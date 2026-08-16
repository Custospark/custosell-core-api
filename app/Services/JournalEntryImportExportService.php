<?php

namespace App\Services;

use App\Models\ChartOfAccount;
use App\Models\JournalEntry;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Journal-entries import/export following the products import standard.
 *
 * Export columns: Date, Description, Account Code, Debit, Credit, Line Note.
 * Import groups consecutive rows sharing the same Date+Description into one
 * balanced journal entry; each row is one account line (Debit or Credit).
 */
class JournalEntryImportExportService
{
    protected const HEADERS = ['Date', 'Description', 'Account Code', 'Debit', 'Credit', 'Line Note'];

    public function __construct(
        protected JournalEntryService $journalEntryService,
    ) {}

    public function generateTemplate(): Spreadsheet
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Journal Entries');

        foreach (self::HEADERS as $i => $header) {
            $sheet->setCellValue(chr(65 + $i).'1', $header);
        }
        $sheet->getStyle('A1:F1')->getFont()->setBold(true);
        $sheet->getStyle('A1:F1')->getFill()
            ->setFillType(Fill::FILL_SOLID)
            ->getStartColor()->setRGB('DBEAFE');
        $sheet->getStyle('A1:F1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

        // Example balanced entry: Dr Cash / Cr Sales Revenue.
        $examples = [
            [now()->format('Y-m-d'), 'Example entry', '1101', 100, 0, 'Dr cash'],
            [now()->format('Y-m-d'), 'Example entry', '4100', 0, 100, 'Cr revenue'],
        ];
        foreach ($examples as $r => $row) {
            foreach ($row as $c => $val) {
                $sheet->setCellValue(chr(65 + $c).($r + 2), $val);
            }
        }

        foreach (['A', 'B', 'C', 'D', 'E', 'F'] as $col) {
            $sheet->getColumnDimension($col)->setWidth(18);
        }
        $sheet->getColumnDimension('B')->setWidth(40);

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

        $accountByCode = ChartOfAccount::where('business_id', $businessId)
            ->pluck('id', 'code')
            ->toArray();

        $imported = 0;
        $errors = [];
        $totalRows = 0;

        // Group consecutive rows by (date, description) into one entry.
        $groups = [];
        foreach ($rows as $rowNumber => $row) {
            if ($rowNumber === 1) {
                continue;
            }

            $date = trim((string) ($row['A'] ?? ''));
            $description = trim((string) ($row['B'] ?? ''));
            $code = trim((string) ($row['C'] ?? ''));
            $debit = (float) ($row['D'] ?? 0);
            $credit = (float) ($row['E'] ?? 0);
            $note = trim((string) ($row['F'] ?? ''));

            if ($date === '' && $description === '' && $code === '') {
                continue; // blank
            }

            $totalRows++;
            $groupKey = $date.'|'.$description;

            if ($date === '') {
                $errors[] = ['row' => $rowNumber, 'errors' => ['date' => ['Date is required.']]];
                continue;
            }
            if ($description === '') {
                $errors[] = ['row' => $rowNumber, 'errors' => ['description' => ['Description is required.']]];
                continue;
            }
            if (!isset($accountByCode[$code])) {
                $errors[] = ['row' => $rowNumber, 'errors' => ['account_code' => ["Account code '{$code}' does not exist in your chart of accounts."]]];
                continue;
            }
            if ($debit <= 0 && $credit <= 0) {
                $errors[] = ['row' => $rowNumber, 'errors' => ['amount' => ['Enter a Debit or Credit amount greater than zero.']]];
                continue;
            }

            $groups[$groupKey][] = [
                'date' => $date,
                'description' => $description,
                'account_id' => $accountByCode[$code],
                'account_code' => $code,
                'debit' => $debit,
                'credit' => $credit,
                'note' => $note,
                'row' => $rowNumber,
            ];
        }

        foreach ($groups as $lines) {
            $debitTotal = round(array_sum(array_column($lines, 'debit')), 2);
            $creditTotal = round(array_sum(array_column($lines, 'credit')), 2);

            if (abs($debitTotal - $creditTotal) > 0.01) {
                $errors[] = [
                    'row' => $lines[0]['row'],
                    'errors' => ['balance' => ['Entry is not balanced - debits '.$debitTotal.' do not match credits '.$creditTotal.'.']],
                ];
                continue;
            }

            try {
                $this->journalEntryService->createAndPostEntry(
                    $businessId,
                    $lines[0]['date'],
                    $lines[0]['description'],
                    array_map(
                        fn ($l) => [
                            'account_code' => $l['account_code'],
                            'debit' => $l['debit'],
                            'credit' => $l['credit'],
                            'description' => $l['note'] ?: null,
                        ],
                        $lines,
                    ),
                    'journal_import',
                    null,
                    $actorUserId,
                );
                $imported++;
            } catch (\Throwable $e) {
                $errors[] = [
                    'row' => $lines[0]['row'],
                    'errors' => ['import' => [$e->getMessage()]],
                ];
            }
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
        $sheet->setTitle('Journal Entries');

        foreach (self::HEADERS as $i => $header) {
            $sheet->setCellValue(chr(65 + $i).'1', $header);
        }
        $sheet->getStyle('A1:F1')->getFont()->setBold(true);
        $sheet->getStyle('A1:F1')->getFill()
            ->setFillType(Fill::FILL_SOLID)
            ->getStartColor()->setRGB('DBEAFE');

        $entries = JournalEntry::where('business_id', $businessId)
            ->with('lines.chartOfAccount')
            ->orderByDesc('date')
            ->orderByDesc('id')
            ->get();

        $rowNum = 2;
        foreach ($entries as $entry) {
            foreach ($entry->lines as $line) {
                $sheet->setCellValue('A'.$rowNum, $entry->date);
                $sheet->setCellValue('B'.$rowNum, $entry->description);
                $sheet->setCellValueExplicit('C'.$rowNum, $line->chartOfAccount?->code ?? '', \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
                $sheet->setCellValue('D'.$rowNum, (float) $line->debit_amount);
                $sheet->setCellValue('E'.$rowNum, (float) $line->credit_amount);
                $sheet->setCellValue('F'.$rowNum, $line->description ?? '');
                $rowNum++;
            }
        }

        foreach (['A', 'B', 'C', 'D', 'E', 'F'] as $col) {
            $sheet->getColumnDimension($col)->setWidth(18);
        }
        $sheet->getColumnDimension('B')->setWidth(40);

        return $spreadsheet;
    }
}
