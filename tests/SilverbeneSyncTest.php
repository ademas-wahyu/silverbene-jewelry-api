<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../includes/class-silverbene-sync.php';

class SilverbeneSyncTest extends TestCase
{
    /** @var Silverbene_Sync */
    private $sync;

    protected function setUp(): void
    {
        $client = new Silverbene_API_Client(['api_key' => 'dummy']);
        $this->sync = new Silverbene_Sync($client);
    }

    private function invokePrepareColorVariations($product_data, $settings = [], $fallback_price = null)
    {
        $method = new ReflectionMethod(Silverbene_Sync::class, 'prepare_color_variations');
        $method->setAccessible(true);

        return $method->invoke($this->sync, $product_data, $settings, $fallback_price);
    }

    public function testUsesOption1ValueForColorAttribute()
    {
        $product_data = [
            'sku' => 'PARENT-100',
            'options' => [
                [
                    'Option1 Name' => 'Color',
                    'Option1 Value' => 'Rose Gold 14K',
                    'sku' => 'RG-14K-001',
                    'price' => '25.50',
                    'stock' => 3,
                ],
            ],
        ];

        $variations = $this->invokePrepareColorVariations($product_data, []);

        $this->assertCount(1, $variations);
        $this->assertSame('Rose Gold', $variations[0]['attribute_value']);
        $this->assertSame('Rose Gold', $variations[0]['color']);
    }

    public function testFallsBackToSkuWhenColorMissing()
    {
        $product_data = [
            'sku' => 'PARENT-200',
            'options' => [
                [
                    'Option1 Name' => 'Size',
                    'Option1 Value' => '7',
                    'sku' => 'SIZE-7-001',
                    'price' => '30',
                    'stock' => 2,
                ],
            ],
        ];

        $variations = $this->invokePrepareColorVariations($product_data, []);

        $this->assertCount(1, $variations);
        $this->assertSame('SIZE-7-001', $variations[0]['attribute_value']);
        $this->assertSame('', $variations[0]['color']);
    }
}
