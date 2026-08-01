<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Business;
use App\Support\ReportDateRange;
use App\Services\ReportMetricsService;
use Illuminate\Http\Request;

trait BuildsReportResponses
{
    private function getDateRange(Request $request): array
    {
        return ReportDateRange::fromRequest(
            $request->query('date_from'),
            $request->query('date_to'),
        );
    }

    private function businessId(Request $request): int
    {
        return (int) $request->user()->business_id;
    }

    private function getBusiness(Request $request): Business
    {
        return Business::findOrFail($this->businessId($request));
    }

    /** @return array{user_id: int|null, shift_id: int|null} */
    private function filters(Request $request): array
    {
        return [
            'user_id' => $request->filled('user_id') ? (int) $request->query('user_id') : null,
            'shift_id' => $request->filled('shift_id') ? (int) $request->query('shift_id') : null,
        ];
    }

    private function pdfData(Request $request, array $extra = []): array
    {
        return array_merge([
            'business' => $this->getBusiness($request),
            'formatter' => $this->export,
            'metrics' => $this->metrics,
            'brandTagline' => ReportMetricsService::BRAND_TAGLINE,
            'brandFooter' => ReportMetricsService::BRAND_FOOTER,
        ], $extra);
    }

    private function dateSubtitle(string $dateFrom, string $dateTo): string
    {
        return "{$dateFrom} - {$dateTo}";
    }

    /** @return list<list<mixed>> */
    private function trendExportRows(array $trend): array
    {
        return array_map(fn ($day) => [
            $day['date'],
            $day['gross_sales'],
            $day['refunds'],
            $day['expenses'],
            $day['net_sales'],
            $day['transactions'],
        ], $trend);
    }

    /** @return array{title: string, categoryCol: int, valueCol: int} */
    private function trendChartConfig(): array
    {
        return [
            'title' => 'Daily Net Sales',
            'categoryCol' => 0,
            'valueCol' => 4,
        ];
    }

    /** @return array{title: string, headers: list<string>, rows: list<list<mixed>>, chart: array{title: string, categoryCol: int, valueCol: int}}|null */
    private function trendBlock(array $trend): ?array
    {
        if ($trend === []) {
            return null;
        }

        return [
            'title' => 'Daily Performance Trend',
            'headers' => ['Date', 'Gross Sales', 'Refunds', 'Expenses', 'Net Sales', 'Transactions'],
            'rows' => $this->trendExportRows($trend),
            'chart' => $this->trendChartConfig(),
        ];
    }

    private function pdfOrientation(string $reportKey): string
    {
        return in_array($reportKey, [
            'daily-sales',
            'shift-reconciliation',
            'inventory',
            'sales-trend',
            'payment-breakdown',
        ], true) ? 'landscape' : 'portrait';
    }

    private function xlsx(
        Business $business,
        string $reportKey,
        ?string $dateFrom,
        ?string $dateTo,
        string $reportTitle,
        string $accentHex,
        array $summaryCards,
        array $headers,
        array $rows,
        ?string $subtitle = null,
        ?string $purpose = null,
        ?array $insightLines = null,
        ?array $chart = null,
        ?array $trendBlock = null,
    ) {
        return $this->export->downloadRichXlsx([
            'filename' => $this->export->buildFilename($business, $reportKey, $dateFrom, $dateTo),
            'business' => $business,
            'reportTitle' => $reportTitle,
            'reportSubtitle' => $subtitle,
            'reportPurpose' => $purpose,
            'accentHex' => $accentHex,
            'summaryCards' => $summaryCards,
            'insightLines' => $insightLines,
            'headers' => $headers,
            'rows' => $rows,
            'chart' => $chart,
            'trendBlock' => $trendBlock,
        ]);
    }
}
