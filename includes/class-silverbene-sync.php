<?php
class Silverbene_Sync
{
    private const COLOR_ATTRIBUTE_NAME = "Color";

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
    public function __construct(Silverbene_API_Client $client)
    {
        $this->client = $client;
    }

    /**
     * Fetch and sync products from Silverbene.
     *
     * @param bool $force Whether to force sync ignoring settings.
     */
    public function sync_products($force = false)
    {
        if (!class_exists("WooCommerce")) {
            return;
        }

        $settings = $this->client->get_settings();
        if (!$force && empty($settings["sync_enabled"])) {
            return;
        }

        $logger = function_exists("wc_get_logger") ? wc_get_logger() : null;
        $context = ["source" => "silverbene-api-sync"];

        $page = 1;
        $per_page = 50;
        $imported = 0;

        do {
            /**
             * Note for Developer: The Silverbene API may time out on very large date ranges.
             * For the initial import, you may need to sync in smaller batches (e.g., 3-6 months at a time)
             * by adjusting the 'start_date' below.
             * For regular daily/hourly syncs, the default of 30 days is safe.
             */
            // $start_date = wp_date( 'Y-m-d', strtotime( '-1 year' ) );

            $products = $this->client->get_products([
                "page" => $page,
                "per_page" => $per_page,
                "is_really_stock" => 1,
                "start_date" => "1970-01-01",
                "end_date" => wp_date("Y-m-d"),
            ]);

            if (empty($products)) {
                break;
            }

            foreach ($products as $product_data) {
                $total_stock = $this->get_total_stock($product_data);

                if ($total_stock <= 0) {
                    $sku = $this->extract_value(
                        $product_data,
                        [
                            "sku",
                            "SKU",
                            "product_sku",
                            "id",
                        ],
                        "",
                    );

                    if (!empty($sku)) {
                        $existing_product_id = wc_get_product_id_by_sku($sku);

                        if ($existing_product_id) {
                            if ($logger) {
                                $logger->info(
                                    sprintf(
                                        "Menghapus produk dengan SKU %s karena stok habis.",
                                        $sku,
                                    ),
                                    $context,
                                );
                            }

                            if (function_exists("wc_delete_product")) {
                                wc_delete_product($existing_product_id, true);
                            } else {
                                wp_trash_post($existing_product_id);
                            }
                        }
                    }

                    continue;
                }

                $product_id = $this->upsert_product(
                    $product_data,
                    $settings,
                    $total_stock,
                );
                if ($product_id) {
                    $imported++;
                }
            }

            $page++;
        } while (count($products) >= $per_page);

        if ($logger) {
            $logger->info(
                sprintf(
                    "Sinkronisasi produk selesai. Total produk diperbarui: %d",
                    $imported,
                ),
                $context,
            );
        }
    }

