<?php
class Silverbene_Sync {
    /**
     * API client instance.
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
     * Fetch and sync products from Silverbene.
     *
     * @param bool $force Whether to force sync ignoring settings.
     */
    public function sync_products( $force = false ) {
        if ( ! class_exists( 'WooCommerce' ) ) {
            return;
        }

        $settings = $this->client->get_settings();
        if ( ! $force && empty( $settings['sync_enabled'] ) ) {
            return;
        }

        $logger  = function_exists( 'wc_get_logger' ) ? wc_get_logger() : null;
        $context = array( 'source' => 'silverbene-api-sync' );

        $page     = 1;
        $per_page = 50;
        $imported = 0;

        do {
            /**
             * Note for Developer: The Silverbene API may time out on very large date ranges.
             * For the initial import, you may need to sync in smaller batches (e.g., 3-6 months at a time)
             * by adjusting the 'start_date' below.
             * For regular daily/hourly syncs, the default of 30 days is safe.
             */
            $start_date = wp_date( 'Y-m-d', strtotime( '-1 year' ) );

            $products = $this->client->get_products(
                array(
                    'page'       => $page,
                    'per_page'   => $per_page,
                    'start_date' => $start_date,
                    'end_date'   => wp_date( 'Y-m-d' ),
                )
            );

            if ( empty( $products ) ) {
                break;
            }

            foreach ( $products as $product_data ) {
                $product_id = $this->upsert_product( $product_data, $settings );
                if ( $product_id ) {
                    $imported++;
                }
            }

            $page++;
        } while ( count( $products ) >= $per_page );

        if ( $logger ) {
            $logger->info(
                sprintf( 'Sinkronisasi produk selesai. Total produk diperbarui: %d', $imported ),
                $context
            );
        }
    }

    /**
     * Create or update a WooCommerce product based on Silverbene payload.
     *
     * @param array $product_data Product data from API.
     * @param array $settings     Plugin settings.
     *
     * @return int|false Product ID on success, false on failure.
     */
    private function upsert_product( $product_data, $settings ) {
        if ( empty( $product_data ) || ! is_array( $product_data ) ) {
            return false;
        }

        $sku = $this->extract_value( $product_data, array( 'sku', 'SKU', 'product_sku', 'id' ) );
        if ( empty( $sku ) ) {
            return false;
        }

        $product_id = wc_get_product_id_by_sku( $sku );
        $is_new     = false;

        if ( $product_id ) {
            $product = wc_get_product( $product_id );
        } else {
            $product = new WC_Product_Simple();
            $product->set_sku( $sku );
            $is_new = true;
        }

        if ( ! $product ) {
            return false;
        }

        $name        = $this->extract_value( $product_data, array( 'name', 'title', 'product_name', 'goods_name', 'product_title' ) );
        $description = $this->extract_value( $product_data, array( 'description', 'desc', 'detail', 'content', 'product_description', 'product_detail', 'product_content', 'goods_desc' ) );
        $short_desc  = $this->extract_value( $product_data, array( 'short_description', 'short_desc', 'summary', 'brief' ) );
        $price       = $this->extract_value( $product_data, array( 'price', 'regular_price', 'selling_price', 'sale_price', 'shop_price', 'market_price' ), 0 );

        // Fallback to option price if top-level price is not available
        if ( empty( $price ) && ! empty( $product_data['option'][0]['price'] ) ) {
            $price = $product_data['option'][0]['price'];
        }

        $stock       = $this->extract_value( $product_data, array( 'stock', 'stock_quantity', 'quantity', 'inventory', 'stock_qty', 'qty', 'real_qty', 'option_qty' ), null );
        $weight      = $this->extract_value( $product_data, array( 'weight' ), null );
        $length      = $this->extract_value( $product_data, array( 'length' ), null );
        $width       = $this->extract_value( $product_data, array( 'width' ), null );
        $height      = $this->extract_value( $product_data, array( 'height' ), null );

        if ( $name ) {
            $product->set_name( wp_strip_all_tags( $name ) );
        }

        if ( $description ) {
            $product->set_description( wp_kses_post( $description ) );
        }

        if ( $short_desc ) {
            $product->set_short_description( wp_kses_post( $short_desc ) );
        }

        if ( null !== $stock ) {
            $product->set_manage_stock( true );
            $product->set_stock_quantity( intval( $stock ) );
            $product->set_stock_status( intval( $stock ) > 0 ? 'instock' : 'outofstock' );
        }

        if ( null !== $weight ) {
            $product->set_weight( wc_format_decimal( $weight ) );
        }

        if ( null !== $length || null !== $width || null !== $height ) {
            $product->set_length( wc_format_decimal( $length ) );
            $product->set_width( wc_format_decimal( $width ) );
            $product->set_height( wc_format_decimal( $height ) );
        }

        $price = $this->apply_markup( $price, $settings );
        $product->set_regular_price( wc_format_decimal( $price ) );
        $product->set_price( wc_format_decimal( $price ) );

        $status = $this->extract_value( $product_data, array( 'status', 'product_status' ), 'publish' );
        $product->set_status( in_array( $status, array( 'draft', 'pending', 'private', 'publish' ), true ) ? $status : 'publish' );

        $product_id = $product->save();

        if ( ! $product_id ) {
            return false;
        }

        $this->assign_categories_and_tags( $product_id, $product_data, $settings );
        $this->assign_images( $product_id, $product_data, $is_new );
        $this->assign_attributes( $product_id, $product_data );

        update_post_meta( $product_id, '_silverbene_product_id', $this->extract_value( $product_data, array( 'id', 'product_id' ), $sku ) );

        return $product_id;
    }

