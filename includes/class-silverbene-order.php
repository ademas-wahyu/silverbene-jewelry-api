<?php
class Silverbene_Order {
    /**
     * API client.
     *
     * @var Silverbene_API_Client
     */
    private $client;

    /**
     * Constructor.
     *
     * @param Silverbene_API_Client $client API client.
     */
    public function __construct( Silverbene_API_Client $client ) {
        $this->client = $client;
    }

    /**
     * Initialize hooks related to order synchronization.
     */
    public function initialize() {
        add_action( 'woocommerce_order_status_processing', array( $this, 'sync_order_to_silverbene' ) );
        add_action( 'woocommerce_order_status_completed', array( $this, 'sync_order_to_silverbene' ) );
    }

    /**
     * Sync WooCommerce order to Silverbene.
     *
     * @param int $order_id WooCommerce order ID.
     */
    public function sync_order_to_silverbene( $order_id ) {
        if ( empty( $order_id ) || get_post_meta( $order_id, '_silverbene_order_id', true ) ) {
            return;
        }

        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return;
        }

        $payload = $this->build_order_payload( $order );
        if ( empty( $payload ) ) {
            return;
        }

        $response = $this->client->create_order( $payload );

        if ( ! empty( $response['order_id'] ) ) {
            update_post_meta( $order_id, '_silverbene_order_id', sanitize_text_field( $response['order_id'] ) );
            $order->add_order_note( __( 'Pesanan berhasil dikirim ke Silverbene.', 'silverbene-api-integration' ) );
        } else {
            $order->add_order_note( __( 'Gagal mengirim pesanan ke Silverbene. Cek log untuk detail.', 'silverbene-api-integration' ) );
        }
    }

    /**
     * Build order payload for Silverbene API.
     *
     * @param WC_Order $order WooCommerce order instance.
     *
     * @return array
     */
    private function build_order_payload( $order ) {
        if ( ! $order instanceof WC_Order ) {
            return array();
        }

        $items = array();
        foreach ( $order->get_items() as $item_id => $item ) {
            $product = $item->get_product();
            if ( ! $product ) {
                continue;
            }

            $sku = $product->get_sku();
            if ( ! $sku ) {
                $sku = $product->get_meta( '_silverbene_product_id', true );
            }

            $items[] = array(
                'sku'      => $sku,
                'name'     => $item->get_name(),
                'quantity' => $item->get_quantity(),
                'price'    => wc_format_decimal( $order->get_item_total( $item, false ) ),
            );
        }

        if ( empty( $items ) ) {
            return array();
        }

        $shipping = $order->get_address( 'shipping' );
        if ( empty( $shipping['first_name'] ) ) {
            $shipping = $order->get_address( 'billing' );
        }

        $payload = array(
            'order_number' => $order->get_order_number(),
            'currency'     => $order->get_currency(),
            'total'        => wc_format_decimal( $order->get_total() ),
            'shipping'     => array(
                'method'  => $order->get_shipping_method(),
                'total'   => wc_format_decimal( $order->get_shipping_total() ),
                'address' => array(
                    'first_name' => $shipping['first_name'],
                    'last_name'  => $shipping['last_name'],
                    'company'    => $shipping['company'],
                    'address_1'  => $shipping['address_1'],
                    'address_2'  => $shipping['address_2'],
                    'city'       => $shipping['city'],
                    'state'      => $shipping['state'],
                    'postcode'   => $shipping['postcode'],
                    'country'    => $shipping['country'],
                    'phone'      => $order->get_billing_phone(),
                ),
            ),
            'customer'     => array(
                'email'      => $order->get_billing_email(),
                'first_name' => $order->get_billing_first_name(),
                'last_name'  => $order->get_billing_last_name(),
                'phone'      => $order->get_billing_phone(),
            ),
            'items'        => $items,
        );

        return $payload;
    }
}
