<?php
/**
 * Silverbene API client helper.
 */
class Silverbene_API_Client
{
    /**
     * Plugin settings for the API integration.
     *
     * @var array
     */
    private $settings = [];

    /**
     * Constructor.
     *
     * @param array $settings Optional settings array. If omitted they will be lazily loaded.
     */
    public function __construct($settings = [])
    {
        $this->settings = $this->parse_settings($settings);
    }

    /**
     * Retrieve products from the Silverbene API.
     *
     * @param array $args Optional query arguments for pagination/filtering.
     * @return array
     */
    public function get_products($args = [])
    {
        $token = $this->get_setting("api_key", "");
        if (empty($token)) {
            $message = "API token is missing, cannot fetch products.";
            $this->log_error($message);
            return new WP_Error('silverbene_api_error', $message);
        }

        $query_args = $this->build_product_query_args($args);
        $query_args["token"] = $token;

        $endpoint = $this->determine_products_endpoint($args);

        $response = $this->request("GET", $endpoint, [
            "query" => $query_args,
        ]);

        if (is_wp_error($response)) {
            return $response;
        }

        if (empty($response)) {
            return [];
        }

        if (isset($response["code"]) && 0 !== intval($response["code"])) {
            $error_message = isset($response['message']) ? $response['message'] : 'Unknown API error';
            $this->log_error(
                "Product request returned non zero status code",
                $response,
            );
            return new WP_Error('silverbene_api_error', $error_message, $response);
        }

        $products = $this->normalize_products_response($response);

        if (empty($products)) {
            return [];
        }

        return $this->maybe_enrich_with_option_quantities($products);
    }

    /**
     * Create an order on Silverbene.
     *
     * @param array $order_data Formatted order payload.
     *
     * @return array|null
     */
    public function create_order($order_data)
    {
        $token = $this->get_setting("api_key", "");
        if (empty($token)) {
            $this->log_error("API token is missing, cannot create order.");
            return null;
        }

        if (empty($order_data["token"])) {
            $order_data["token"] = $token;
        }

        $endpoint = $this->get_setting(
            "orders_endpoint",
            "/dropshipping/create_order",
        );

        $response = $this->request("POST", $endpoint, [
            "body" => wp_json_encode($order_data),
            "headers" => [
                "Content-Type" => "application/json",
            ],
        ]);

        if (empty($response)) {
            return null;
        }

        if (isset($response["code"]) && 0 !== intval($response["code"])) {
            $this->log_error(
                "Create order request returned non zero status code",
                $response,
            );
        }

        return $response;
    }

    /**
     * Retrieve available shipping methods for a country.
     *
     * @param string $country_id Two letter ISO 3166-1 alpha-2 country code.
     *
     * @return array
     */
    public function get_shipping_methods($country_id)
    {
        $country_id = strtoupper(trim($country_id));

        if (empty($country_id)) {
            return [];
        }

        $token = $this->get_setting("api_key", "");
        if (empty($token)) {
            $this->log_error(
                "API token is missing, cannot fetch shipping methods.",
            );
            return [];
        }

        $endpoint = $this->get_setting(
            "shipping_methods_endpoint",
            "/dropshipping/get_shipping_method",
        );

        $response = $this->request("GET", $endpoint, [
            "query" => [
                "token" => $token,
                "country_id" => $country_id,
            ],
        ]);

        if (empty($response)) {
            return [];
        }

        if (isset($response["code"]) && 0 !== intval($response["code"])) {
            $this->log_error(
                "Shipping method request returned non zero status code",
                $response,
            );
            return [];
        }

        $data = $this->unwrap_data_container($response);

        return is_array($data) ? $data : [];
    }

