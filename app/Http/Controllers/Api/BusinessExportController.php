<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Services\BusinessExportService;
use App\Services\ReportExportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class BusinessExportController extends Controller
{
    public function __construct(
        protected BusinessExportService $businessExport,
        protected ReportExportService $export,
    ) {}

    public function export(Request $request): JsonResponse|Response
    {
        $request->validate([
            'format' => 'nullable|in:json,csv,xlsx',
        ]);

        $business = Business::findOrFail((int) $request->user()->business_id);

        if ((int) $business->owner_id !== (int) $request->user()->id) {
            return response()->json(['message' => 'Only the business owner can export data.'], 403);
        }

        $format = $request->query('format', 'json');

        if ($format === 'json') {
            $data = $this->businessExport->exportJson($business);
            return response()->json(['data' => $data]);
        }

        $entity = $request->query('entity', 'all');
        if ($entity === 'all') {
            $data = $this->businessExport->exportJson($business);
            $filename = $this->export->buildFilename($business, 'full-export');
            $headers = ['Entity', 'Count'];
            $rows = array_map(fn ($key, $val) => [$key, is_array($val) ? count($val) : $val], array_keys($data), array_values($data));

            if ($format === 'csv') {
                return $this->export->downloadCsv($filename, $headers, $rows);
            }

            return $this->export->downloadRichXlsx([
                'filename' => $filename,
                'business' => $business,
                'reportTitle' => 'Full Business Data Export',
                'accent' => '#059669',
                'headers' => $headers,
                'rows' => $rows,
            ]);
        }

        [$headers, $rows] = $this->businessExport->exportCsv($business, $entity);
        $filename = $this->export->buildFilename($business, "export-{$entity}");

        if ($format === 'csv') {
            return $this->export->downloadCsv($filename, $headers, $rows);
        }

        return $this->export->downloadRichXlsx([
            'filename' => $filename,
            'business' => $business,
            'reportTitle' => "Export: {$entity}",
            'accent' => '#059669',
            'headers' => $headers,
            'rows' => $rows,
        ]);
    }
}
