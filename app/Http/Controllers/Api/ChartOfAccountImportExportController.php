<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ChartOfAccountImportExportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class ChartOfAccountImportExportController extends Controller
{
    public function __construct(
        protected ChartOfAccountImportExportService $importExportService,
    ) {}

    public function downloadTemplate(Request $request)
    {
        $spreadsheet = $this->importExportService->generateTemplate();
        $writer = new Xlsx($spreadsheet);

        $fileName = 'chart-of-accounts-import-template.xlsx';
        $tempFile = tempnam(sys_get_temp_dir(), $fileName);
        $writer->save($tempFile);

        return response()->download($tempFile, $fileName, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])->deleteFileAfterSend(true);
    }

    public function import(Request $request): JsonResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'mimes:xlsx,xls,csv', 'max:10240'],
        ]);

        $businessId = $request->user()->business_id;
        if (!$businessId) {
            return response()->json(['message' => 'No business associated with this user'], 400);
        }

        set_time_limit(300);
        if (function_exists('ini_set')) {
            @ini_set('max_execution_time', '300');
            @ini_set('memory_limit', '256M');
        }

        $results = $this->importExportService->import(
            $businessId,
            $request->file('file')->getPathname(),
            $request->user()->id,
        );

        return response()->json($results);
    }

    public function export(Request $request)
    {
        $businessId = $request->user()->business_id;
        if (!$businessId) {
            return response()->json(['message' => 'No business associated with this user'], 400);
        }

        $spreadsheet = $this->importExportService->export($businessId);
        $writer = new Xlsx($spreadsheet);

        $fileName = 'chart-of-accounts-'.date('Y-m-d').'.xlsx';
        $tempFile = tempnam(sys_get_temp_dir(), $fileName);
        $writer->save($tempFile);

        return response()->download($tempFile, $fileName, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])->deleteFileAfterSend(true);
    }
}