    /**
     * Execute a request to the Silverbene API.
     *
     * @param string $method   HTTP method.
     * @param string $endpoint Endpoint path, with or without leading slash.
     * @param array  $args     Additional arguments (`body`, `headers`, `query`, ...).
     *
     * @return array|null The decoded response body or null on failure.
     */
    public function request($method, $endpoint, $args = [])
    {
        $base_url = untrailingslashit(
            $this->get_setting("api_url", "https://s.silverbene.com/api"),
        );
        $endpoint = "/" . ltrim($endpoint, "/");
        $url = $base_url . $endpoint;

        $default_timeout = 60;
        $timeout = isset($args["timeout"]) ? intval($args["timeout"]) : $default_timeout;
        $timeout = max(5, min($timeout, 120));

        $request_args = [
            "method" => strtoupper($method),
            "timeout" => $timeout,
            "headers" => $this->prepare_headers(
                isset($args["headers"]) ? $args["headers"] : [],
            ),
        ];

        if (!empty($args["body"])) {
            $request_args["body"] = $args["body"];
        }

        if (!empty($args["query"]) && is_array($args["query"])) {
            $url = add_query_arg($args["query"], $url);
        }

        $response = wp_remote_request($url, $request_args);

        if (is_wp_error($response)) {
            $error_message = $response->get_error_message();
            $this->log_error("API request failed", [
                "endpoint" => $url,
                "error" => $error_message,
            ]);
            return new WP_Error('silverbene_http_error', $error_message, ['endpoint' => $url]);
        }

        $status_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $decoded = null;

        if (!empty($body)) {
            $decoded = json_decode($body, true);
        }

        if ($status_code < 200 || $status_code >= 300) {
            $error_message = sprintf(
                __('API request returned a non-success status code %d.', 'silverbene-api-integration'),
                $status_code
            );

            if (!empty($decoded['message'])) {
                $error_message = $decoded['message'];
            }

            $this->log_error($error_message, [
                "endpoint" => $url,
                "status_code" => $status_code,
                "body" => $decoded,
            ]);

            return new WP_Error('silverbene_api_error', $error_message, ['status_code' => $status_code, 'body' => $decoded]);
        }

        return $decoded;
    }

    /**
     * Retrieve the full settings array.
     *
     * @return array
     */
    public function get_settings()
    {
        $this->settings = $this->parse_settings(
            get_option("silverbene_api_settings", []),
        );
        return $this->settings;
    }

    /**
     * Retrieve a single setting value.
     *
     * @param string $key     Setting key.
     * @param mixed  $default Default fallback.
     *
     * @return mixed
     */
    public function get_setting($key, $default = "")
    {
        $settings = $this->get_settings();
        return isset($settings[$key]) && "" !== $settings[$key]
            ? $settings[$key]
            : $default;
    }

    /**
     * Update cached settings with new values (e.g. after saving options).
     */
    public function refresh_settings()
    {
        $this->settings = $this->parse_settings(
            get_option("silverbene_api_settings", []),
        );
    }

    /**
     * Prepare request headers.
     *
     * @param array $headers Optional headers to merge.
     * @return array
     */
    private function prepare_headers($headers = [])
    {
        $headers = wp_parse_args($headers, []);
        $headers["Accept"] = "application/json";

        return $headers;
    }

    /**
     * Parse settings array with defaults.
     *
     * @param array $settings Raw settings.
     * @return array
     */
    private function parse_settings($settings)
    {
        return wp_parse_args($settings, [
            "api_url" => "https://s.silverbene.com/api",
            "api_key" => "",
            "api_secret" => "",
            "products_endpoint" => "/dropshipping/product_list",
            "products_by_date_endpoint" => "/dropshipping/product_list_by_date",
            "option_qty_endpoint" => "/dropshipping/option_qty",
            "orders_endpoint" => "/dropshipping/create_order",
            "shipping_methods_endpoint" => "/dropshipping/get_shipping_method",
            "sync_enabled" => false,
            "sync_interval" => "hourly",
            "default_category" => "",
            "price_markup_type" => "percentage",
            "price_markup_value" => 0,
            "price_markup_value_below_100" => "",
            "price_markup_value_above_100" => "",
            "pre_markup_shipping_fee" => 0,
            "sync_start_date" => "",
        ]);
    }

