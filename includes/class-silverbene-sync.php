<?php
class Silverbene_Sync {
    public function fetch_products() {
        // Mengambil daftar produk dari API Silverbene
        $response = wp_remote_get( 'https://api.silverbene.com/v1/products', array(
            'headers' => array(
                'Authorization' => 'Bearer YOUR_API_TOKEN',
            ),
        ) );

        if ( is_wp_error( $response ) ) {
            return;
        }

        $products = json_decode( wp_remote_retrieve_body( $response ), true );
        foreach ( $products as $product ) {
            // Menambahkan produk ke WooCommerce
            $this->add_product_to_woocommerce( $product );
        }
    }

    private function add_product_to_woocommerce( $product_data ) {
        // Menambahkan produk ke WooCommerce
        $post_id = wp_insert_post( array(
            'post_title'   => $product_data['name'],
            'post_content' => $product_data['description'],
            'post_status'  => 'publish',
            'post_type'    => 'product',
        ) );

        if ( $post_id ) {
            // Menambahkan metadata produk
            update_post_meta( $post_id, '_regular_price', $product_data['price'] );
            update_post_meta( $post_id, '_price', $product_data['price'] );
            update_post_meta( $post_id, '_stock', $product_data['stock'] );
            // Menambahkan kategori produk
            wp_set_object_terms( $post_id, array( 'jewelry' ), 'product_cat' );
        }
    }
}