    /**
     * Create or update a WooCommerce product based on Silverbene payload.
     *
     * @param array $product_data Product data from API.
     * @param array $settings     Plugin settings.
     * @param int|null $total_stock Pre-calculated total stock value.
     *
     * @return int|false Product ID on success, false on failure.
     */
    private function upsert_product($product_data, $settings, $total_stock = null)
    {
        if (empty($product_data) || !is_array($product_data)) {
            return false;
        }

        if (null === $total_stock) {
            $total_stock = $this->get_total_stock($product_data);
        }

        if ($total_stock <= 0) {
            return false;
        }

        $sku = $this->extract_value($product_data, [
            "sku",
            "SKU",
            "product_sku",
            "id",
        ]);
        if (empty($sku)) {
            return false;
        }

        $options = $this->get_product_options($product_data);

        $price = $this->extract_value(
            $product_data,
            [
                "price",
                "regular_price",
                "selling_price",
                "sale_price",
                "shop_price",
                "market_price",
            ],
            0,
        );

        if (empty($price) && !empty($options[0]["price"])) {
            $price = $options[0]["price"];
        }

        $color_variations = $this->prepare_color_variations(
            $product_data,
            $settings,
            $price,
        );

        $unique_color_values = array_unique(
            array_map(
                function ($variation) {
                    return strtolower($variation["color"]);
                },
                $color_variations,
            ),
        );
        $has_color_variations = count($unique_color_values) > 1;
        $target_type = $has_color_variations ? "variable" : "simple";

        $product_id = wc_get_product_id_by_sku($sku);
        $is_new = false;

        if ($product_id) {
            $product = wc_get_product($product_id);

            if ($product && $product->get_type() !== $target_type) {
                if ("simple" === $target_type) {
                    $this->remove_existing_variations($product_id);
                    wp_set_object_terms($product_id, "simple", "product_type");
                    $product = new WC_Product_Simple($product_id);
                } else {
                    wp_set_object_terms($product_id, "variable", "product_type");
                    $product = new WC_Product_Variable($product_id);
                }
            }
        } else {
            if ("variable" === $target_type) {
                $product = new WC_Product_Variable();
            } else {
                $product = new WC_Product_Simple();
            }

            $product->set_sku($sku);
            $is_new = true;
        }

        if (!$product) {
            return false;
        }

        $name = $this->extract_value($product_data, [
            "name",
            "title",
            "product_name",
            "goods_name",
            "product_title",
        ]);
        $description = $this->extract_value($product_data, [
            "description",
            "desc",
            "detail",
            "content",
            "product_description",
            "product_detail",
            "product_content",
            "goods_desc",
        ]);
        $short_desc = $this->extract_value($product_data, [
            "short_description",
            "short_desc",
            "summary",
            "brief",
        ]);
        $stock = $this->extract_value(
            $product_data,
            [
                "stock",
                "stock_quantity",
                "quantity",
                "inventory",
                "stock_qty",
                "qty",
                "real_qty",
                "option_qty",
            ],
            null,
        );
        $weight = $this->extract_value($product_data, ["weight"], null);
        $length = $this->extract_value($product_data, ["length"], null);
        $width = $this->extract_value($product_data, ["width"], null);
        $height = $this->extract_value($product_data, ["height"], null);

        if ($name) {
            $product->set_name(wp_strip_all_tags($name));
        }

        if ($description) {
            $product->set_description(wp_kses_post($description));
        }

        if ($short_desc) {
            $product->set_short_description(wp_kses_post($short_desc));
        }

        if ("variable" === $target_type) {
            $product->set_manage_stock(false);
            $product->set_stock_status(
                $total_stock > 0 ? "instock" : "outofstock",
            );
        } elseif (null !== $stock) {
            $product->set_manage_stock(true);
            $product->set_stock_quantity(intval($stock));
            $product->set_stock_status(
                intval($stock) > 0 ? "instock" : "outofstock",
            );
        } elseif ($total_stock > 0) {
            $product->set_manage_stock(true);
            $product->set_stock_quantity(intval($total_stock));
            $product->set_stock_status("instock");
        }

        if (null !== $weight) {
            $product->set_weight(wc_format_decimal($weight));
        }

        if (null !== $length || null !== $width || null !== $height) {
            $product->set_length(wc_format_decimal($length));
            $product->set_width(wc_format_decimal($width));
            $product->set_height(wc_format_decimal($height));
        }

        if ("simple" === $target_type) {
            $price = $this->apply_markup($price, $settings);
            $product->set_regular_price(wc_format_decimal($price));
            $product->set_price(wc_format_decimal($price));
        }

        $status = $this->extract_value(
            $product_data,
            ["status", "product_status"],
            "publish",
        );
        $product->set_status(
            in_array($status, ["draft", "pending", "private", "publish"], true)
                ? $status
                : "publish",
        );

        $product_id = $product->save();

        if (!$product_id) {
            return false;
        }

        $this->assign_categories_and_tags(
            $product_id,
            $product_data,
            $settings,
        );
        $this->assign_images($product_id, $product_data, $is_new);

        if ($has_color_variations) {
            wp_set_object_terms($product_id, "variable", "product_type");
            $this->sync_color_variations($product_id, $color_variations, $total_stock);
        } else {
            $this->remove_existing_variations($product_id);
        }

        $this->assign_attributes($product_id, $product_data);

        update_post_meta(
            $product_id,
            "_silverbene_product_id",
            $this->extract_value($product_data, ["id", "product_id"], $sku),
        );

        return $product_id;
    }

