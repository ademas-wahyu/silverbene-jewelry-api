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

        if ( isset( $response['code'] ) && 0 === intval( $response['code'] ) ) {
            $order_data = isset( $response['data'] ) ? $response['data'] : array();
            $remote_id  = isset( $order_data['order_id'] ) ? $order_data['order_id'] : '';

            if ( $remote_id ) {
                update_post_meta( $order_id, '_silverbene_order_id', sanitize_text_field( $remote_id ) );
                $order->add_order_note( __( 'Pesanan berhasil dikirim ke Silverbene.', 'silverbene-api-integration' ) );
                return;
            }
        }

        $order->add_order_note( __( 'Gagal mengirim pesanan ke Silverbene. Cek log untuk detail.', 'silverbene-api-integration' ) );
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

        $options = $this->build_order_options_payload( $order );
        if ( empty( $options ) ) {
            return array();
        }

        $shipping_address = $this->build_shipping_address_payload( $order );
        if ( empty( $shipping_address ) || empty( $shipping_address['country_id'] ) ) {
            return array();
        }

        $shipping_codes = $this->determine_shipping_method_codes( $order, $shipping_address['country_id'] );

        if ( empty( $shipping_codes['shipping_carrier_code'] ) || empty( $shipping_codes['shipping_method_code'] ) ) {
            $this->log_order_error( 'Tidak ada metode pengiriman yang valid untuk negara tujuan.', array(
                'order_id'    => $order->get_id(),
                'country_id'  => $shipping_address['country_id'],
            ) );
            return array();
        }

        $token = $this->client->get_setting( 'api_key', '' );
        if ( empty( $token ) ) {
            $this->log_order_error( 'Token API tidak tersedia saat membangun payload pesanan.', array(
                'order_id' => $order->get_id(),
            ) );
            return array();
        }

        return array(
            'token'                 => $token,
            'options'               => $options,
            'shipping_address'      => $shipping_address,
            'shipping_carrier_code' => $shipping_codes['shipping_carrier_code'],
            'shipping_method_code'  => $shipping_codes['shipping_method_code'],
        );
    }

    /**
     * Build order options payload required by Silverbene.
     *
     * @param WC_Order $order Order instance.
     *
     * @return array
     */
    private function build_order_options_payload( $order ) {
        $options = array();

        foreach ( $order->get_items() as $item ) {
            $quantity = intval( $item->get_quantity() );
            if ( $quantity <= 0 ) {
                continue;
            }

            $option_id = $item->get_meta( '_silverbene_option_id', true );

            $product = $item->get_product();
            if ( ! $option_id && $product ) {
                $option_id = $product->get_meta( '_silverbene_option_id', true );

                if ( ! $option_id ) {
                    $option_id = $product->get_meta( '_silverbene_product_id', true );
                }

                if ( ! $option_id ) {
                    $option_id = $product->get_sku();
                }
            }

            if ( ! $option_id ) {
                continue;
            }

            $options[] = array(
                'option_id' => strval( $option_id ),
                'qty'       => $quantity,
            );
        }

        return $options;
    }

    /**
     * Build shipping address payload that matches Silverbene requirements.
     *
     * @param WC_Order $order Order instance.
     *
     * @return array
     */
    private function build_shipping_address_payload( $order ) {
        $shipping = $order->get_address( 'shipping' );
        if ( empty( $shipping['first_name'] ) ) {
            $shipping = $order->get_address( 'billing' );
        }

        if ( empty( $shipping ) ) {
            return array();
        }

        $country = isset( $shipping['country'] ) ? strtoupper( $shipping['country'] ) : '';
        if ( empty( $country ) ) {
            $country = strtoupper( $order->get_billing_country() );
        }

        if ( empty( $country ) ) {
            return array();
        }

        $region_data = $this->resolve_region_data( $country, isset( $shipping['state'] ) ? $shipping['state'] : '' );

        return array(
            'city'       => isset( $shipping['city'] ) ? $shipping['city'] : '',
            'country_id' => $country,
            'email'      => $order->get_billing_email(),
            'firstname'  => isset( $shipping['first_name'] ) ? $shipping['first_name'] : '',
            'lastname'   => isset( $shipping['last_name'] ) ? $shipping['last_name'] : '',
            'postcode'   => isset( $shipping['postcode'] ) ? $shipping['postcode'] : '',
            'region'     => $region_data['region'],
            'region_code'=> $region_data['region_code'],
            'region_id'  => $region_data['region_id'],
            'street'     => $this->format_street_address( $shipping ),
            'telephone'  => $order->get_billing_phone(),
        );
    }

    /**
     * Attempt to determine the carrier and method code for the order shipment.
     *
     * @param WC_Order $order      Order instance.
     * @param string   $country_id Destination country code.
     *
     * @return array
     */
    private function determine_shipping_method_codes( $order, $country_id ) {
        $available_methods = $this->client->get_shipping_methods( $country_id );

        if ( empty( $available_methods ) ) {
            $this->log_order_error( 'Tidak ada metode pengiriman dari API.', array(
                'order_id'   => $order->get_id(),
                'country_id' => $country_id,
            ) );

            return array(
                'shipping_carrier_code' => '',
                'shipping_method_code'  => '',
            );
        }

        $selected_shipping_item = null;
        $shipping_items          = $order->get_items( 'shipping' );
        if ( ! empty( $shipping_items ) ) {
            $selected_shipping_item = reset( $shipping_items );
        }

        $selected_method_id    = $selected_shipping_item ? $selected_shipping_item->get_method_id() : '';
        $selected_method_title = $selected_shipping_item ? $selected_shipping_item->get_name() : '';

        $matched = $this->match_shipping_method( $available_methods, $selected_method_id, $selected_method_title );

        if ( empty( $matched ) ) {
            $matched = $this->get_first_shipping_method( $available_methods );
        }

        $codes = array(
            'shipping_carrier_code' => isset( $matched['carrier_code'] ) ? $matched['carrier_code'] : '',
            'shipping_method_code'  => isset( $matched['method_code'] ) ? $matched['method_code'] : '',
        );

        /**
         * Allow customization of shipping codes sent to Silverbene.
         *
         * @param array    $codes            Carrier and method codes.
         * @param WC_Order $order            The WooCommerce order.
         * @param array    $available_methods Available methods returned by the API.
         */
        $codes = apply_filters( 'silverbene_shipping_method_codes', $codes, $order, $available_methods );

        return $codes;
    }

    /**
     * Match a shipping method from the API response with WooCommerce selection.
     *
     * @param array  $methods API response data.
     * @param string $selected_id Selected WooCommerce method ID.
     * @param string $selected_title Selected WooCommerce method title.
     *
     * @return array
     */
    private function match_shipping_method( $methods, $selected_id, $selected_title ) {
        $selected_id    = strtolower( strval( $selected_id ) );
        $selected_title = strtolower( strval( $selected_title ) );

        if ( '' === $selected_id && '' === $selected_title ) {
            return array();
        }

        $flattened = $this->flatten_shipping_methods( $methods );

        foreach ( $flattened as $method ) {
            $candidates = array(
                isset( $method['method_code'] ) ? strtolower( strval( $method['method_code'] ) ) : '',
                isset( $method['carrier_code'] ) ? strtolower( strval( $method['carrier_code'] ) ) : '',
                isset( $method['method_title'] ) ? strtolower( strval( $method['method_title'] ) ) : '',
            );

            foreach ( $candidates as $candidate ) {
                if ( '' === $candidate ) {
                    continue;
                }

                if ( $candidate === $selected_id || $candidate === $selected_title ) {
                    return $method;
                }
            }
        }

        return array();
    }

    /**
     * Retrieve the first available shipping method from API data.
     *
     * @param array $methods API response data.
     *
     * @return array
     */
    private function get_first_shipping_method( $methods ) {
        $flattened = $this->flatten_shipping_methods( $methods );

        return isset( $flattened[0] ) ? $flattened[0] : array();
    }

    /**
     * Flatten nested shipping method data into a simple list of carrier/method codes.
     *
     * @param array $methods API response data.
     * @param array $parent  Parent carrier context.
     *
     * @return array
     */
    private function flatten_shipping_methods( $methods, $parent = array() ) {
        $flattened = array();

        foreach ( $methods as $method ) {
            if ( ! is_array( $method ) ) {
                continue;
            }

            $current = $method;

            if ( isset( $parent['carrier_code'] ) && ! isset( $current['carrier_code'] ) ) {
                $current['carrier_code'] = $parent['carrier_code'];
            }

            if ( isset( $parent['carrier_title'] ) && ! isset( $current['carrier_title'] ) ) {
                $current['carrier_title'] = $parent['carrier_title'];
            }

            if ( isset( $current['shipping_methods'] ) && is_array( $current['shipping_methods'] ) ) {
                $flattened = array_merge( $flattened, $this->flatten_shipping_methods( $current['shipping_methods'], $current ) );
                continue;
            }

            if ( isset( $current['methods'] ) && is_array( $current['methods'] ) ) {
                $flattened = array_merge( $flattened, $this->flatten_shipping_methods( $current['methods'], $current ) );
                continue;
            }

            if ( isset( $current['carrier_code'], $current['method_code'] ) ) {
                $flattened[] = array(
                    'carrier_code' => $current['carrier_code'],
                    'method_code'  => $current['method_code'],
                    'carrier_title' => isset( $current['carrier_title'] ) ? $current['carrier_title'] : '',
                    'method_title'  => isset( $current['method_title'] ) ? $current['method_title'] : ( isset( $current['title'] ) ? $current['title'] : '' ),
                );
            }
        }

        return $flattened;
    }

    /**
     * Resolve region data (name, code, ID) from WooCommerce states list.
     *
     * @param string $country Country code.
     * @param string $state   State/region code.
     *
     * @return array
     */
    private function resolve_region_data( $country, $state ) {
        $country = strtoupper( strval( $country ) );
        $state   = strtoupper( strval( $state ) );

        $region_name = $state;
        $region_code = $state ? $state : null;
        $region_id   = null;

        if ( class_exists( 'WC_Countries' ) ) {
            $countries = new WC_Countries();
            $states    = $countries->get_states( $country );

            if ( $state && isset( $states[ $state ] ) ) {
                $region_name = $states[ $state ];
            }
        }

        return array(
            'region'      => $region_name ? $region_name : $region_code,
            'region_code' => $region_code,
            'region_id'   => $region_id,
        );
    }

    /**
     * Format street address for Silverbene payload.
     *
     * @param array $address Address array from WooCommerce.
     *
     * @return string
     */
    private function format_street_address( $address ) {
        $parts = array();

        if ( ! empty( $address['address_1'] ) ) {
            $parts[] = $address['address_1'];
        }

        if ( ! empty( $address['address_2'] ) ) {
            $parts[] = $address['address_2'];
        }

        return implode( ' ', array_filter( array_map( 'trim', $parts ) ) );
    }

    /**
     * Log order related errors.
     *
     * @param string $message Error message.
     * @param array  $context Additional context.
     */
    private function log_order_error( $message, $context = array() ) {
        if ( function_exists( 'wc_get_logger' ) ) {
            $logger = wc_get_logger();
            $logger->error( $message . ' - ' . wp_json_encode( $context ), array( 'source' => 'silverbene-order' ) );
        }
    }
}
