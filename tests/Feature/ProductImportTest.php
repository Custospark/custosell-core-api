<?php

namespace Tests\Feature;

use App\Models\Business;
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