    /**
     * Apply markup rules defined in settings.
     *
     * Calculation steps:
     * 1. Sanitize the base price from the API and clamp it to zero or greater.
     * 2. Add an optional pre-markup shipping fee (also clamped to zero or greater).
     * 3. Apply the configured markup type (percentage or fixed) to the subtotal.
     *
     * @param float $base_price Base price from API.
     * @param array $settings   Plugin settings.
     *
     * @return float
     */
    private function apply_markup($base_price, $settings)
    {
        $base_price = max(floatval($base_price), 0);
        $shipping_fee = isset($settings["pre_markup_shipping_fee"])
            ? max(0, floatval($settings["pre_markup_shipping_fee"]))
            : 0;
        $subtotal = $base_price + $shipping_fee;

        if ($subtotal <= 0) {
            return 0;
        }

        $type = isset($settings["price_markup_type"])
            ? $settings["price_markup_type"]
            : "none";

        $default_markup = isset($settings["price_markup_value"])
            ? floatval($settings["price_markup_value"])
            : 0;

        $below_markup = null;
        if (isset($settings["price_markup_value_below_100"])) {
            $raw_value = $settings["price_markup_value_below_100"];
            if ("" !== trim((string) $raw_value)) {
                $below_markup = floatval($raw_value);
            }
        }

        $above_markup = null;
        if (isset($settings["price_markup_value_above_100"])) {
            $raw_value = $settings["price_markup_value_above_100"];
            if ("" !== trim((string) $raw_value)) {
                $above_markup = floatval($raw_value);
            }
        }

        $markup_value = $default_markup;
        if ($base_price < 100) {
            $markup_value = null !== $below_markup ? $below_markup : $default_markup;
        } else {
            $markup_value = null !== $above_markup ? $above_markup : $default_markup;
        }

        if ("percentage" === $type) {
            $subtotal += $subtotal * ($markup_value / 100);
        } elseif ("fixed" === $type) {
            $subtotal += $markup_value;
        }

        return max($subtotal, 0);
    }