    /**
     * Determine the endpoint to use for product retrieval.
     *
     * @param array $args Request arguments.
     *
     * @return string
     */
    private function determine_products_endpoint($args)
    {
        $use_by_date = !empty($args["start_date"]) || !empty($args["end_date"]);

        if ($use_by_date) {
            return $this->get_setting(
                "products_by_date_endpoint",
                "/dropshipping/product_list_by_date",
            );
        }

        return $this->get_setting(
            "products_endpoint",
            "/dropshipping/product_list",
        );
    }

    /**
     * Build query arguments for product requests.
     *
     * @param array $args Original arguments.
     *
     * @return array
     */
    private function build_product_query_args($args)
    {
        $query_args = [];

        if (!empty($args["sku"])) {
            $skus = $args["sku"];
            if (is_array($skus)) {
                $skus = implode(",", array_filter(array_map("trim", $skus)));
            }
            $query_args["sku"] = $skus;
        }

        $date_keys = ["start_date", "end_date"];
        foreach ($date_keys as $date_key) {
            if (!empty($args[$date_key])) {
                $query_args[$date_key] = $args[$date_key];
            }
        }

        if (isset($args["is_really_stock"])) {
            $query_args["is_really_stock"] = intval($args["is_really_stock"]);
        }

        if (!empty($args["keywords"])) {
            $query_args["keywords"] = $args["keywords"];
        }

        $extra_params = ["page", "per_page", "limit", "offset"];
        foreach ($extra_params as $param) {
            if (isset($args[$param]) && "" !== $args[$param]) {
                $query_args[$param] = $args[$param];
            }
        }

        return $query_args;
    }

    /**
     * Normalize product response into a predictable array.
     *
     * @param array $response Raw API response.
     *
     * @return array
     */
    private function normalize_products_response($response)
    {
        if (isset($response["code"]) && 0 !== intval($response["code"])) {
            $this->log_error(
                "Product request returned non zero status code",
                $response,
            );
            return [];
        }

        $data = $this->unwrap_data_container($response);

        if (empty($data) || !is_array($data)) {
            return [];
        }

        $normalized = [];
        foreach ($data as $product_item) {
            if (!is_array($product_item)) {
                continue;
            }

            $normalized[] = $this->normalize_product_item($product_item);
        }

        return $normalized;
    }

    /**
     * Normalize individual product payload.
     *
     * @param array $item Raw item.
     *
     * @return array
     */
    private function normalize_product_item($item)
    {
        $normalized = $item;

        $normalized["sku"] = $this->extract_product_field($item, [
            "sku",
            "product_sku",
            "goods_sn",
            "spu",
            "item_sku",
            "SKU",
        ]);

        $normalized["name"] = $this->extract_product_field($item, [
            "name",
            "title",
            "product_name",
            "goods_name",
            "product_title",
        ]);

        $description = $this->extract_product_field($item, [
            "description",
            "desc",
            "detail",
            "content",
            "product_description",
            "product_detail",
            "goods_desc",
        ]);

        if (!empty($description)) {
            $normalized["description"] = $description;
        }

        $short_desc = $this->extract_product_field($item, [
            "short_description",
            "short_desc",
            "summary",
            "brief",
        ]);

        if (!empty($short_desc)) {
            $normalized["short_description"] = $short_desc;
        }

        $price = $this->extract_product_field($item, [
            "price",
            "regular_price",
            "selling_price",
            "sale_price",
            "shop_price",
            "market_price",
        ]);

        if ("" !== $price && null !== $price) {
            $normalized["price"] = floatval($price);
        }

        $stock = $this->extract_product_field($item, [
            "stock",
            "stock_qty",
            "stock_quantity",
            "quantity",
            "qty",
            "inventory",
            "real_qty",
        ]);

        if ("" !== $stock && null !== $stock) {
            $normalized["stock"] = intval($stock);
        }

        $images = $this->extract_product_images($item);
        if (!empty($images)) {
            $normalized["images"] = $images;
        }

        $options = $this->extract_product_options($item);
        if (!empty($options)) {
            $normalized["options"] = $options;
        }

        return $normalized;
    }