    /**
     * Apply markup rules defined in settings.
     *
     * @param float $base_price Base price from API.
     * @param array $settings   Plugin settings.
     *
     * @return float
     */
    private function apply_markup( $base_price, $settings ) {
        $base_price = max( floatval( $base_price ), 0 );
        $shipping_fee = isset( $settings['pre_markup_shipping_fee'] ) ? max( 0, floatval( $settings['pre_markup_shipping_fee'] ) ) : 0;
        $base_price += $shipping_fee;

        if ( $base_price <= 0 ) {
            return 0;
        }

        $type  = isset( $settings['price_markup_type'] ) ? $settings['price_markup_type'] : 'none';
        $value = isset( $settings['price_markup_value'] ) ? floatval( $settings['price_markup_value'] ) : 0;

        if ( 'percentage' === $type ) {
            $base_price += $base_price * ( $value / 100 );
        } elseif ( 'fixed' === $type ) {
            $base_price += $value;
        }

        return max( $base_price, 0 );
    }

    /**
     * Assign categories and tags to the product.
     *
     * @param int   $product_id   Product ID.
     * @param array $product_data API data.
     * @param array $settings     Plugin settings.
     */
    private function assign_categories_and_tags( $product_id, $product_data, $settings ) {
        $categories = $this->extract_value( $product_data, array( 'categories', 'category', 'product_category' ), array() );
        $tags       = $this->extract_value( $product_data, array( 'tags', 'tag_list' ), array() );

        if ( ! is_array( $categories ) ) {
            $categories = array_filter( array_map( 'trim', explode( ',', strval( $categories ) ) ) );
        }

        if ( empty( $categories ) && ! empty( $settings['default_category'] ) ) {
            $categories = array( $settings['default_category'] );
        }

        if ( is_array( $categories ) && ! empty( $categories ) ) {
            $category_ids = array();
            foreach ( $categories as $category_name ) {
                if ( empty( $category_name ) ) {
                    continue;
                }

                $term = term_exists( $category_name, 'product_cat' );
                if ( ! $term ) {
                    $term = wp_insert_term( $category_name, 'product_cat' );
                }

                if ( ! is_wp_error( $term ) ) {
                    $category_ids[] = intval( is_array( $term ) ? $term['term_id'] : $term );
                }
            }

            if ( ! empty( $category_ids ) ) {
                wp_set_object_terms( $product_id, $category_ids, 'product_cat' );
            }
        }

        if ( ! is_array( $tags ) ) {
            $tags = array_filter( array_map( 'trim', explode( ',', strval( $tags ) ) ) );
        }

        if ( is_array( $tags ) && ! empty( $tags ) ) {
            wp_set_object_terms( $product_id, $tags, 'product_tag' );
        }
    }

