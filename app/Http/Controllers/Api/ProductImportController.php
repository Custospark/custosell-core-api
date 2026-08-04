<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ProductImportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class ProductImportController extends Controller
{
    public function __construct(
        protected ProductImportService $importService,
    ) {}

    public function downloadTemplate(Request $request)
    {
        $businessId = (int) ($request->user()->business_id ?? 0);
        $spreadsheet = $this->importService->generateTemplate($businessId);
        $writer = new Xlsx($spreadsheet);

        $fileName = 'product-import-template.xlsx';
        $tempFile = tempnam(sys_get_temp_dir(), $fileName);
        $writer->save($tempFile);

        return response()->download($tempFile, $fileName, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])->deleteFileAfterSend(true);
    }

    public function import(Request $request): JsonResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'mimes:xlsx,xls,csv', 'max:20480'],
            'location_id' => ['nullable', 'integer'],
        ]);

        $businessId = $request->user()->business_id;
        if (!$businessId) {
            return response()->json(['message' => 'No business associated with this user'], 400);
        }

        // Long import window: FE axios timeout is 600_000 ms for this path.
        set_time_limit(600);
        if (function_exists('ini_set')) {
            @ini_set('max_execution_time', '600');
            @ini_set('memory_limit', '512M');
        }

        $results = $this->importService->import(
            $businessId,
            $request->file('file')->getPathname(),
            $request->user()->id,
            $request->integer('location_id') ?: null,
        );

        return response()->json($results);
    }
}