    /**
     * Attempt to unwrap nested response containers to access the actual data list.
     *
     * @param array $response Raw response array.
     *
     * @return array
     */
    private function unwrap_data_container($response)
    {
        if (isset($response["data"])) {
            $data = $response["data"];

            if (isset($data["data"])) {
                $data = $data["data"];
            }

            if (is_array($data)) {
                return $data;
            }
        }

        if (isset($response["items"]) && is_array($response["items"])) {
            return $response["items"];
        }

        if (isset($response["products"]) && is_array($response["products"])) {
            return $response["products"];
        }

        if (isset($response["data_list"]) && is_array($response["data_list"])) {
            return $response["data_list"];
        }

        return is_array($response) ? $response : [];
    }

    /**
     * Extract a single field from the product payload.
     *
     * @param array $item Payload.
     * @param array $keys Possible keys.
     *
     * @return mixed
     */
    private function extract_product_field($item, $keys)
    {
        foreach ($keys as $key) {
            if (
                isset($item[$key]) &&
                "" !== $item[$key] &&
                null !== $item[$key]
            ) {
                return $item[$key];
            }
        }

        return "";
    }

    /**
     * Extract image URLs from the payload.
     *
     * @param array $item Product payload.
     *
     * @return array
     */
    private function extract_product_images($item)
    {
        $image_keys = [
            "images",
            "image_urls",
            "image_list",
            "img_urls",
            "img_list",
            "product_images",
            "gallery",
            "imgs",
            "pictures",
            "photos",
            "image",
            "thumb",
        ];

        foreach ($image_keys as $key) {
            if (empty($item[$key])) {
                continue;
            }

            $images = $item[$key];

            if (is_string($images)) {
                $decoded = json_decode($images, true);
                if (
                    json_last_error() === JSON_ERROR_NONE &&
                    is_array($decoded)
                ) {
                    $images = $decoded;
                }
            }

            if (is_string($images)) {
                $images = array_map("trim", explode(",", $images));
            }

            if (is_array($images)) {
                $urls = [];
                foreach ($images as $image_item) {
                    if (is_array($image_item)) {
                        $candidate = $this->extract_product_field($image_item, [
                            "url",
                            "image",
                            "image_url",
                            "thumb",
                            "src",
                        ]);
                        if (!empty($candidate)) {
                            $urls[] = $candidate;
                        }
                    } elseif (is_string($image_item)) {
                        $urls[] = $image_item;
                    }
                }

                $urls = array_values(array_filter(array_unique($urls)));
                if (!empty($urls)) {
                    return $urls;
                }
            }
        }

        return [];
    }

    /**
     * Extract option payloads.
     *
     * @param array $item Product payload.
     *
     * @return array
     */
    private function extract_product_options($item)
    {
        $option_keys = ["options", "option_list", "variants", "skus", "items"];

        foreach ($option_keys as $key) {
            if (empty($item[$key]) || !is_array($item[$key])) {
                continue;
            }

            $options = [];
            foreach ($item[$key] as $option_item) {
                if (!is_array($option_item)) {
                    continue;
                }

                $option = $option_item;

                if (empty($option["option_id"])) {
                    $option["option_id"] = $this->extract_product_field(
                        $option_item,
                        ["option_id", "id", "variant_id"],
                    );
                }

                if (empty($option["sku"])) {
                    $option["sku"] = $this->extract_product_field(
                        $option_item,
                        ["sku", "option_sku", "variant_sku"],
                    );
                }

                if (!isset($option["price"])) {
                    $price = $this->extract_product_field($option_item, [
                        "price",
                        "selling_price",
                        "sale_price",
                        "shop_price",
                    ]);

                    if ("" !== $price) {
                        $option["price"] = floatval($price);
                    }
                }

                if (!isset($option["stock"])) {
                    $qty = $this->extract_product_field($option_item, [
                        "stock",
                        "qty",
                        "stock_qty",
                        "inventory",
                        "option_qty",
                    ]);

                    if ("" !== $qty) {
                        $option["stock"] = intval($qty);
                    }
                }

                $options[] = $option;
            }

            if (!empty($options)) {
                return $options;
            }
        }

        return [];
    }