    /**
     * Assign categories and tags to the product.
     *
     * @param int   $product_id   Product ID.
     * @param array $product_data API data.
     * @param array $settings     Plugin settings.
     */
    private function assign_categories_and_tags(
        $product_id,
        $product_data,
        $settings,
    ) {
        $categories_from_api = $this->extract_value(
            $product_data,
            ["categories", "category", "product_category"],
            [],
        );
        $tags = $this->extract_value($product_data, ["tags", "tag_list"], []);

        $matched_category = null;
        $product_title = $this->extract_value(
            $product_data,
            [
                "name",
                "title",
                "product_name",
                "goods_name",
                "product_title",
            ],
            "",
        );

        if (!empty($product_title)) {
            $keyword_category_map = [
                "EARRINGS" => ["earring", "earrings"],
                "NECKLACES" => ["necklace", "necklaces"],
                "RINGS" => ["ring", "rings"],
                "BRACELETS" => ["bracelet", "bracelets"],
                "FINDINGS" => ["finding", "findings"],
                "PEARL" => ["pearl", "pearls"],
                "CS/MOISSANITE" => ["cs", "moissanite"],
                "CHAINS" => ["chain", "chains"],
                "BOX" => ["box", "boxes"],
                "MEN'S/MEN" => ["men's", "mens", "men"],
                "GIFT WRAP" => ["gift wrap", "giftwrap", "gift-wrap"],
            ];

            foreach ($keyword_category_map as $category_name => $keywords) {
                foreach ($keywords as $keyword) {
                    if ('' === $keyword) {
                        continue;
                    }

                    if (false !== stripos($product_title, $keyword)) {
                        $matched_category = $category_name;
                        break 2;
                    }
                }
            }
        }

        if ($matched_category) {
            $categories = [$matched_category];
        } else {
            $categories = $categories_from_api;

            if (!is_array($categories)) {
                $categories = array_filter(
                    array_map("trim", explode(",", strval($categories))),
                );
            }

            if (empty($categories) && !empty($settings["default_category"])) {
                $categories = [$settings["default_category"]];
            }
        }

        if (is_array($categories) && !empty($categories)) {
            $category_ids = [];
            foreach ($categories as $category_name) {
                if (empty($category_name)) {
                    continue;
                }

                $term = term_exists($category_name, "product_cat");
                if (!$term) {
                    $term = wp_insert_term($category_name, "product_cat");
                }

                if (!is_wp_error($term)) {
                    $category_ids[] = intval(
                        is_array($term) ? $term["term_id"] : $term,
                    );
                }
            }

            if (!empty($category_ids)) {
                wp_set_object_terms($product_id, $category_ids, "product_cat");
            }
        }

        if (!is_array($tags)) {
            $tags = array_filter(
                array_map("trim", explode(",", strval($tags))),
            );
        }

        if (is_array($tags) && !empty($tags)) {
            wp_set_object_terms($product_id, $tags, "product_tag");
        }
    }

    /**
     * Assign WooCommerce product attributes from API data.
     *
     * @param int   $product_id   Product ID.
     * @param array $product_data API data.
     */
    private function assign_attributes($product_id, $product_data)
    {
        if (!function_exists("wc_get_attribute_taxonomies")) {
            return;
        }

        $attributes = $this->extract_value(
            $product_data,
            ["attributes", "attribute_list", "product_attributes"],
            [],
        );
        if (empty($attributes) || !is_array($attributes)) {
            return;
        }

        $product_attributes = [];
        $existing_attributes = get_post_meta(
            $product_id,
            "_product_attributes",
            true,
        );
        if (!is_array($existing_attributes)) {
            $existing_attributes = [];
        }

        foreach ($attributes as $key => $value) {
            if (is_array($value)) {
                $value = implode(", ", $value);
            }

            $attribute_key = sanitize_title($key);
            $taxonomy_name = "pa_" . $attribute_key;

            if (taxonomy_exists($taxonomy_name)) {
                // For global attributes.
                $term_names = array_map("trim", explode(",", $value));
                $term_ids = [];

                foreach ($term_names as $term_name) {
                    if (empty($term_name)) {
                        continue;
                    }

                    $term = term_exists($term_name, $taxonomy_name);
                    if (!$term) {
                        $term = wp_insert_term($term_name, $taxonomy_name);
                    }

                    if (!is_wp_error($term)) {
                        $term_ids[] = intval(
                            is_array($term) ? $term["term_id"] : $term,
                        );
                    }
                }

                if (!empty($term_ids)) {
                    wp_set_object_terms($product_id, $term_ids, $taxonomy_name);
                }

                $product_attributes[$taxonomy_name] = [
                    "name" => $taxonomy_name,
                    "value" => "",
                    "is_visible" => 1,
                    "is_taxonomy" => 1,
                    "is_variation" => isset($existing_attributes[$taxonomy_name]["is_variation"])
                        ? intval($existing_attributes[$taxonomy_name]["is_variation"])
                        : 0,
                ];
            } else {
                // Use custom attribute.
                $product_attributes[$attribute_key] = [
                    "name" => wc_attribute_label($key),
                    "value" => $value,
                    "is_visible" => 1,
                    "is_taxonomy" => 0,
                    "is_variation" => isset($existing_attributes[$attribute_key]["is_variation"])
                        ? intval($existing_attributes[$attribute_key]["is_variation"])
                        : 0,
                ];
            }
        }

        if (!empty($product_attributes)) {
            $attributes_to_save = $existing_attributes;

            foreach ($product_attributes as $key => $attribute) {
                $attributes_to_save[$key] = $attribute;
            }

            update_post_meta(
                $product_id,
                "_product_attributes",
                $attributes_to_save,
            );
        }
    }

