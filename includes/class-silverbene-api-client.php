<?php
/**
 * Silverbene API client helper.
 */
class Silverbene_API_Client {
    /**
     * Plugin settings for the API integration.
     *
     * @var array
     */
    private $settings = array();

    /**
     * Constructor.
     *
     * @param array $settings Optional settings array. If omitted they will be lazily loaded.
     */
    public function __construct( $settings = array() ) {
        $this->settings = $this->parse_settings( $settings );
    }

    /**
     * Retrieve products from the Silverbene API.
     *
     * @param array $args Optional query arguments for pagination/filtering.
     * @return array
     */
    public function get_products( $args = array() ) {
        $endpoint = $this->get_setting( 'products_endpoint', '/products' );
        $response = $this->request( 'GET', $endpoint, array(
            'query' => $args,
        ) );

        if ( empty( $response ) ) {
            return array();
        }

        // The API might return data in different keys depending on the response structure.
        if ( isset( $response['data'] ) ) {
            if ( isset( $response['data']['items'] ) && is_array( $response['data']['items'] ) ) {
                return $response['data']['items'];
            }

            if ( isset( $response['data']['list'] ) && is_array( $response['data']['list'] ) ) {
                return $response['data']['list'];
            }

            if ( is_array( $response['data'] ) ) {
                return $response['data'];
            }
        }

        if ( isset( $response['items'] ) && is_array( $response['items'] ) ) {
            return $response['items'];
        }

        if ( isset( $response['products'] ) && is_array( $response['products'] ) ) {
            return $response['products'];
        }

        if ( is_array( $response ) ) {
            return $response;
        }

        return array();
    }

    /**
     * Create an order on Silverbene.
     *
     * @param array $order_data Formatted order payload.
     * @return array|
     */
    public function create_order( $order_data ) {
        $endpoint = $this->get_setting( 'orders_endpoint', '/orders' );
        return $this->request( 'POST', $endpoint, array(
            'body' => wp_json_encode( $order_data ),
            'headers' => array(
                'Content-Type' => 'application/json',
            ),
        ) );
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
    public function request( $method, $endpoint, $args = array() ) {
        $settings = $this->get_settings();
        $base_url = untrailingslashit( $this->get_setting( 'api_url', 'https://api.silverbene.com/v1' ) );
        $endpoint = '/' . ltrim( $endpoint, '/' );

        $request_args = array(
            'method'  => strtoupper( $method ),
            'timeout' => 30,
            'headers' => $this->prepare_headers( isset( $args['headers'] ) ? $args['headers'] : array() ),
        );

        if ( ! empty( $args['body'] ) ) {
            $request_args['body'] = $args['body'];
        }

        if ( ! empty( $args['query'] ) && is_array( $args['query'] ) ) {
            $endpoint = add_query_arg( $args['query'], $endpoint );
        }

        $response = wp_remote_request( $base_url . $endpoint, $request_args );

        if ( is_wp_error( $response ) ) {
            $this->log_error( 'API request failed', array(
                'endpoint' => $endpoint,
                'error'    => $response->get_error_message(),
            ) );

            return null;
        }

        $status_code = wp_remote_retrieve_response_code( $response );
        $body        = wp_remote_retrieve_body( $response );
        $decoded     = null;

        if ( ! empty( $body ) ) {
            $decoded = json_decode( $body, true );
        }

        if ( $status_code < 200 || $status_code >= 300 ) {
            $this->log_error( 'API request returned a non-success status code', array(
                'endpoint'    => $endpoint,
                'status_code' => $status_code,
                'body'        => $decoded,
            ) );

            return null;
        }

        return $decoded;
    }

    /**
     * Retrieve the full settings array.
     *
     * @return array
     */
    public function get_settings() {
        if ( empty( $this->settings ) ) {
            $this->settings = $this->parse_settings( get_option( 'silverbene_api_settings', array() ) );
        }

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
    public function get_setting( $key, $default = '' ) {
        $settings = $this->get_settings();
        return isset( $settings[ $key ] ) && '' !== $settings[ $key ] ? $settings[ $key ] : $default;
    }

    /**
     * Update cached settings with new values (e.g. after saving options).
     */
    public function refresh_settings() {
        $this->settings = $this->parse_settings( get_option( 'silverbene_api_settings', array() ) );
    }

    /**
     * Prepare request headers.
     *
     * @param array $headers Optional headers to merge.
     * @return array
     */
    private function prepare_headers( $headers = array() ) {
        $headers = wp_parse_args( $headers, array() );
        $api_key = $this->get_setting( 'api_key', '' );
        $api_secret = $this->get_setting( 'api_secret', '' );

        if ( ! empty( $api_key ) ) {
            $headers['Authorization'] = 'Bearer ' . $api_key;
            $headers['X-API-KEY']     = $api_key;
        }

        if ( ! empty( $api_secret ) ) {
            $headers['X-API-SECRET'] = $api_secret;
        }

        $headers['Accept'] = 'application/json';

        return $headers;
    }

    /**
     * Parse settings array with defaults.
     *
     * @param array $settings Raw settings.
     * @return array
     */
    private function parse_settings( $settings ) {
        return wp_parse_args( $settings, array(
            'api_url'            => 'https://api.silverbene.com/v1',
            'api_key'            => '',
            'api_secret'         => '',
            'products_endpoint'  => '/products',
            'orders_endpoint'    => '/orders',
            'sync_enabled'       => false,
            'sync_interval'      => 'hourly',
            'default_category'   => '',
            'price_markup_type'  => 'percentage',
            'price_markup_value' => 0,
        ) );
    }

    /**
     * Simple logger helper.
     *
     * @param string $message Log message.
     * @param array  $context Additional context.
     */
    private function log_error( $message, $context = array() ) {
        if ( function_exists( 'wc_get_logger' ) ) {
            $logger = wc_get_logger();
            $logger->error( $message . ' - ' . wp_json_encode( $context ), array( 'source' => 'silverbene-api' ) );
        }
    }
}
