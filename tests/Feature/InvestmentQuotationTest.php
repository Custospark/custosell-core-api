<?php

declare(strict_types=1);

namespace Tests\Feature;

use Database\Seeders\InvestmentCatalogSeeder;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InvestmentQuotationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PlanSeeder::class);
        $this->seed(InvestmentCatalogSeeder::class);
    }

    public function test_packages_lists_tiers_with_kit_and_maintenance(): void
    {
        $response = $this->getJson('/api/v1/quotations/packages')->assertOk();

        $slugs = collect($response->json('data'))->pluck('slug')->all();
        $this->assertContains('essential', $slugs);

        $essential = collect($response->json('data'))->firstWhere('slug', 'essential');
        $this->assertSame(150000.0, (float) $essential['maintenance_fee_ugx']);
        $this->assertNotEmpty($essential['bundle']);
    }

    public function test_items_endpoint_lists_active_catalog_with_specs(): void
    {
        $response = $this->getJson('/api/v1/quotations/items')->assertOk();

        $codes = collect($response->json('data'))->pluck('code')->all();
        $this->assertContains('thermal-printer-80', $codes);
        $first = $response->json('data.0');
        $this->assertArrayHasKey('specs', $first);
        $this->assertArrayHasKey('price_ugx', $first);
    }

    public function test_estimate_scales_bundle_by_drivers_and_totals(): void
    {
        $response = $this->postJson('/api/v1/quotations/estimate', [
            'plan' => 'essential',
            'kit' => 'full',
            'drivers' => ['tills' => 2, 'staff' => 3, 'branches' => 1],
        ])->assertOk();

        $data = $response->json('data');
        $lines = collect($data['hardware_lines']);

        // Per-till lines multiply: thermal printer base 1 x 2 tills.
        $this->assertSame(2, (int) $lines->firstWhere('code', 'thermal-printer-80')['qty']);
        // Flat lines stay put.
        $this->assertSame(1, (int) $lines->firstWhere('code', 'desktop-i5')['qty']);

        $expectedHardware = $lines->sum(fn ($l) => (float) $l['line_total_ugx']);
        $this->assertEqualsWithDelta($expectedHardware, (float) $data['hardware_total_ugx'], 0.01);

        $expectedGrand = (float) $data['hardware_total_ugx']
            + (float) $data['subscription_first_year_ugx']
            + (float) $data['onboarding_ugx']
            + (float) $data['maintenance_annual_ugx'];
        $this->assertEqualsWithDelta($expectedGrand, (float) $data['grand_total_ugx'], 0.01);
        $this->assertGreaterThan(0, (float) $data['grand_total_usd']);
        $this->assertEqualsWithDelta(
            (float) $data['hardware_total_ugx'] + (float) $data['onboarding_ugx'],
            (float) $data['one_time_ugx'],
            0.01,
        );
        $this->assertEqualsWithDelta(
            (float) $data['subscription_first_year_ugx'] + (float) $data['maintenance_annual_ugx'],
            (float) $data['annual_recurring_ugx'],
            0.01,
        );
    }

    public function test_default_kit_only_includes_custosell_services(): void
    {
        $response = $this->postJson('/api/v1/quotations/estimate', [
            'plan' => 'essential',
            'drivers' => ['tills' => 2, 'staff' => 3, 'branches' => 1],
        ])->assertOk();

        $codes = collect($response->json('data.hardware_lines'))->pluck('code')->all();
        $this->assertContains('setup-service', $codes);
        $this->assertNotContains('thermal-printer-80', $codes);
        $this->assertNotContains('desktop-i5', $codes);
    }

    public function test_item_rows_set_absolute_quantities_and_remove_at_zero(): void
    {
        $response = $this->postJson('/api/v1/quotations/estimate', [
            'plan' => 'essential',
            'kit' => 'full',
            'drivers' => ['tills' => 2, 'staff' => 1, 'branches' => 1],
            'items' => [
                ['code' => 'thermal-printer-80', 'qty' => 5],
                ['code' => 'desktop-i5', 'qty' => 0],
            ],
        ])->assertOk();

        $lines = collect($response->json('data.hardware_lines'));
        $this->assertSame(5, (int) $lines->firstWhere('code', 'thermal-printer-80')['qty']);
        $this->assertNull($lines->firstWhere('code', 'desktop-i5'));
    }

    public function test_custom_lines_discount_vat_and_fields_flow_through(): void
    {
        $response = $this->postJson('/api/v1/quotations/estimate', [
            'plan' => 'essential',
            'drivers' => ['tills' => 1, 'staff' => 1, 'branches' => 1],
            'custom_lines' => [['label' => 'Upcountry travel', 'amount_ugx' => 200000]],
            'custom_fields' => [['label' => 'Valid until', 'value' => '30 Oct 2026']],
            'discount_percent' => 10,
            'vat_percent' => 18,
        ])->assertOk();

        $data = $response->json('data');
        $this->assertSame('Upcountry travel', $data['custom_lines'][0]['label']);
        $this->assertSame('30 Oct 2026', $data['custom_fields'][0]['value']);
        $this->assertGreaterThan(0, (float) $data['discount_ugx']);
        $this->assertGreaterThan(0, (float) $data['vat_ugx']);

        $expectedGrand = (float) $data['hardware_total_ugx']
            - (float) $data['discount_ugx']
            + (float) $data['custom_total_ugx']
            + (float) $data['subscription_first_year_ugx']
            + (float) $data['onboarding_ugx']
            + (float) $data['maintenance_annual_ugx']
            + (float) $data['vat_ugx'];
        $this->assertEqualsWithDelta($expectedGrand, (float) $data['grand_total_ugx'], 0.01);
    }

    public function test_estimate_rejects_unknown_plan_and_bad_input(): void
    {
        $this->postJson('/api/v1/quotations/estimate', ['plan' => 'nope'])->assertNotFound();
        $this->postJson('/api/v1/quotations/estimate', [
            'plan' => 'essential',
            'drivers' => ['tills' => 0],
        ])->assertStatus(422);
    }

    public function test_download_returns_pdf(): void
    {
        $response = $this->postJson('/api/v1/quotations/download', [
            'plan' => 'essential',
            'customer_name' => 'Test Shop',
        ]);

        $response->assertOk();
        $this->assertStringContainsString('application/pdf', (string) $response->headers->get('Content-Type'));
        $this->assertNotEmpty($response->getContent());
    }
}
