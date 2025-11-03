<?php
class Silverbene_Sync
{
    private const LAST_SYNC_STATUS_OPTION = "silverbene_last_sync_status";
    private const LAST_SUCCESSFUL_SYNC_OPTION = "silverbene_last_sync_timestamp";
    private const DEFAULT_SYNC_LOOKBACK_DAYS = 30;
    private const ADMIN_NOTICE_TRANSIENT = "silverbene_sync_admin_notice";
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
     *
     * @return bool True on success, false on failure.
     */
    public function sync_products($force = false)
    {
        if (!class_exists("WooCommerce")) {
            return false;
        }

        $settings = $this->client->get_settings();
        if (!$force && empty($settings["sync_enabled"])) {
            return true;
        }

        $logger = function_exists("wc_get_logger") ? wc_get_logger() : null;
        $context = ["source" => "silverbene-api-sync"];

        $page = 1;
        $per_page = 50;
        $imported = 0;
        $sync_failed = false;
        $failure_message = "";

        $last_successful_sync_timestamp = 0;
        if (function_exists("get_option")) {
            $stored_timestamp = get_option(self::LAST_SUCCESSFUL_SYNC_OPTION);

            if (is_numeric($stored_timestamp) && (int) $stored_timestamp > 0) {
                $last_successful_sync_timestamp = (int) $stored_timestamp;
            }
        }

        $lookback_seconds = (defined("DAY_IN_SECONDS") ? DAY_IN_SECONDS : 86400) * self::DEFAULT_SYNC_LOOKBACK_DAYS;
        $fallback_timestamp = max(time() - $lookback_seconds, 0);

        if ($last_successful_sync_timestamp <= 0) {
            $last_successful_sync_timestamp = $fallback_timestamp;
        }

        $start_date_value = max($last_successful_sync_timestamp, 0);

        $start_date = function_exists("wp_date")
            ? wp_date("Y-m-d", $start_date_value)
            : gmdate("Y-m-d", $start_date_value);

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
                "start_date" => $start_date,
                "end_date" => wp_date("Y-m-d"),
            ]);

            if (empty($products)) {
                break;
            }

            $grouped_products = $this->group_products_by_parent($products);

            foreach ($grouped_products as $product_data) {
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

                $sku_for_logging = $this->extract_value(
                    $product_data,
                    [
                        "sku",
                        "SKU",
                        "product_sku",
                        "id",
                    ],
                    "",
                );
                $product_name_for_logging = $this->extract_value(
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

                $product_id = $this->upsert_product(
                    $product_data,
                    $settings,
                    $total_stock,
                );
                if (false === $product_id) {
                    $product_identifier = $sku_for_logging
                        ? sprintf(
                            /* translators: %s: product SKU. */
                            __("produk dengan SKU %s", "silverbene-api-integration"),
                            $sku_for_logging,
                        )
                        : __("produk tanpa SKU", "silverbene-api-integration");

                    if (empty($sku_for_logging) && !empty($product_name_for_logging)) {
                        $product_identifier = sprintf(
                            /* translators: %s: product name. */
                            __("produk \"%s\"", "silverbene-api-integration"),
                            $product_name_for_logging,
                        );
                    }

                    $failure_message = sprintf(
                        /* translators: %s: product identifier. */
                        __(
                            "Sinkronisasi produk dihentikan karena gagal memperbarui %s. Silakan periksa log untuk detail.",
                            "silverbene-api-integration"
                        ),
                        $product_identifier,
                    );

                    if ($logger) {
                        $logger->error($failure_message, $context);
                    }

                    $sync_failed = true;

                    break 2;
                }

                if ($product_id) {
                    $imported++;
                }
            }

            $page++;
        } while (count($products) >= $per_page);

        if ($sync_failed) {
            $this->record_sync_result(false, $failure_message);

            return false;
        }

        $success_message = sprintf(
            __("Sinkronisasi produk selesai. Total produk diperbarui: %d", "silverbene-api-integration"),
            $imported,
        );

        if ($logger) {
            $logger->info($success_message, $context);
        }

        $this->record_sync_result(true, $success_message);

        return true;
    }

    /**
     * Store the result of the latest sync for later reference.
     *
     * @param bool   $success Whether the sync was successful.
     * @param string $message Descriptive message about the sync result.
     */
    private function record_sync_result($success, $message)
    {
        if (function_exists("update_option")) {
            update_option(
                self::LAST_SYNC_STATUS_OPTION,
                [
                    "success" => (bool) $success,
                    "timestamp" => time(),
                    "message" => $message,
                ],
            );
        }

        if ($success) {
            if (function_exists("update_option")) {
                update_option(self::LAST_SUCCESSFUL_SYNC_OPTION, time());
            }

            if (function_exists("delete_transient")) {
                delete_transient(self::ADMIN_NOTICE_TRANSIENT);
            }

            return;
        }

        if (!function_exists("set_transient")) {
            return;
        }

        $expiration = defined("DAY_IN_SECONDS") ? DAY_IN_SECONDS : 86400;

        set_transient(
            self::ADMIN_NOTICE_TRANSIENT,
            [
                "type" => "error",
                "message" => $message,
            ],
            $expiration,
        );
    }

    /**
     * Group raw API products by their parent identifier so each WooCommerce product handles all children.
     *
     * @param array $products Raw product payloads.
     *
     * @return array
     */
    private function group_products_by_parent($products)
    {
        if (empty($products) || !is_array($products)) {
            return [];
        }

        $grouped = [];

        foreach ($products as $product_data) {
            if (empty($product_data) || !is_array($product_data)) {
                continue;
            }

            $parent_identifier = $this->get_parent_identifier($product_data);
            $child_sku = $this->extract_value(
                $product_data,
                [
                    "sku",
                    "SKU",
                    "product_sku",
                    "id",
                ],
                "",
            );

            $group_key = $parent_identifier ?: $child_sku;

            if (empty($group_key)) {
                $encoded = function_exists("wp_json_encode")
                    ? wp_json_encode($product_data)
                    : json_encode($product_data);
                $group_key = md5((string) $encoded);
            }

            if (!isset($grouped[$group_key])) {
                $grouped[$group_key] = $product_data;
                $grouped[$group_key]["_grouped_options"] = [];
            } else {
                $grouped[$group_key] = $this->merge_product_payload(
                    $grouped[$group_key],
                    $product_data,
                );
            }

            if (!empty($parent_identifier)) {
                $grouped[$group_key]["parent_sku"] = $parent_identifier;

                if (
                    empty($grouped[$group_key]["sku"]) ||
                    $grouped[$group_key]["sku"] === $child_sku
                ) {
                    $grouped[$group_key]["sku"] = $parent_identifier;
                }
            }

            $options = $this->get_product_options($product_data);
            if (empty($options)) {
                $generated_option = $this->convert_product_to_option(
                    $product_data,
                    $parent_identifier,
                );

                if (!empty($generated_option)) {
                    $options = [$generated_option];
                }
            }

            foreach ($options as $option) {
                $normalized_option = $this->normalize_grouped_option(
                    $option,
                    $parent_identifier,
                );

                if (!empty($normalized_option)) {
                    $grouped[$group_key]["_grouped_options"][] = $normalized_option;
                }
            }
        }

        foreach ($grouped as &$group_data) {
            if (!empty($group_data["_grouped_options"])) {
                $group_data["options"] = $this->deduplicate_grouped_options(
                    $group_data["_grouped_options"],
                );
            }

            unset($group_data["_grouped_options"]);

            if (!empty($group_data["parent_sku"])) {
                $group_data["_silverbene_parent_identifier"] = $group_data["parent_sku"];

                if (empty($group_data["sku"])) {
                    $group_data["sku"] = $group_data["parent_sku"];
                }
            }
        }

        unset($group_data);

        return array_values($grouped);
    }

    /**
     * Merge two product payloads, prioritising existing values and filling gaps with incoming data.
     *
     * @param array $base     Base payload.
     * @param array $incoming Incoming payload.
     *
     * @return array
     */
    private function merge_product_payload($base, $incoming)
    {
        foreach ($incoming as $key => $value) {
            if (in_array($key, ["options", "_grouped_options"], true)) {
                continue;
            }

            if (!isset($base[$key]) || "" === $base[$key] || null === $base[$key]) {
                $base[$key] = $value;
            }
        }

        return $base;
    }

    /**
     * Ensure grouped option payload contains a reference to its parent identifier.
     *
     * @param array  $option            Raw option payload.
     * @param string $parent_identifier Parent identifier.
     *
     * @return array
     */
    private function normalize_grouped_option($option, $parent_identifier)
    {
        if (!is_array($option)) {
            return [];
        }

        $normalized = $option;

        if (!empty($parent_identifier)) {
            $normalized["parent_sku"] = $parent_identifier;
        } elseif (empty($normalized["parent_sku"])) {
            $normalized["parent_sku"] = $this->get_parent_identifier($option);
        }

        return $normalized;
    }

    /**
     * Convert a standalone product payload into an option payload when the API does not provide options.
     *
     * @param array  $product_data      Product payload.
     * @param string $parent_identifier Parent identifier.
     *
     * @return array
     */
    private function convert_product_to_option($product_data, $parent_identifier = "")
    {
        if (empty($product_data) || !is_array($product_data)) {
            return [];
        }

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

        if (empty($sku)) {
            return [];
        }

        $option = $product_data;
        $option["sku"] = $sku;

        if (!empty($parent_identifier)) {
            $option["parent_sku"] = $parent_identifier;
        }

        if (empty($option["option_id"])) {
            $option["option_id"] = $this->extract_value(
                $product_data,
                ["option_id", "id", "variant_id"],
                "",
            );
        }

        if (!isset($option["price"])) {
            $price = $this->extract_value(
                $product_data,
                [
                    "price",
                    "regular_price",
                    "selling_price",
                    "sale_price",
                    "shop_price",
                ],
                null,
            );

            if ("" === $price) {
                $price = null;
            }

            if (null !== $price) {
                $option["price"] = $price;
            }
        }

        if (!isset($option["stock"])) {
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

            if ("" === $stock) {
                $stock = null;
            }

            if (null !== $stock) {
                $option["stock"] = $stock;
            }
        }

        return $option;
    }

    /**
     * Deduplicate grouped options by their option ID or SKU.
     *
     * @param array $options Option payloads.
     *
     * @return array
     */
    private function deduplicate_grouped_options($options)
    {
        $unique = [];
        $result = [];

        foreach ($options as $option) {
            if (!is_array($option)) {
                continue;
            }

            $key = "";

            if (!empty($option["option_id"])) {
                $key = "option:" . $option["option_id"];
            } elseif (!empty($option["sku"])) {
                $key = "sku:" . $option["sku"];
            } else {
                $encoded = function_exists("wp_json_encode")
                    ? wp_json_encode($option)
                    : json_encode($option);
                $key = "hash:" . md5((string) $encoded);
            }

            if (isset($unique[$key])) {
                continue;
            }

            $unique[$key] = true;
            $result[] = $option;
        }

        return $result;
    }

    /**
     * Extract parent identifier from payload.
     *
     * @param array $product_data Product payload.
     *
     * @return string
     */
    private function get_parent_identifier($product_data)
    {
        if (empty($product_data) || !is_array($product_data)) {
            return "";
        }

        $keys = [
            "parent_sku",
            "parentSku",
            "parent_spu",
            "parentSpu",
            "parent_id",
            "parentId",
            "spu",
            "SPU",
            "style",
            "style_no",
            "style_number",
            "style_id",
            "goods_spu",
            "goodsSpu",
            "group_sku",
            "groupSku",
            "product_parent_sku",
            "parentSkuCode",
            "main_sku",
            "mainSku",
        ];

        $identifier = $this->extract_value($product_data, $keys, "");

        if (!empty($identifier)) {
            return (string) $identifier;
        }

        if (!empty($product_data["parent"]) && is_array($product_data["parent"])) {
            $identifier = $this->extract_value(
                $product_data["parent"],
                array_merge(["sku", "id"], $keys),
                "",
            );

            if (!empty($identifier)) {
                return (string) $identifier;
            }
        }

        return "";
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
        $has_color_variations = count($color_variations) > 1
            || count($unique_color_values) > 1;
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
        $this->assign_brand($product_id, $settings);
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

        $parent_sku = $this->get_parent_identifier($product_data);
        if (empty($parent_sku) && !empty($product_data["_silverbene_parent_identifier"])) {
            $parent_sku = $product_data["_silverbene_parent_identifier"];
        }

        if (!empty($parent_sku)) {
            $clean_parent_sku = wc_clean((string) $parent_sku);
            update_post_meta($product_id, "_silverbene_parent_sku", $clean_parent_sku);
            update_post_meta($product_id, "silverbene_parent_sku", $clean_parent_sku);
        }

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
     * Assign a default brand to the product when configured.
     *
     * @param int   $product_id Product ID.
     * @param array $settings   Plugin settings.
     */
    private function assign_brand($product_id, $settings)
    {
        if (empty($settings["default_brand"])) {
            return;
        }

        $brand_name = trim(wp_strip_all_tags($settings["default_brand"]));

        if ("" === $brand_name) {
            return;
        }

        if (taxonomy_exists("product_brand")) {
            $term = term_exists($brand_name, "product_brand");

            if (!$term) {
                $term = wp_insert_term($brand_name, "product_brand");
            }

            if (!is_wp_error($term)) {
                $term_id = is_array($term) ? intval($term["term_id"]) : intval($term);
                wp_set_object_terms($product_id, [$term_id], "product_brand", false);
            }

            return;
        }

        $attribute_label = __("Brand", "silverbene-api-integration");
        $attribute_key = sanitize_title($attribute_label);

        $existing_attributes = get_post_meta(
            $product_id,
            "_product_attributes",
            true,
        );

        if (!is_array($existing_attributes)) {
            $existing_attributes = [];
        }

        $existing_attributes[$attribute_key] = [
            "name" => $attribute_label,
            "value" => $brand_name,
            "is_visible" => 1,
            "is_variation" => 0,
            "is_taxonomy" => 0,
        ];

        update_post_meta($product_id, "_product_attributes", $existing_attributes);
        update_post_meta($product_id, "attribute_" . $attribute_key, $brand_name);
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
            $attribute_value = !empty($variation["attribute_value"])
                ? $variation["attribute_value"]
                : $variation["color"];

            if (empty($attribute_value)) {
                continue;
            }

            $hash = strtolower($attribute_value);

            if (!isset($attribute_values[$hash])) {
                $attribute_values[$hash] = $attribute_value;
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
                $existing_children["attribute:" . strtolower($child_color)] = $child_id;
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
            if (!empty($variation_data["attribute_value"])) {
                $lookup_keys[] = "attribute:" . strtolower(
                    $variation_data["attribute_value"],
                );
            } elseif (!empty($variation_data["color"])) {
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

            $attribute_value = !empty($variation_data["attribute_value"])
                ? $variation_data["attribute_value"]
                : $variation_data["color"];

            if (empty($attribute_value)) {
                continue;
            }

            $variation->set_attributes([
                $attribute_meta_key => $attribute_value,
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

            if (!empty($variation_data["parent_sku"])) {
                update_post_meta(
                    $saved_variation_id,
                    "_silverbene_parent_sku",
                    wc_clean((string) $variation_data["parent_sku"]),
                );
            }

            if (!empty($variation_data["color"])) {
                update_post_meta(
                    $saved_variation_id,
                    "_silverbene_color_label",
                    wc_clean((string) $variation_data["color"]),
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
        $color_counts = [];
        $parent_identifier = $this->get_parent_identifier($product_data);

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
            $color_counts[$color_index] = isset($color_counts[$color_index])
                ? intval($color_counts[$color_index]) + 1
                : 1;

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

            $option_sku = $this->extract_value(
                $option,
                ["sku", "option_sku", "variant_sku"],
                "",
            );

            $attribute_value = $color_value;
            if ($color_counts[$color_index] > 1) {
                $attribute_value = !empty($option_sku)
                    ? sprintf("%s (%s)", $color_value, $option_sku)
                    : sprintf("%s #%d", $color_value, $color_counts[$color_index]);
            }

            $variations[] = [
                "color" => $color_value,
                "attribute_value" => wc_clean($attribute_value),
                "price" => $variation_price,
                "stock" => $stock,
                "sku" => $option_sku,
                "option_id" => $this->extract_value(
                    $option,
                    ["option_id", "id", "variant_id"],
                    "",
                ),
                "parent_sku" => !empty($option["parent_sku"])
                    ? $option["parent_sku"]
                    : $parent_identifier,
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
