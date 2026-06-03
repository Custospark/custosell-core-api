<?php

namespace App\Services;

use App\Models\Product;
use App\Models\StockMovement;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;

class ProductImportService
{
    protected const HEADERS = [
        'Name*', 'Unit', 'Category', 'Unit Price*', 'Wholesale Price',
        'Cost Price', 'Stock Qty', 'Low Stock Threshold', 'SKU', 'Barcode', 'Tax %', 'Description',
    ];

    protected const REQUIRED = ['name', 'unit_price'];

    protected const CATEGORY_CACHE = [];

    public function generateTemplate(): Spreadsheet
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Products');

        $bold = ['font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']], 'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '2563EB']], 'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER]];
        $sheet->getStyle('A1:L1')->applyFromArray($bold);

        foreach (self::HEADERS as $i => $h) {
            $col = chr(65 + $i);
            $sheet->setCellValue($col . '1', $h);
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        $example = ['Maize Flour', 'Kg', 'Grains', '4000', '3500', '2500', '100', '10', '', '', '0', 'Premium maize flour (delete this example row)'];
        foreach ($example as $i => $val) {
            $sheet->setCellValue(chr(65 + $i) . '2', $val);
        }

        $sheet->getStyle('A2:L2')->applyFromArray(['fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'F0FDF4']]]);

        $sheet->setCellValue('A3', '');
        $sheet->getStyle('A3:L3')->applyFromArray(['borders' => ['bottom' => ['borderStyle' => Border::BORDER_MEDIUM, 'color' => ['rgb' => 'E5E7EB']]]]);

        $sheet->freezePane('A2');

        return $spreadsheet;
    }

    public function import(int $businessId, string $filePath): array
    {
        $spreadsheet = IOFactory::load($filePath);
        $worksheet = $spreadsheet->getActiveSheet();
        $rows = $worksheet->toArray();
        array_shift($rows);

        $results = ['imported' => 0, 'errors' => [], 'total_rows' => count($rows)];
        $categories = \App\Models\Category::where('business_id', $businessId)->pluck('id', 'name')->toArray();

        foreach ($rows as $index => $row) {
            $rowNum = $index + 2;
            $data = $this->mapRow($row, $categories);

            $validator = Validator::make($data, [
                'name' => ['required', 'string', 'max:255'],
                'unit' => ['nullable', 'string', 'max:50'],
                'category_id' => ['nullable', 'integer', 'exists:categories,id'],
                'unit_price' => ['required', 'numeric', 'min:0'],
                'wholesale_price' => ['nullable', 'numeric', 'min:0'],
                'cost_price' => ['nullable', 'numeric', 'min:0'],
                'stock_quantity' => ['nullable', 'integer', 'min:0'],
                'low_stock_threshold' => ['nullable', 'integer', 'min:0'],
                'sku' => ['nullable', 'string', 'max:100', 'unique:products,sku'],
                'barcode' => ['nullable', 'string', 'max:100'],
                'tax_percentage' => ['nullable', 'numeric', 'min:0', 'max:100'],
                'description' => ['nullable', 'string'],
            ]);

            if ($validator->fails()) {
                $results['errors'][] = ['row' => $rowNum, 'errors' => $validator->errors()->toArray()];
                continue;
            }

            DB::transaction(function () use ($businessId, $data, &$results) {
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
                    ]);

                    $product->stock_quantity = $stockQty;
                    $product->save();
                }

                $results['imported']++;
            });
        }

        return $results;
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
            'description' => $get(11),
        ];
    }
}