    /**
     * Create or update product variations based on available color data.
     *
     * @param int   $product_id        Parent product ID.
     * @param array $color_variations  Prepared color variation payloads.
     * @param int   $total_stock       Total stock calculated for the product.
     */
    private function sync_color_variations(
        $product_id,
        $color_variations,
        $total_stock
    ) {
        if (empty($product_id) || count($color_variations) < 2) {
            return;
        }

        $product = wc_get_product($product_id);
        if (!$product) {
            return;
        }

        if ("variable" !== $product->get_type()) {
            $product = new WC_Product_Variable($product_id);
        }

        if (!$product || "variable" !== $product->get_type()) {
            return;
        }

        $attribute_label = self::COLOR_ATTRIBUTE_NAME;
        $attribute_key = sanitize_title($attribute_label);
        $attribute_meta_key = "attribute_" . $attribute_key;

        $existing_attributes = get_post_meta(
            $product_id,
            "_product_attributes",
            true,
        );
        if (!is_array($existing_attributes)) {
            $existing_attributes = [];
        }

        $attribute_values = [];
        foreach ($color_variations as $variation) {
            if (empty($variation["color"])) {
                continue;
            }

            $value = $variation["color"];
            $hash = strtolower($value);

            if (!isset($attribute_values[$hash])) {
                $attribute_values[$hash] = $value;
            }
        }

        if (empty($attribute_values)) {
            return;
        }

        $existing_attributes[$attribute_key] = [
            "name" => $attribute_label,
            "value" => implode(" | ", $attribute_values),
            "is_visible" => 1,
            "is_taxonomy" => 0,
            "is_variation" => 1,
        ];

        update_post_meta(
            $product_id,
            "_product_attributes",
            $existing_attributes,
        );

        $existing_children = [];
        $child_ids = $product->get_children();
        foreach ($child_ids as $child_id) {
            $option_id = get_post_meta($child_id, "_silverbene_option_id", true);
            if (!empty($option_id)) {
                $existing_children["option:" . $option_id] = $child_id;
            }

            $child_sku = get_post_meta($child_id, "_sku", true);
            if (!empty($child_sku)) {
                $existing_children["sku:" . $child_sku] = $child_id;
            }

            $child_color = get_post_meta($child_id, $attribute_meta_key, true);
            if (!empty($child_color)) {
                $existing_children["color:" . strtolower($child_color)] = $child_id;
            }
        }

        $used_variation_ids = [];
        $min_price = null;
        $has_stock = false;

        foreach ($color_variations as $variation_data) {
            $lookup_keys = [];
            if (!empty($variation_data["option_id"])) {
                $lookup_keys[] = "option:" . $variation_data["option_id"];
            }
            if (!empty($variation_data["sku"])) {
                $lookup_keys[] = "sku:" . $variation_data["sku"];
            }
            if (!empty($variation_data["color"])) {
                $lookup_keys[] = "color:" . strtolower($variation_data["color"]);
            }

            $variation_id = null;
            foreach ($lookup_keys as $key) {
                if (isset($existing_children[$key])) {
                    $variation_id = $existing_children[$key];
                    break;
                }
            }

            if ($variation_id) {
                $variation = new WC_Product_Variation($variation_id);
            } else {
                $variation = new WC_Product_Variation();
                $variation->set_parent_id($product_id);
            }

            $variation->set_status("publish");

            if (!empty($variation_data["sku"])) {
                $variation->set_sku($variation_data["sku"]);
            }

            if (null !== $variation_data["price"]) {
                $price = wc_format_decimal($variation_data["price"]);
                $variation->set_regular_price($price);
                $variation->set_price($price);

                if (null === $min_price || $variation_data["price"] < $min_price) {
                    $min_price = $variation_data["price"];
                }
            }

            if (null !== $variation_data["stock"]) {
                $stock_qty = intval($variation_data["stock"]);
                $variation->set_manage_stock(true);
                $variation->set_stock_quantity($stock_qty);
                $variation->set_stock_status($stock_qty > 0 ? "instock" : "outofstock");

                if ($stock_qty > 0) {
                    $has_stock = true;
                }
            } else {
                $variation->set_manage_stock(false);
                $variation->set_stock_status($total_stock > 0 ? "instock" : "outofstock");

                if ($total_stock > 0) {
                    $has_stock = true;
                }
            }

            $variation->set_attributes([
                $attribute_meta_key => $variation_data["color"],
            ]);

            $saved_variation_id = $variation->save();
            if (!$saved_variation_id) {
                continue;
            }

            if (!empty($variation_data["option_id"])) {
                update_post_meta(
                    $saved_variation_id,
                    "_silverbene_option_id",
                    $variation_data["option_id"],
                );
            }

            $used_variation_ids[] = $saved_variation_id;
        }

        $product = wc_get_product($product_id);
        $current_children = [];
        if ($product && method_exists($product, "get_children")) {
            $current_children = $product->get_children();
        }
        foreach ($current_children as $child_id) {
            if (!in_array($child_id, $used_variation_ids, true)) {
                wp_delete_post($child_id, true);
            }
        }

        if (!$product || "variable" !== $product->get_type()) {
            $product = new WC_Product_Variable($product_id);
        }

        if (null !== $min_price) {
            $product->set_regular_price(wc_format_decimal($min_price));
            $product->set_price(wc_format_decimal($min_price));
        }

        $product->set_manage_stock(false);
        $product->set_stock_status($has_stock ? "instock" : "outofstock");
        $product->save();

        if (method_exists("WC_Product_Variable", "sync")) {
            WC_Product_Variable::sync($product_id);
        }
    }

