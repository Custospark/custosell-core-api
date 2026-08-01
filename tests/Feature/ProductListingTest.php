<?php

namespace Tests\Feature;

use App\Models\{Business, Product, User};
use App\Services\ProductImportService;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class ProductListingTest extends TestCase
{
    use RefreshDatabase;

    protected User $owner;

    protected Business $business;

    protected string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PlanSeeder::class);

        $this->owner = User::factory()->create(['is_active' => true]);
        $this->token = $this->owner->createToken('owner')->plainTextToken;

        $this->business = Business::factory()->create([
            'owner_id' => $this->owner->id,
            'status' => 'active',
        ]);
        $this->owner->business_id = $this->business->id;
        $this->owner->save();
        $this->ensureSubscription($this->business->id);
    }

    protected function asOwner(string $method, string $uri, array $data = [])
    {
        $this->app['auth']->forgetGuards();

        return $this->withHeader('Authorization', "Bearer {$this->token}")->json($method, $uri, $data);
    }

    protected function makeProduct(array $overrides = []): Product
    {
        return Product::factory()->create(array_merge([
            'business_id' => $this->business->id,
            'unit_price' => 1000,
            'is_active' => true,
            'listed_for_supply' => false,
            'listed_for_storefront' => false,
        ], $overrides));
    }

    public function test_new_products_default_to_listed(): void
    {
        $response = $this->asOwner('POST', '/api/v1/products', [
            'name' => 'Default Listed Item',
            'unit_price' => 5000,
            'stock_quantity' => 3,
        ]);

        $response->assertStatus(201);
        $this->assertTrue($response->json('data.listed_for_supply'));
        $this->assertTrue($response->json('data.listed_for_storefront'));
        $this->assertDatabaseHas('products', [
            'name' => 'Default Listed Item',
            'listed_for_supply' => true,
            'listed_for_storefront' => true,
        ]);
    }

    public function test_imported_products_default_to_listed(): void
    {
        $path = $this->makeImportFile(3);

        $results = app(ProductImportService::class)->import($this->business->id, $path);

        $this->assertSame(3, $results['imported']);
        $this->assertSame([], $results['errors']);
        $this->assertSame(3, Product::where('business_id', $this->business->id)
            ->where('listed_for_supply', true)
            ->where('listed_for_storefront', true)
            ->count());

        @unlink($path);
    }

    public function test_bulk_list_supply_falls_back_to_wholesale_price(): void
    {
        $a = $this->makeProduct(['wholesale_price' => 800]);
        $b = $this->makeProduct(['wholesale_price' => 900]);
        $service = $this->makeProduct(['type' => Product::TYPE_SERVICE, 'stock_quantity' => 0]);

        $response = $this->asOwner('POST', '/api/v1/products/bulk-listing', [
            'ids' => [$a->id, $b->id, $service->id],
            'channel' => 'supply',
            'listed' => true,
        ]);

        $response->assertStatus(200)->assertJson(['updated' => 3]);

        $this->assertSame(800, (int) Product::find($a->id)->supply_price);
        $this->assertSame(900, (int) Product::find($b->id)->supply_price);
        $this->assertNotNull(Product::find($a->id)->listed_at);
        $this->assertTrue((bool) Product::find($a->id)->listed_for_supply);

        $unlist = $this->asOwner('POST', '/api/v1/products/bulk-listing', [
            'ids' => [$a->id, $b->id],
            'channel' => 'supply',
            'listed' => false,
        ]);
        $unlist->assertStatus(200)->assertJson(['updated' => 2]);
        $this->assertFalse((bool) Product::find($a->id)->listed_for_supply);
        $this->assertNull(Product::find($a->id)->listed_at);
    }

    public function test_bulk_list_storefront_sets_timestamp(): void
    {
        $a = $this->makeProduct();

        $response = $this->asOwner('POST', '/api/v1/products/bulk-listing', [
            'ids' => [$a->id],
            'channel' => 'storefront',
            'listed' => true,
        ]);

        $response->assertStatus(200)->assertJson(['updated' => 1]);
        $this->assertTrue((bool) Product::find($a->id)->listed_for_storefront);
        $this->assertNotNull(Product::find($a->id)->storefront_listed_at);

        $this->asOwner('POST', '/api/v1/products/bulk-listing', [
            'ids' => [$a->id],
            'channel' => 'storefront',
            'listed' => false,
        ])->assertStatus(200);

        $this->assertFalse((bool) Product::find($a->id)->listed_for_storefront);
        $this->assertNull(Product::find($a->id)->storefront_listed_at);
    }

    public function test_bulk_listing_is_scoped_to_own_business(): void
    {
        $otherOwner = User::factory()->create(['is_active' => true]);
        $other = Business::factory()->create([
            'owner_id' => $otherOwner->id,
            'status' => 'active',
        ]);
        $otherOwner->business_id = $other->id;
        $otherOwner->save();
        $this->ensureSubscription($other->id);
        $otherToken = $otherOwner->createToken('other')->plainTextToken;

        $mine = $this->makeProduct();
        $theirs = Product::factory()->create([
            'business_id' => $other->id,
            'unit_price' => 500,
            'is_active' => true,
            'listed_for_supply' => false,
        ]);

        $this->app['auth']->forgetGuards();
        $response = $this->withHeader('Authorization', "Bearer {$otherToken}")
            ->postJson('/api/v1/products/bulk-listing', [
                'ids' => [$mine->id, $theirs->id],
                'channel' => 'storefront',
                'listed' => true,
            ]);

        $response->assertStatus(200)->assertJson(['updated' => 1]);
        $this->assertTrue((bool) Product::find($theirs->id)->listed_for_storefront);
        $this->assertFalse((bool) Product::find($mine->id)->listed_for_storefront);
    }

    public function test_bulk_listing_validation(): void
    {
        $product = $this->makeProduct();

        $this->asOwner('POST', '/api/v1/products/bulk-listing', [
            'ids' => [],
            'channel' => 'supply',
            'listed' => true,
        ])->assertStatus(422);

        $this->asOwner('POST', '/api/v1/products/bulk-listing', [
            'ids' => [$product->id],
            'channel' => 'webhooks',
            'listed' => true,
        ])->assertStatus(422);

        $this->asOwner('POST', '/api/v1/products/bulk-listing', [
            'ids' => [$product->id],
            'channel' => 'storefront',
        ])->assertStatus(422);
    }

    protected function makeImportFile(int $rows): string
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->fromArray([
            ['Name*', 'Unit', 'Category', 'Unit Price*', 'Wholesale Price', 'Cost Price', 'Stock Qty', 'Low Stock Threshold', 'SKU', 'Barcode', 'Tax %', 'Tax Class', 'Description'],
        ]);

        for ($i = 1; $i <= $rows; $i++) {
            $sheet->fromArray([
                ["Listed Item {$i}", 'Pieces', '', '1000', '', '', '0', '5', "SKU-LST-{$i}", '', '18', 'standard', ''],
            ], null, 'A' . ($i + 1));
        }

        $path = tempnam(sys_get_temp_dir(), 'product-import-') . '.xlsx';
        (new Xlsx($spreadsheet))->save($path);

        return $path;
    }
}
