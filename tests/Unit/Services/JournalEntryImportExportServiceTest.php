<?php

namespace Tests\Unit\Services;

use App\Models\AccountType;
use App\Models\Business;
use App\Models\ChartOfAccount;
use App\Models\JournalEntry;
use App\Models\AccountingPeriod;
use App\Models\User;
use App\Services\JournalEntryImportExportService;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class JournalEntryImportExportServiceTest extends TestCase
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

        // Mini chart of accounts.
        AccountType::firstOrCreate(['name' => 'Asset'], ['normal_balance' => 'debit']);
        AccountType::firstOrCreate(['name' => 'Revenue'], ['normal_balance' => 'credit']);
        $assetType = AccountType::where('name', 'Asset')->first();
        $revenueType = AccountType::where('name', 'Revenue')->first();

        ChartOfAccount::create([
            'business_id' => $this->business->id,
            'code' => '1101',
            'name' => 'Cash',
            'type_id' => $assetType->id,
            'normal_balance' => 'debit',
            'is_active' => true,
        ]);
        ChartOfAccount::create([
            'business_id' => $this->business->id,
            'code' => '4100',
            'name' => 'Sales Revenue',
            'type_id' => $revenueType->id,
            'normal_balance' => 'credit',
            'is_active' => true,
        ]);

        AccountingPeriod::create([
            'business_id' => $this->business->id,
            'name' => 'Test Period',
            'start_date' => now()->startOfMonth()->toDateString(),
            'end_date' => now()->endOfMonth()->toDateString(),
            'is_closed' => false,
        ]);
    }

    protected function service(): JournalEntryImportExportService
    {
        return app(JournalEntryImportExportService::class);
    }

    protected function writeTempSpreadsheet(array $rows): string
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setCellValue('A1', 'Date');
        $sheet->setCellValue('B1', 'Description');
        $sheet->setCellValue('C1', 'Account Code');
        $sheet->setCellValue('D1', 'Debit');
        $sheet->setCellValue('E1', 'Credit');
        $sheet->setCellValue('F1', 'Line Note');
        foreach ($rows as $i => $row) {
            $r = $i + 2;
            $sheet->setCellValue('A'.$r, $row[0]);
            $sheet->setCellValue('B'.$r, $row[1]);
            $sheet->setCellValueExplicit('C'.$r, $row[2], \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
            $sheet->setCellValue('D'.$r, $row[3] ?? 0);
            $sheet->setCellValue('E'.$r, $row[4] ?? 0);
            $sheet->setCellValue('F'.$r, $row[5] ?? '');
        }
        $path = tempnam(sys_get_temp_dir(), 'je').'.xlsx';
        (new Xlsx($spreadsheet))->save($path);

        return $path;
    }

    public function test_import_creates_balanced_entries_from_grouped_rows(): void
    {
        $d1 = now()->toDateString();
        $d2 = now()->addDay()->toDateString();
        $file = $this->writeTempSpreadsheet([
            [$d1, 'Import sale', '1101', 100, 0, 'Dr cash'],
            [$d1, 'Import sale', '4100', 0, 100, 'Cr revenue'],
            [$d2, 'Second entry', '4100', 0, 50, 'Cr'],
            [$d2, 'Second entry', '1101', 50, 0, 'Dr'],
        ]);

        $result = $this->service()->import($this->business->id, $file);

        $this->assertSame(2, $result['imported']);
        $this->assertSame([], $result['errors']);

        $entries = JournalEntry::where('business_id', $this->business->id)->where('reference_type', 'journal_import')->get();
        $this->assertCount(2, $entries);

        $first = JournalEntry::where('description', 'Import sale')->first();
        $this->assertNotNull($first);
        $this->assertSame($d1, $first->date->toDateString());
        $this->assertNotNull($first->posted_at);
        $this->assertCount(2, $first->lines);
    }

    public function test_import_rejects_unbalanced_entries(): void
    {
        $d = now()->toDateString();
        $file = $this->writeTempSpreadsheet([
            [$d, 'Unbalanced', '1101', 100, 0, 'Dr cash'],
            [$d, 'Unbalanced', '4100', 0, 90, 'Cr only 90'],
        ]);

        $result = $this->service()->import($this->business->id, $file);

        $this->assertSame(0, $result['imported']);
        $this->assertCount(1, $result['errors']);
        $this->assertArrayHasKey('balance', $result['errors'][0]['errors']);
    }

    public function test_import_rejects_unknown_account_code(): void
    {
        $d = now()->toDateString();
        $file = $this->writeTempSpreadsheet([
            [$d, 'Bad code', '9999', 100, 0, ''],
        ]);

        $result = $this->service()->import($this->business->id, $file);

        $this->assertSame(0, $result['imported']);
        $this->assertArrayHasKey('account_code', $result['errors'][0]['errors']);
    }
}