    /**
     * Remove existing variations and color attribute when product should be simple.
     *
     * @param int $product_id Product ID.
     */
    private function remove_existing_variations($product_id)
    {
        if (empty($product_id)) {
            return;
        }

        $product = wc_get_product($product_id);
        if ($product && method_exists($product, "get_children")) {
            foreach ($product->get_children() as $child_id) {
                wp_delete_post($child_id, true);
            }
        }

        $attributes = get_post_meta(
            $product_id,
            "_product_attributes",
            true,
        );
        if (is_array($attributes)) {
            $attribute_key = sanitize_title(self::COLOR_ATTRIBUTE_NAME);
            if (isset($attributes[$attribute_key])) {
                unset($attributes[$attribute_key]);
                update_post_meta($product_id, "_product_attributes", $attributes);
            }
        }
    }

    /**
     * Retrieve option payload array from API response.
     *
     * @param array $product_data Product payload.
     *
     * @return array
     */
    private function get_product_options($product_data)
    {
        if (!empty($product_data["options"]) && is_array($product_data["options"])) {
            return $product_data["options"];
        }

        if (!empty($product_data["option"]) && is_array($product_data["option"])) {
            return $product_data["option"];
        }

        return [];
    }

    /**
     * Prepare color variation payloads from option data.
     *
     * @param array    $product_data   Product payload.
     * @param array    $settings       Plugin settings for markup rules.
     * @param float|int $fallback_price Fallback price when option price missing.
     *
     * @return array
     */
    private function prepare_color_variations(
        $product_data,
        $settings,
        $fallback_price = null
    ) {
        $options = $this->get_product_options($product_data);

        if (empty($options)) {
            return [];
        }

        $variations = [];

        foreach ($options as $option) {
            if (!is_array($option)) {
                continue;
            }

            $color_value = $this->extract_option_color($option);
            if ('' === $color_value) {
                continue;
            }

            $color_value = wc_clean($color_value);
            $color_index = strtolower($color_value);

            if (isset($variations[$color_index])) {
                continue;
            }

            $raw_price = $this->extract_value(
                $option,
                [
                    "price",
                    "regular_price",
                    "selling_price",
                    "sale_price",
                    "shop_price",
                ],
                null,
            );

            if ("" === $raw_price) {
                $raw_price = null;
            }

            if (null !== $raw_price) {
                $variation_price = $this->apply_markup($raw_price, $settings);
            } elseif (null !== $fallback_price) {
                $variation_price = $this->apply_markup($fallback_price, $settings);
            } else {
                $variation_price = null;
            }

            $raw_stock = $this->extract_value(
                $option,
                [
                    "stock",
                    "stock_quantity",
                    "quantity",
                    "inventory",
                    "stock_qty",
                    "qty",
                    "real_qty",
                    "option_qty",
                ],
                null,
            );

            if ("" === $raw_stock) {
                $raw_stock = null;
            }

            $stock = null !== $raw_stock ? intval($raw_stock) : null;

            $variations[$color_index] = [
                "color" => $color_value,
                "price" => $variation_price,
                "stock" => $stock,
                "sku" => $this->extract_value(
                    $option,
                    ["sku", "option_sku", "variant_sku"],
                    "",
                ),
                "option_id" => $this->extract_value(
                    $option,
                    ["option_id", "id", "variant_id"],
                    "",
                ),
            ];
        }

        return array_values($variations);
    }

