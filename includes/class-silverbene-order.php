<?php
class Silverbene_Order {
    public function create_order( $order_data ) {
        // Membuat pesanan di Silverbene
        $response = wp_remote_post( 'https://api.silverbene.com/v1/orders', array(
            'headers' => array(
                'Authorization' => 'Bearer YOUR_API_TOKEN',
            ),
            'body' => json_encode( $order_data ),
        ) );

        if ( is_wp_error( $response ) ) {
            return;
        }

        // Menangani respons dari API
        $order_response = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( isset( $order_response['order_id'] ) ) {
            // Menyimpan ID pesanan di WooCommerce
            update_post_meta( $order_data['order_id'], '_silverbene_order_id', $order_response['order_id'] );
        }
    }
}
