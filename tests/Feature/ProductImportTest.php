<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Location;
use App\Models\LocationProduct;
use App\Models\Product;
use App\Models\Role;
use App\Models\User;
use App\Services\ProductImportService;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class ProductImportTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected string $adminToken;

    protected Business $business;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PlanSeeder::class);

        $this->admin = User::factory()->create(['is_active' => true]);
        $this->adminToken = $this->admin->createToken('admin')->plainTextToken;

        $this->business = Business::factory()->create([
            'owner_id' => $this->admin->id,
            'currency' => 'UGX',
            'status' => 'active',
        ]);
        $this->admin->business_id = $this->business->id;
        $this->admin->save();

        $adminRole = Role::create([
            'business_id' => $this->business->id,
            'name' => 'Admin',
            'slug' => 'admin',
            'permissions' => [
                'inventory.view' => true,
                'inventory.create' => true,
            ],
        ]);
        $this->admin->role_id = $adminRole->id;
        $this->admin->modules = ['inventory'];
        $this->admin->save();
    }

    public function test_import_handles_large_batch(): void
    {
        $path = $this->makeImportFile(150);

        $results = app(ProductImportService::class)->import($this->business->id, $path);

        $this->assertSame(150, $results['imported']);
        $this->assertSame(150, $results['total_rows']);
        $this->assertSame([], $results['errors']);
        $this->assertSame(150, Product::where('business_id', $this->business->id)->count());

        @unlink($path);
    }

    public function test_import_maps_products_to_logged_in_user_branch_by_default(): void
    {
        $main = Location::create(['business_id' => $this->business->id, 'name' => 'Main', 'is_default' => true, 'is_active' => true]);
        $annex = Location::create(['business_id' => $this->business->id, 'name' => 'Annex', 'is_default' => false, 'is_active' => true]);
        $this->admin->location_id = $annex->id;
        $this->admin->save();

        $path = $this->makeStockImportFile('Mapped-1', 5);
        $results = app(ProductImportService::class)->import($this->business->id, $path, $this->admin->id);

        $this->assertSame(1, $results['imported']);
        $product = Product::where('business_id', $this->business->id)->firstOrFail();
        $this->assertSame($annex->id, LocationProduct::where('product_id', $product->id)->value('location_id'));

        @unlink($path);
    }

    public function test_import_honours_branch_chosen_in_upload_modal(): void
    {
        $main = Location::create(['business_id' => $this->business->id, 'name' => 'Main', 'is_default' => true, 'is_active' => true]);
        $annex = Location::create(['business_id' => $this->business->id, 'name' => 'Annex', 'is_default' => false, 'is_active' => true]);
        $this->admin->location_id = $main->id;
        $this->admin->save();

        $path = $this->makeStockImportFile('Mapped-2', 3);
        $results = app(ProductImportService::class)->import($this->business->id, $path, $this->admin->id, $annex->id);

        $this->assertSame(1, $results['imported']);
        $product = Product::where('business_id', $this->business->id)->firstOrFail();
        $this->assertSame($annex->id, LocationProduct::where('product_id', $product->id)->value('location_id'));

        @unlink($path);
    }

    public function test_import_defaults_blank_low_stock_threshold(): void
    {
        $path = $this->makeImportFileWithBlankThreshold('Blank-Threshold', 8);

        $results = app(ProductImportService::class)->import($this->business->id, $path);

        $this->assertSame(1, $results['imported']);
        $this->assertSame([], $results['errors']);
        $product = Product::where('business_id', $this->business->id)->firstOrFail();
        $this->assertSame(5, (int) $product->low_stock_threshold);

        @unlink($path);
    }

    protected function makeStockImportFile(string $name, int $qty): string
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->fromArray([
            ['Name*', 'Unit', 'Category', 'Unit Price*', 'Wholesale Price', 'Cost Price', 'Stock Qty', 'Low Stock Threshold', 'SKU', 'Barcode', 'Tax %', 'Tax Class', 'Description'],
        ]);
        $sheet->fromArray([[$name, 'Pieces', '', '1000', '', '', $qty, '5', 'SKU-MAP', '', '18', 'standard', '']], null, 'A2');

        $path = tempnam(sys_get_temp_dir(), 'product-import-') . '.xlsx';
        (new Xlsx($spreadsheet))->save($path);

        return $path;
    }

    protected function makeImportFileWithBlankThreshold(string $name, int $qty): string
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->fromArray([
            ['Name*', 'Unit', 'Category', 'Unit Price*', 'Wholesale Price', 'Cost Price', 'Stock Qty', 'Low Stock Threshold', 'SKU', 'Barcode', 'Tax %', 'Tax Class', 'Description'],
        ]);
        $sheet->fromArray([[$name, 'Pieces', '', '1000', '', '', $qty, '', 'SKU-BLANK', '', '18', 'standard', '']], null, 'A2');

        $path = tempnam(sys_get_temp_dir(), 'product-import-') . '.xlsx';
        (new Xlsx($spreadsheet))->save($path);

        return $path;
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
                ["Bulk Product {$i}", 'Pieces', '', '1000', '', '', '0', '5', "SKU-{$i}", '', '18', 'standard', ''],
            ], null, 'A' . ($i + 1));
        }

        $path = tempnam(sys_get_temp_dir(), 'product-import-') . '.xlsx';
        (new Xlsx($spreadsheet))->save($path);

        return $path;
    }
}
