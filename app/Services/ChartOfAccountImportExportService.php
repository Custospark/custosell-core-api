<?php

namespace App\Services;

use App\Models\AccountType;
use App\Models\ChartOfAccount;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Chart-of-accounts import/export following the products import standard.
 *
 * Template columns: Account Code, Account Name, Account Type, Normal Balance.
 * Import is idempotent by (business_id, code) - matching codes update, new
 * codes are created. Returns per-row errors like ProductImportService.
 */
class ChartOfAccountImportExportService
{
    protected const HEADERS = ['Account Code', 'Account Name', 'Account Type', 'Normal Balance'];

    public function generateTemplate(): Spreadsheet
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Chart of Accounts');

        foreach (self::HEADERS as $i => $header) {
            $sheet->setCellValue(chr(65 + $i).'1', $header);
        }
        $sheet->getStyle('A1:D1')->getFont()->setBold(true);
        $sheet->getStyle('A1:D1')->getFill()
            ->setFillType(Fill::FILL_SOLID)
            ->getStartColor()->setRGB('DBEAFE');
        $sheet->getStyle('A1:D1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

        // Example rows.
        $examples = [
            ['1000', 'Example Asset', 'Asset', 'debit'],
            ['4000', 'Example Revenue', 'Revenue', 'credit'],
        ];
        foreach ($examples as $r => $row) {
            foreach ($row as $c => $val) {
                $sheet->setCellValueExplicit(chr(65 + $c).($r + 2), $val, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
            }
        }

        $sheet->getColumnDimension('A')->setWidth(18);
        $sheet->getColumnDimension('B')->setWidth(40);
        $sheet->getColumnDimension('C')->setWidth(18);
        $sheet->getColumnDimension('D')->setWidth(18);

        return $spreadsheet;
    }

    /**
     * @return array{imported: int, errors: array<int, array{row: int, errors: array<string, string[]>}>, total_rows: int}
     */
    public function import(int $businessId, string $filePath, ?int $actorUserId = null): array
    {
        $reader = IOFactory::createReaderForFile($filePath);
        $reader->setReadDataOnly(true);
        $spreadsheet = $reader->load($filePath);
        $sheet = $spreadsheet->getActiveSheet();
        $rows = $sheet->toArray(null, true, true, true);

        $typeByName = AccountType::pluck('id', 'name')->toArray();
        $existing = ChartOfAccount::where('business_id', $businessId)
            ->pluck('id', 'code')
            ->toArray();

        $imported = 0;
        $errors = [];
        $totalRows = 0;

        foreach ($rows as $rowNumber => $row) {
            if ($rowNumber === 1) {
                continue; // header
            }

            $code = trim((string) ($row['A'] ?? ''));
            $name = trim((string) ($row['B'] ?? ''));
            $typeName = trim((string) ($row['C'] ?? ''));
            $balance = strtolower(trim((string) ($row['D'] ?? '')));

            if ($code === '' && $name === '') {
                continue; // blank row
            }

            $totalRows++;
            $rowErrors = [];

            if ($code === '') {
                $rowErrors['code'] = ['Account code is required.'];
            }
            if ($name === '') {
                $rowErrors['name'] = ['Account name is required.'];
            }
            if (!isset($typeByName[$typeName])) {
                $rowErrors['type'] = ['Account type must be one of: Asset, Liability, Equity, Revenue, Expense.'];
            }
            if (!in_array($balance, ['debit', 'credit'], true)) {
                $rowErrors['normal_balance'] = ['Normal balance must be debit or credit.'];
            }

            if ($rowErrors !== []) {
                $errors[] = ['row' => $rowNumber, 'errors' => $rowErrors];
                continue;
            }

            ChartOfAccount::updateOrCreate(
                ['business_id' => $businessId, 'code' => $code],
                [
                    'name' => $name,
                    'type_id' => $typeByName[$typeName],
                    'normal_balance' => $balance,
                    'is_active' => true,
                ],
            );

            $imported++;
        }

        return [
            'imported' => $imported,
            'errors' => $errors,
            'total_rows' => $totalRows,
        ];
    }

    public function export(int $businessId): Spreadsheet
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Chart of Accounts');

        foreach (self::HEADERS as $i => $header) {
            $sheet->setCellValue(chr(65 + $i).'1', $header);
        }
        $sheet->getStyle('A1:D1')->getFont()->setBold(true);
        $sheet->getStyle('A1:D1')->getFill()
            ->setFillType(Fill::FILL_SOLID)
            ->getStartColor()->setRGB('DBEAFE');

        $accounts = ChartOfAccount::where('business_id', $businessId)
            ->with('accountType')
            ->orderBy('code')
            ->get();

        $rowNum = 2;
        foreach ($accounts as $account) {
            $sheet->setCellValueExplicit('A'.$rowNum, $account->code, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
            $sheet->setCellValue('B'.$rowNum, $account->name);
            $sheet->setCellValue('C'.$rowNum, $account->accountType?->name ?? '');
            $sheet->setCellValue('D'.$rowNum, $account->normal_balance);
            $rowNum++;
        }

        $sheet->getColumnDimension('A')->setWidth(18);
        $sheet->getColumnDimension('B')->setWidth(40);
        $sheet->getColumnDimension('C')->setWidth(18);
        $sheet->getColumnDimension('D')->setWidth(18);

        return $spreadsheet;
    }
}
