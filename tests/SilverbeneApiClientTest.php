<?php

use PHPUnit\Framework\TestCase;

class TestSilverbeneApiClient extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->resetGlobals();
    }

    protected function tearDown(): void
    {
        $this->resetGlobals();
        parent::tearDown();
    }

    private function resetGlobals(): void
    {
        $GLOBALS['__wp_options'] = [];
        $GLOBALS['__wp_remote_request_callback'] = null;
    }

    private function withSettings(array $overrides): void
    {
        $defaults = [
            'api_url' => 'https://api.example.com',
            'api_key' => 'token-123',
            'products_endpoint' => '/products',
        ];
        update_option('silverbene_api_settings', array_merge($defaults, $overrides));
    }

    public function test_get_products_returns_normalized_products_from_success_response(): void
    {
        $this->withSettings([]);

        $payload = [
            'code' => 0,
            'data' => [
                [
                    'sku' => 'SKU-001',
                    'name' => 'Ring Emas',
                ],
                [
                    'sku' => 'SKU-002',
                    'name' => 'Kalung Perak',
                ],
            ],
        ];

        $GLOBALS['__wp_remote_request_callback'] = function ($url, $args) use ($payload) {
            return [
                'response' => ['code' => 200],
                'body' => json_encode($payload),
            ];
        };

        $client = new Silverbene_API_Client();
        $products = $client->get_products();

        $this->assertCount(2, $products);
        $this->assertSame(['SKU-001', 'SKU-002'], array_column($products, 'sku'));
        $this->assertSame(['Ring Emas', 'Kalung Perak'], array_column($products, 'name'));
    }

    public function test_get_products_returns_empty_array_when_token_missing(): void
    {
        $this->withSettings(['api_key' => '']);

        $client = new Silverbene_API_Client();
        $products = $client->get_products();

        $this->assertSame([], $products);
    }

    public function test_get_products_returns_empty_array_on_http_error(): void
    {
        $this->withSettings([]);

        $GLOBALS['__wp_remote_request_callback'] = function ($url, $args) {
            return [
                'response' => ['code' => 500],
                'body' => json_encode(['code' => 0, 'data' => []]),
            ];
        };

        $client = new Silverbene_API_Client();
        $products = $client->get_products();

        $this->assertSame([], $products);
    }

    public function test_get_products_returns_empty_array_when_payload_has_error_code(): void
    {
        $this->withSettings([]);

        $GLOBALS['__wp_remote_request_callback'] = function ($url, $args) {
            return [
                'response' => ['code' => 200],
                'body' => json_encode([
                    'code' => 1,
                    'message' => 'Invalid request',
                ]),
            ];
        };

        $client = new Silverbene_API_Client();
        $products = $client->get_products();

        $this->assertSame([], $products);
    }
}
