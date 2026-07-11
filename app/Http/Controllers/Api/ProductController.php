<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ProductRequest;
use App\Http\Requests\ProductSupplyListingRequest;
use App\Http\Resources\ProductCollection;
use App\Http\Resources\ProductResource;
use App\Http\Resources\StockMovementCollection;
use App\Services\Contracts\ProductServiceInterface;
use App\Services\Contracts\StockMovementServiceInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Response;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class ProductController extends Controller
{
    public function __construct(
        protected ProductServiceInterface $productService,
        protected StockMovementServiceInterface $stockMovementService,
    ) {}

    public function index(Request $request): ProductCollection
    {
        $businessId = $request->user()->business_id;
        return new ProductCollection($this->productService->getAll($businessId));
    }

    public function show(int $id): ProductResource
    {
        $product = $this->productService->getById($id);
        if (!$product) {
            abort(404, 'Product not found');
        }
        return new ProductResource($product);
    }

    public function store(ProductRequest $request): JsonResponse
    {
        $businessId = $request->user()->business_id;
        $product = $this->productService->create($businessId, $request->validated());
        return response()->json(new ProductResource($product), 201);
    }

    public function update(ProductRequest $request, int $id): ProductResource
    {
        $product = $this->productService->update($id, $request->validated());
        return new ProductResource($product);
    }

    public function destroy(int $id): JsonResponse
    {
        $this->productService->delete($id);
        return response()->json(null, 204);
    }

    public function bulkDelete(Request $request): JsonResponse
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['required', 'integer', 'exists:products,id'],
        ]);

        $businessId = $request->user()->business_id;
        $count = $this->productService->bulkDelete($data['ids'], $businessId);

        return response()->json(['deleted' => $count]);
    }

    public function updateSupplyListing(ProductSupplyListingRequest $request, int $id): ProductResource
    {
        $businessId = $request->user()->business_id;
        $product = $this->productService->updateSupplyListing($id, $businessId, $request->validated());

        return new ProductResource($product);
    }

    public function active(Request $request): ProductCollection
    {
        $businessId = $request->user()->business_id;
        return new ProductCollection($this->productService->getActive($businessId));
    }

    public function lowStock(Request $request): ProductCollection
    {
        $businessId = $request->user()->business_id;
        return new ProductCollection($this->productService->getLowStock($businessId));
    }

    public function stockMovements(Request $request, int $id): StockMovementCollection
    {
        $businessId = $request->user()->business_id;
        return new StockMovementCollection(
            $this->stockMovementService->getByProduct($businessId, $id)
        );
    }

    public function export(Request $request)
    {
        $businessId = $request->user()->business_id;
        $products = $this->productService->getAll($businessId);
        $format = $request->query('format', 'csv');

        $headers = ['Name', 'Unit', 'Category', 'Unit Price', 'Wholesale Price', 'Cost Price', 'Stock Qty', 'Low Stock Threshold', 'SKU', 'Barcode', 'Tax %', 'Description'];

        if ($format === 'xlsx') {
            $spreadsheet = new Spreadsheet;
            $sheet = $spreadsheet->getActiveSheet();
            $sheet->setTitle('Products');

            foreach ($headers as $i => $h) {
                $sheet->setCellValue(chr(65 + $i) . '1', $h);
                $sheet->getColumnDimension(chr(65 + $i))->setAutoSize(true);
            }

            foreach ($products as $r => $p) {
                $row = $r + 2;
                $vals = [
                    $p->name, $p->unit ?? '', $p->category?->name ?? '', $p->unit_price,
                    $p->wholesale_price ?? '', $p->cost_price ?? '', $p->stock_quantity,
                    $p->low_stock_threshold ?? '', $p->sku ?? '', $p->barcode ?? '',
                    $p->tax_percentage ?? '0', $p->description ?? '',
                ];
                foreach ($vals as $i => $v) {
                    $sheet->setCellValue(chr(65 + $i) . $row, $v);
                }
            }

            $writer = new Xlsx($spreadsheet);
            $temp = tempnam(sys_get_temp_dir(), 'export');
            $writer->save($temp);

            return response()->download($temp, 'products-export.xlsx', [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ])->deleteFileAfterSend(true);
        }

        $rows = [$headers];
        foreach ($products as $p) {
            $rows[] = [
                $p->name, $p->unit ?? '', $p->category?->name ?? '', $p->unit_price,
                $p->wholesale_price ?? '', $p->cost_price ?? '', $p->stock_quantity,
                $p->low_stock_threshold ?? '', $p->sku ?? '', $p->barcode ?? '',
                $p->tax_percentage ?? '0', $p->description ?? '',
            ];
        }

        $csv = implode("\n", array_map(fn ($r) => implode(',', array_map(fn ($v) => '"' . str_replace('"', '""', (string) $v) . '"', $r)), $rows));

        return Response::make($csv, 200, [
            'Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="products-export.csv"',
        ]);
    }
}