    /**
     * Assign WooCommerce product attributes from API data.
     *
     * @param int   $product_id   Product ID.
     * @param array $product_data API data.
     */
    private function assign_attributes( $product_id, $product_data ) {
        if ( ! function_exists( 'wc_get_attribute_taxonomies' ) ) {
            return;
        }

        $attributes = $this->extract_value( $product_data, array( 'attributes', 'attribute_list', 'product_attributes' ), array() );
        if ( empty( $attributes ) || ! is_array( $attributes ) ) {
            return;
        }

        $product_attributes = array();

        foreach ( $attributes as $key => $value ) {
            if ( is_array( $value ) ) {
                $value = implode( ', ', $value );
            }

            $attribute_key = sanitize_title( $key );
            $taxonomy_name = 'pa_' . $attribute_key;

            if ( taxonomy_exists( $taxonomy_name ) ) {
                // For global attributes.
                $term_names = array_map( 'trim', explode( ',', $value ) );
                $term_ids   = array();

                foreach ( $term_names as $term_name ) {
                    if ( empty( $term_name ) ) {
                        continue;
                    }

                    $term = term_exists( $term_name, $taxonomy_name );
                    if ( ! $term ) {
                        $term = wp_insert_term( $term_name, $taxonomy_name );
                    }

                    if ( ! is_wp_error( $term ) ) {
                        $term_ids[] = intval( is_array( $term ) ? $term['term_id'] : $term );
                    }
                }

                if ( ! empty( $term_ids ) ) {
                    wp_set_object_terms( $product_id, $term_ids, $taxonomy_name );
                }

                $product_attributes[ $taxonomy_name ] = array(
                    'name'         => $taxonomy_name,
                    'value'        => '',
                    'is_visible'   => 1,
                    'is_taxonomy'  => 1,
                    'is_variation' => 0,
                );
            } else {
                // Use custom attribute.
                $product_attributes[ $attribute_key ] = array(
                    'name'         => wc_attribute_label( $key ),
                    'value'        => $value,
                    'is_visible'   => 1,
                    'is_taxonomy'  => 0,
                    'is_variation' => 0,
                );
            }
        }

        if ( ! empty( $product_attributes ) ) {
            update_post_meta( $product_id, '_product_attributes', $product_attributes );
        }
    }

    /**
     * Download and set product images.
     *
     * @param int  $product_id Product ID.
     * @param array $product_data API data.
     * @param bool $is_new Whether the product is newly created.
     */
    private function assign_images( $product_id, $product_data, $is_new ) {
        $images = $this->extract_value( $product_data, array( 'images', 'product_images', 'gallery', 'image_urls', 'image_list', 'img_urls', 'pictures', 'photos', 'thumb' ), array() );

        if ( empty( $images ) ) {
            return;
        }

        if ( ! is_array( $images ) ) {
            $images = array( $images );
        }

        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $image_ids = array();
        foreach ( $images as $index => $image_url ) {
            if ( empty( $image_url ) || ! filter_var( $image_url, FILTER_VALIDATE_URL ) ) {
                continue;
            }

            $attachment_id = $this->sideload_image( $image_url, $product_id, $index );
            if ( $attachment_id ) {
                if ( 0 === $index ) {
                    set_post_thumbnail( $product_id, $attachment_id );
                } else {
                    $image_ids[] = $attachment_id;
                }
            }
        }

        if ( ! empty( $image_ids ) ) {
            update_post_meta( $product_id, '_product_image_gallery', implode( ',', $image_ids ) );
        } elseif ( $is_new ) {
            delete_post_meta( $product_id, '_product_image_gallery' );
        }
    }

    /**
     * Helper to sideload image to WordPress media library.
     *
     * @param string $image_url Image URL.
     * @param int    $product_id Product ID.
     * @param int    $index Image index.
     *
     * @return int|false Attachment ID.
     */
    private function sideload_image( $image_url, $product_id, $index ) {
        $tmp = download_url( $image_url );

        if ( is_wp_error( $tmp ) ) {
            return false;
        }

        $file_array = array(
            'name'     => basename( parse_url( $image_url, PHP_URL_PATH ) ),
            'tmp_name' => $tmp,
        );

        $id = media_handle_sideload( $file_array, $product_id );

        if ( is_wp_error( $id ) ) {
            @unlink( $tmp );
            return false;
        }

        return $id;
    }

    /**
     * Extract value from data array using possible keys.
     *
     * @param array        $data Source array.
     * @param array        $keys Possible keys.
     * @param string|float $default Default value.
     *
     * @return mixed
     */
    private function extract_value( $data, $keys, $default = '' ) {
        foreach ( $keys as $key ) {
            if ( isset( $data[ $key ] ) ) {
                return $data[ $key ];
            }
        }

        return $default;
    }
}