    /**
     * Attempt to extract color value from an option payload.
     *
     * @param array $option Option payload.
     *
     * @return string
     */
    private function extract_option_color($option)
    {
        if (empty($option) || !is_array($option)) {
            return "";
        }

        $color_keys = [
            "color",
            "colour",
            "color_name",
            "colour_name",
            "metal_color",
            "metal_colour",
            "plating",
        ];

        foreach ($color_keys as $key) {
            if (empty($option[$key])) {
                continue;
            }

            $value = $option[$key];
            if (is_array($value)) {
                $value = implode(", ", array_filter($value));
            }

            if ('' !== trim((string) $value)) {
                return $value;
            }
        }

        if (!empty($option["attributes"]) && is_array($option["attributes"])) {
            foreach ($option["attributes"] as $key => $value) {
                if (false === stripos((string) $key, "color")) {
                    continue;
                }

                if (is_array($value)) {
                    $value = implode(", ", array_filter($value));
                }

                if ('' !== trim((string) $value)) {
                    return $value;
                }
            }
        }

        foreach ($option as $key => $value) {
            if (!is_string($key) || false === stripos($key, "color")) {
                continue;
            }

            if (is_array($value)) {
                $value = implode(", ", array_filter($value));
            }

            if ('' !== trim((string) $value)) {
                return $value;
            }
        }

        return "";
    }

