<?php

namespace Tests\Unit;

use App\Models\Business;
use App\Models\Product;
use App\Support\TaxEngine;
use Tests\TestCase;

class TaxEngineTest extends TestCase
{
    public function test_vat_inclusive_standard_rate(): void
    {
        $business = new Business([
            'tax_regime' => TaxEngine::REGIME_VAT_REGISTERED,
            'default_vat_rate' => 18,
            'prices_include_tax' => true,
        ]);

        $product = new Product([
            'tax_class' => TaxEngine::CLASS_STANDARD,
            'tax_percentage' => 18,
        ]);

        $engine = new TaxEngine();
        $line = $engine->computeLine($business, $product, 1, 118000);

        $this->assertEquals(100000.0, $line['net']);
        $this->assertEquals(18000.0, $line['tax']);
        $this->assertEquals(118000.0, $line['gross']);
    }

    public function test_exempt_products_have_zero_tax(): void
    {
        $business = new Business([
            'tax_regime' => TaxEngine::REGIME_VAT_REGISTERED,
            'default_vat_rate' => 18,
            'prices_include_tax' => true,
        ]);

        $product = new Product([
            'tax_class' => TaxEngine::CLASS_EXEMPT,
            'tax_percentage' => 18,
        ]);

        $engine = new TaxEngine();
        $line = $engine->computeLine($business, $product, 2, 50000);

        $this->assertEquals(0.0, $line['tax']);
        $this->assertEquals(100000.0, $line['gross']);
    }

    public function test_none_regime_has_zero_tax(): void
    {
        $business = new Business([
            'tax_regime' => TaxEngine::REGIME_NONE,
            'prices_include_tax' => true,
        ]);

        $product = new Product([
            'tax_class' => TaxEngine::CLASS_STANDARD,
            'tax_percentage' => 18,
        ]);

        $engine = new TaxEngine();
        $result = $engine->computeSale($business, [[
            'product' => $product,
            'quantity' => 1,
            'unit_price' => 10000,
        ]]);

        $this->assertEquals(0.0, $result['tax_total']);
        $this->assertEquals(10000.0, $result['total_amount']);
    }
}
