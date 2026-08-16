<?php

namespace Tests\Unit\Services;

use App\Models\Business;
use App\Models\ChartOfAccount;
use App\Models\User;
use App\Services\ChartOfAccountImportExportService;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class ChartOfAccountImportExportServiceTest extends TestCase
{
    use RefreshDatabase;

    protected Business $business;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PlanSeeder::class);

        $user = User::factory()->create(['is_active' => true]);
        $this->business = Business::factory()->create([
            'owner_id' => $user->id,
            'currency' => 'UGX',
            'status' => 'active',
        ]);
    }

    protected function service(): ChartOfAccountImportExportService
    {
        return app(ChartOfAccountImportExportService::class);
    }

    protected function writeTempSpreadsheet(array $rows): string
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setCellValue('A1', 'Account Code');
        $sheet->setCellValue('B1', 'Account Name');
        $sheet->setCellValue('C1', 'Account Type');
        $sheet->setCellValue('D1', 'Normal Balance');
        foreach ($rows as $i => $row) {
            $r = $i + 2;
            $sheet->setCellValueExplicit('A'.$r, $row[0], \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
            $sheet->setCellValue('B'.$r, $row[1]);
            $sheet->setCellValue('C'.$r, $row[2]);
            $sheet->setCellValue('D'.$r, $row[3]);
        }
        $path = tempnam(sys_get_temp_dir(), 'coa').'.xlsx';
        (new Xlsx($spreadsheet))->save($path);

        return $path;
    }

    public function test_import_creates_and_updates_accounts_by_code(): void
    {
        $file = $this->writeTempSpreadsheet([
            ['1111', 'New Asset', 'Asset', 'debit'],
            ['4200', 'New Service Revenue', 'Revenue', 'credit'],
        ]);

        $result = $this->service()->import($this->business->id, $file);

        $this->assertSame(2, $result['imported']);
        $this->assertSame([], $result['errors']);

        $this->assertDatabaseHas('chart_of_accounts', [
            'business_id' => $this->business->id,
            'code' => '1111',
            'name' => 'New Asset',
        ]);
        $this->assertDatabaseHas('chart_of_accounts', [
            'business_id' => $this->business->id,
            'code' => '4200',
            'name' => 'New Service Revenue',
        ]);

        // Re-import with a changed name updates by code (idempotent create/update).
        $file2 = $this->writeTempSpreadsheet([
            ['1111', 'Renamed Asset', 'Asset', 'debit'],
        ]);
        $result2 = $this->service()->import($this->business->id, $file2);
        $this->assertSame(1, $result2['imported']);
        $this->assertSame('Renamed Asset', ChartOfAccount::where('business_id', $this->business->id)->where('code', '1111')->value('name'));
        $this->assertSame(1, ChartOfAccount::where('business_id', $this->business->id)->where('code', '1111')->count());
    }

    public function test_import_reports_per_row_errors(): void
    {
        $file = $this->writeTempSpreadsheet([
            ['', 'No code', 'Asset', 'debit'],
            ['2222', 'Bad type', 'Whatever', 'credit'],
            ['3333', 'Bad balance', 'Expense', 'sideways'],
            ['4444', 'Valid', 'Liability', 'credit'],
        ]);

        $result = $this->service()->import($this->business->id, $file);

        $this->assertSame(1, $result['imported']);
        $this->assertSame(3, count($result['errors']));
        $this->assertArrayHasKey('code', $result['errors'][0]['errors']);
        $this->assertArrayHasKey('type', $result['errors'][1]['errors']);
        $this->assertArrayHasKey('normal_balance', $result['errors'][2]['errors']);
    }

    public function test_export_returns_all_accounts(): void
    {
        ChartOfAccount::create([
            'business_id' => $this->business->id,
            'code' => '9001',
            'name' => 'Export Me',
            'type_id' => 4, // Revenue
            'normal_balance' => 'credit',
            'is_active' => true,
        ]);

        $spreadsheet = $this->service()->export($this->business->id);
        $sheet = $spreadsheet->getActiveSheet();
        $rows = $sheet->toArray(null, true, true, true);

        // Header + the one account row.
        $this->assertGreaterThanOrEqual(2, count($rows));
        $this->assertSame('9001', (string) $rows[2]['A']);
        $this->assertSame('Export Me', $rows[2]['B']);
        $this->assertSame('Revenue', $rows[2]['C']);
        $this->assertSame('credit', $rows[2]['D']);
    }
}