    /**
     * Enrich product data with option quantities from option qty endpoint if available.
     *
     * @param array $products Normalized products.
     *
     * @return array
     */
    private function maybe_enrich_with_option_quantities($products)
    {
        $option_ids = [];

        foreach ($products as $product) {
            if (empty($product["options"]) || !is_array($product["options"])) {
                continue;
            }

            foreach ($product["options"] as $option) {
                if (empty($option["option_id"])) {
                    continue;
                }

                $option_ids[] = $option["option_id"];
            }
        }

        $option_ids = array_values(array_filter(array_unique($option_ids)));

        if (empty($option_ids)) {
            return $products;
        }

        $quantities = $this->get_option_quantities($option_ids);

        if (empty($quantities)) {
            return $products;
        }

        foreach ($products as $index => $product) {
            if (empty($product["options"]) || !is_array($product["options"])) {
                continue;
            }

            $total_stock = 0;

            foreach ($product["options"] as $option_index => $option) {
                if (empty($option["option_id"])) {
                    continue;
                }

                $option_id = $option["option_id"];
                if (isset($quantities[$option_id])) {
                    $products[$index]["options"][$option_index]["stock"] =
                        $quantities[$option_id];
                    $total_stock += intval($quantities[$option_id]);
                }
            }

            if (empty($product["stock"]) && $total_stock > 0) {
                $products[$index]["stock"] = $total_stock;
            }
        }

        return $products;
    }

    /**
     * Fetch option quantities from Silverbene API.
     *
     * @param array $option_ids List of option IDs.
     *
     * @return array Map of option_id => qty.
     */
    public function get_option_quantities($option_ids)
    {
        if (empty($option_ids)) {
            return [];
        }

        $token = $this->get_setting("api_key", "");
        if (empty($token)) {
            return [];
        }

        $endpoint = $this->get_setting(
            "option_qty_endpoint",
            "/dropshipping/option_qty",
        );

        $option_ids = array_map("strval", $option_ids);
        $option_ids = array_map("trim", $option_ids);

        $response = $this->request("GET", $endpoint, [
            "query" => [
                "token" => $token,
                "option_id" => implode(",", array_filter($option_ids)),
            ],
        ]);

        if (is_wp_error($response)) {
            $this->log_error(
                "Option quantity request returned WP_Error",
                ["error_message" => $response->get_error_message()],
            );
            return [];
        }

        if (empty($response)) {
            return [];
        }

        if (is_array($response) && isset($response["code"]) && 0 !== intval($response["code"])) {
            $this->log_error(
                "Option quantity request returned non zero status code",
                $response,
            );
            return [];
        }

        if (!is_array($response)) {
            return [];
        }

        $data = $this->unwrap_data_container($response);
        if (empty($data) || !is_array($data)) {
            return [];
        }

        $quantities = [];
        foreach ($data as $item) {
            if (!is_array($item)) {
                continue;
            }

            $option_id = $this->extract_product_field($item, [
                "option_id",
                "id",
                "variant_id",
            ]);
            if (empty($option_id)) {
                continue;
            }

            $qty = $this->extract_product_field($item, [
                "qty",
                "stock",
                "stock_qty",
                "inventory",
                "option_qty",
            ]);

            if ("" === $qty || null === $qty) {
                continue;
            }

            $quantities[$option_id] = intval($qty);
        }

        return $quantities;
    }

    /**
     * Simple logger helper.
     *
     * @param string $message Log message.
     * @param array  $context Additional context.
     */
    private function log_error($message, $context = [])
    {
        if (function_exists("wc_get_logger")) {
            $logger = wc_get_logger();
            $logger->error($message . " - " . wp_json_encode($context), [
                "source" => "silverbene-api",
            ]);
        }
    }
}
