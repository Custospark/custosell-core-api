<?php

namespace App\Services;

use App\Models\Product;
use App\Models\StockMovement;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;

class ProductImportService
{
    protected const CHUNK_SIZE = 100;

    protected const TAX_CLASSES = ['standard', 'exempt', 'zero_rated'];

    protected const HEADERS = [
        'Name*', 'Unit', 'Category', 'Unit Price*', 'Wholesale Price',
        'Cost Price', 'Stock Qty', 'Low Stock Threshold', 'SKU', 'Barcode', 'Tax %', 'Tax Class', 'Description',
    ];

    protected const REQUIRED = ['name', 'unit_price'];

    protected const CATEGORY_CACHE = [];

    public function generateTemplate(): Spreadsheet
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Products');

        $bold = ['font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']], 'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '2563EB']], 'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER]];
        $lastCol = chr(65 + count(self::HEADERS) - 1);
        $sheet->getStyle("A1:{$lastCol}1")->applyFromArray($bold);

        foreach (self::HEADERS as $i => $h) {
            $col = chr(65 + $i);
            $sheet->setCellValue($col . '1', $h);
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        $example = ['Maize Flour', 'Kg', 'Grains', '4000', '3500', '2500', '100', '10', '', '', '18', 'standard', 'Premium maize flour (delete this example row)'];
        foreach ($example as $i => $val) {
            $sheet->setCellValue(chr(65 + $i) . '2', $val);
        }

        $sheet->getStyle("A2:{$lastCol}2")->applyFromArray(['fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'F0FDF4']]]);

        $sheet->setCellValue('A3', '');
        $sheet->getStyle("A3:{$lastCol}3")->applyFromArray(['borders' => ['bottom' => ['borderStyle' => Border::BORDER_MEDIUM, 'color' => ['rgb' => 'E5E7EB']]]]);

        $sheet->freezePane('A2');

        return $spreadsheet;
    }

    public function import(int $businessId, string $filePath, ?int $actorUserId = null): array
    {
        $reader = IOFactory::createReaderForFile($filePath);
        $reader->setReadDataOnly(true);
        $worksheet = $reader->load($filePath)->getActiveSheet();
        $rows = $worksheet->toArray();
        array_shift($rows);

        $rowEntries = [];
        foreach ($rows as $index => $row) {
            if ($this->isEmptyRow($row)) {
                continue;
            }
            $rowEntries[] = ['index' => $index, 'row' => $row];
        }

        $results = ['imported' => 0, 'errors' => [], 'total_rows' => count($rowEntries)];
        if ($rowEntries === []) {
            return $results;
        }

        $categories = \App\Models\Category::where('business_id', $businessId)->pluck('id', 'name')->toArray();
        $existingSkus = Product::query()
            ->where('business_id', $businessId)
            ->whereNotNull('sku')
            ->pluck('sku')
            ->map(fn ($sku) => strtolower(trim((string) $sku)))
            ->flip()
            ->all();
        $importSkus = [];

        foreach (array_chunk($rowEntries, self::CHUNK_SIZE) as $chunk) {
            DB::transaction(function () use ($businessId, $chunk, $categories, &$existingSkus, &$importSkus, &$results) {
                foreach ($chunk as $entry) {
                    $rowNum = $entry['index'] + 2;
                    $data = $this->mapRow($entry['row'], $categories);
                    $skuKey = $data['sku'] ? strtolower(trim($data['sku'])) : null;

                    $validator = Validator::make($data, $this->validationRules($businessId));

                    if ($skuKey) {
                        if (isset($existingSkus[$skuKey]) || isset($importSkus[$skuKey])) {
                            $validator->after(function ($v) {
                                $v->errors()->add('sku', 'The sku has already been taken.');
                            });
                        }
                    }

                    if ($validator->fails()) {
                        $results['errors'][] = ['row' => $rowNum, 'errors' => $validator->errors()->toArray()];
                        continue;
                    }

                    $data['business_id'] = $businessId;
                    $stockQty = (int) ($data['stock_quantity'] ?? 0);
                    unset($data['stock_quantity']);

                    $product = Product::create($data);

                    if ($stockQty > 0) {
                        StockMovement::create([
                            'business_id' => $businessId,
                            'product_id' => $product->id,
                            'type' => 'initial',
                            'quantity_change' => $stockQty,
                            'stock_before' => 0,
                            'stock_after' => $stockQty,
                            'notes' => 'Initial stock from import',
                            'created_by' => $actorUserId ?? auth()->id(),
                        ]);

                        $product->stock_quantity = $stockQty;
                        $product->save();
                    }

                    if ($skuKey) {
                        $importSkus[$skuKey] = true;
                    }

                    $results['imported']++;
                }
            });
        }

        return $results;
    }

    /** @return array<string, mixed> */
    protected function validationRules(int $businessId): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'unit' => ['nullable', 'string', 'max:50'],
            'category_id' => ['nullable', 'integer', 'exists:categories,id'],
            'unit_price' => ['required', 'numeric', 'min:0'],
            'wholesale_price' => ['nullable', 'numeric', 'min:0'],
            'cost_price' => ['nullable', 'numeric', 'min:0'],
            'stock_quantity' => ['nullable', 'integer', 'min:0'],
            'low_stock_threshold' => ['nullable', 'integer', 'min:0'],
            'sku' => [
                'nullable',
                'string',
                'max:100',
                Rule::unique('products', 'sku')->where(fn ($q) => $q->where('business_id', $businessId)),
            ],
            'barcode' => ['nullable', 'string', 'max:100'],
            'tax_percentage' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'tax_class' => ['nullable', 'string', 'in:standard,exempt,zero_rated'],
            'description' => ['nullable', 'string'],
        ];
    }

    protected function isEmptyRow(array $row): bool
    {
        foreach ($row as $cell) {
            if ($cell !== null && trim((string) $cell) !== '') {
                return false;
            }
        }

        return true;
    }

    protected function mapRow(array $row, array $categories): array
    {
        $get = function (int $i) use ($row): ?string {
            $raw = $row[$i] ?? null;
            if ($raw === null || $raw === '') return null;
            $trimmed = trim((string) $raw);
            return $trimmed === '' ? null : $trimmed;
        };

        $name = $get(0);
        $categoryName = $get(2);
        $categoryId = $categoryName && isset($categories[$categoryName]) ? $categories[$categoryName] : null;

        return [
            'name' => $name,
            'unit' => $get(1),
            'category_id' => $categoryId,
            'unit_price' => $get(3),
            'wholesale_price' => $get(4),
            'cost_price' => $get(5),
            'stock_quantity' => $get(6),
            'low_stock_threshold' => $get(7),
            'sku' => $get(8),
            'barcode' => $get(9),
            'tax_percentage' => $get(10),
            'tax_class' => $this->normalizeTaxClass($get(11)),
            'description' => $get(12),
        ];
    }

    protected function normalizeTaxClass(?string $value): string
    {
        if ($value === null || trim($value) === '') {
            return 'standard';
        }

        $normalized = strtolower(str_replace([' ', '-'], '_', trim($value)));

        return in_array($normalized, self::TAX_CLASSES, true) ? $normalized : 'standard';
    }
}