    /**
     * Download and set product images.
     *
     * @param int  $product_id Product ID.
     * @param array $product_data API data.
     * @param bool $is_new Whether the product is newly created.
     */
    private function assign_images($product_id, $product_data, $is_new)
    {
        $images = $this->extract_value(
            $product_data,
            [
                "images",
                "product_images",
                "gallery",
                "image_urls",
                "image_list",
                "img_urls",
                "pictures",
                "photos",
                "thumb",
            ],
            [],
        );

        if (empty($images)) {
            return;
        }

        if (!is_array($images)) {
            $images = [$images];
        }

        require_once ABSPATH . "wp-admin/includes/media.php";
        require_once ABSPATH . "wp-admin/includes/file.php";
        require_once ABSPATH . "wp-admin/includes/image.php";

        $image_ids = [];
        foreach ($images as $index => $image_url) {
            if (
                empty($image_url) ||
                !filter_var($image_url, FILTER_VALIDATE_URL)
            ) {
                continue;
            }

            $attachment_id = $this->find_existing_attachment($image_url);

            if (!$attachment_id) {
                $attachment_id = $this->sideload_image(
                    $image_url,
                    $product_id,
                    $index,
                );
            }
            if ($attachment_id) {
                if (0 === $index) {
                    set_post_thumbnail($product_id, $attachment_id);
                } else {
                    $image_ids[] = $attachment_id;
                }
            }
        }

        if (!empty($image_ids)) {
            update_post_meta(
                $product_id,
                "_product_image_gallery",
                implode(",", $image_ids),
            );
        } elseif ($is_new) {
            delete_post_meta($product_id, "_product_image_gallery");
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
    private function sideload_image($image_url, $product_id, $index)
    {
        $existing_attachment_id = $this->find_existing_attachment($image_url);

        if ($existing_attachment_id) {
            return $existing_attachment_id;
        }

        $tmp = download_url($image_url);

        if (is_wp_error($tmp)) {
            return false;
        }

        $file_array = [
            "name" => basename(parse_url($image_url, PHP_URL_PATH)),
            "tmp_name" => $tmp,
        ];

        $id = media_handle_sideload($file_array, $product_id);

        if (is_wp_error($id)) {
            @unlink($tmp);
            return false;
        }

        update_post_meta(
            $id,
            "_silverbene_source_image_url",
            esc_url_raw($image_url)
        );

        return $id;
    }

    /**
     * Find an existing attachment that matches the given source URL.
     *
     * @param string $image_url Image URL.
     *
     * @return int Attachment ID if found, 0 otherwise.
     */
    private function find_existing_attachment($image_url)
    {
        if (empty($image_url) || !filter_var($image_url, FILTER_VALIDATE_URL)) {
            return 0;
        }

        $attachment_id = attachment_url_to_postid($image_url);

        if ($attachment_id) {
            return (int) $attachment_id;
        }

        $attachments = get_posts([
            "post_type" => "attachment",
            "posts_per_page" => 1,
            "fields" => "ids",
            "meta_query" => [
                [
                    "key" => "_silverbene_source_image_url",
                    "value" => esc_url_raw($image_url),
                ],
            ],
        ]);

        if (!empty($attachments)) {
            return (int) $attachments[0];
        }

        return 0;
    }

    /**
     * Calculate total available stock for a product payload.
     *
     * @param array $product_data Product data from API.
     *
     * @return int
     */
    private function get_total_stock($product_data)
    {
        if (empty($product_data) || !is_array($product_data)) {
            return 0;
        }

        $stock = $this->extract_value(
            $product_data,
            [
                "stock",
                "stock_quantity",
                "quantity",
                "inventory",
                "stock_qty",
                "qty",
                "real_qty",
                "option_qty",
            ],
            null,
        );

        if (null !== $stock) {
            return intval($stock);
        }

        $total_stock = 0;

        $options = $this->get_product_options($product_data);

        if (!empty($options)) {
            foreach ($options as $option) {
                if (!is_array($option)) {
                    continue;
                }

                $option_stock = $this->extract_value(
                    $option,
                    [
                        "stock",
                        "stock_quantity",
                        "quantity",
                        "inventory",
                        "stock_qty",
                        "qty",
                        "real_qty",
                        "option_qty",
                    ],
                    null,
                );

                if (null !== $option_stock) {
                    $total_stock += intval($option_stock);
                }
            }
        }

        return intval($total_stock);
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
    private function extract_value($data, $keys, $default = "")
    {
        foreach ($keys as $key) {
            if (isset($data[$key])) {
                return $data[$key];
            }
        }

        return $default;
    }
}
