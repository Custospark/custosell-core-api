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

    public function downloadTemplate()
    {
        $spreadsheet = $this->importService->generateTemplate();
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
            'file' => ['required', 'file', 'mimes:xlsx,xls,csv', 'max:5120'],
        ]);

        $businessId = $request->user()->business_id;
        if (!$businessId) {
            return response()->json(['message' => 'No business associated with this user'], 400);
        }

        $results = $this->importService->import($businessId, $request->file('file')->getPathname());

        return response()->json($results);
    }
}
