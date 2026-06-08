<?php

namespace App\Services;

use App\Models\Business;
use Barryvdh\DomPDF\Facade\Pdf;
use PhpOffice\PhpSpreadsheet\Chart\Chart;
use PhpOffice\PhpSpreadsheet\Chart\DataSeries;
use PhpOffice\PhpSpreadsheet\Chart\DataSeriesValues;
use PhpOffice\PhpSpreadsheet\Chart\Legend;
use PhpOffice\PhpSpreadsheet\Chart\PlotArea;
use PhpOffice\PhpSpreadsheet\Chart\Title as ChartTitle;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;

class ReportExportService
{
    private const CURRENCY_SYMBOLS = [
        'UGX' => 'UGX',
        'KES' => 'KSh',
        'TZS' => 'TSh',
        'USD' => '$',
        'EUR' => 'EUR',
        'GBP' => 'GBP',
    ];

    public const NET_SALES_FORMULA_LABEL = 'Net Sales (gross - refunds - expenses)';

    public function formatMoney(float $amount, ?string $currencyCode = null): string
    {
        $code = strtoupper($currencyCode ?? 'UGX');
        $symbol = self::CURRENCY_SYMBOLS[$code] ?? $code;
        $formatted = number_format($amount, 2, '.', ',');

        return "{$symbol} {$formatted}";
    }

    /** Compact numeric value for PDF/Excel table cells (currency shown in column header). */
    public function formatTableNumber(float $amount): string
    {
        return number_format($amount, 2, '.', ',');
    }

    public function buildFilename(Business $business, string $reportKey, ?string $dateFrom = null, ?string $dateTo = null): string
    {
        $namePart = $this->sanitizeFilenamePart($business->name ?: $business->slug ?: 'business');
        $keyPart = $this->sanitizeFilenamePart($reportKey);
        $parts = [$namePart, $keyPart];

        if ($dateFrom && $dateTo) {
            $parts[] = $dateFrom.'_to_'.$dateTo;
        } else {
            $parts[] = now()->format('Y-m-d');
        }

        return implode('-', $parts);
    }

    public function buildShiftCloseFilename(Business $business, string $cashierName, ?\DateTimeInterface $closedAt = null): string
    {
        $namePart = $this->sanitizeFilenamePart($business->name ?: $business->slug ?: 'business');
        $cashierPart = $this->sanitizeFilenamePart($cashierName ?: 'cashier');
        $stamp = ($closedAt ?? now())->format('Y-m-d-Hi');

        return "{$namePart}-shift-close-{$cashierPart}-{$stamp}";
    }

    public function sanitizeFilenamePart(string $value): string
    {
        $value = preg_replace('/[^\p{L}\p{N}\s_-]/u', '', $value) ?? '';
        $value = preg_replace('/[\s_]+/', '-', trim($value)) ?? '';
        $value = preg_replace('/-+/', '-', $value) ?? '';

        return strtolower($value) ?: 'report';
    }

    public function hexToArgb(string $hex): string
    {
        $hex = ltrim($hex, '#');

        return 'FF'.strtoupper(strlen($hex) === 6 ? $hex : '1E40AF');
    }

    /**
     * @param  array{
     *   filename: string,
     *   business: Business,
     *   reportTitle: string,
     *   reportSubtitle?: string|null,
     *   reportPurpose?: string|null,
     *   accentHex?: string,
     *   summaryCards?: list<array{label: string, value: string, tone?: string}>,
     *   insightLines?: list<string>,
     *   headers: list<string>,
     *   rows: list<list<mixed>>,
     *   chart?: array{title: string, categoryCol: int, valueCol: int, excludeLastRows?: int}|null,
     *   trendBlock?: array{title: string, headers: list<string>, rows: list<list<mixed>>, chart?: array{title: string, categoryCol: int, valueCol: int, excludeLastRows?: int}|null}|null
     * }  $config
     */
    public function downloadRichXlsx(array $config): BinaryFileResponse
    {
        $business = $config['business'];
        $headers = $config['headers'];
        $rows = $config['rows'];
        $accentArgb = $this->hexToArgb($config['accentHex'] ?? '#1e40af');
        $lastCol = $this->columnLetter(max(0, count($headers) - 1));

        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Report');

        $row = 1;
        $sheet->setCellValue("A{$row}", strtoupper($business->name));
        $sheet->mergeCells("A{$row}:{$lastCol}{$row}");
        $this->styleHeaderBand($sheet, "A{$row}:{$lastCol}{$row}", $accentArgb, 16);

        $row++;
        $sheet->setCellValue("A{$row}", $config['reportTitle']);
        $sheet->mergeCells("A{$row}:{$lastCol}{$row}");
        $sheet->getStyle("A{$row}")->getFont()->setBold(true)->setSize(13);

        if (! empty($config['reportSubtitle'])) {
            $row++;
            $sheet->setCellValue("A{$row}", $config['reportSubtitle']);
            $sheet->mergeCells("A{$row}:{$lastCol}{$row}");
            $sheet->getStyle("A{$row}")->getFont()->setSize(10)->getColor()->setARGB('FF6B7280');
        }

        if (! empty($config['reportPurpose'])) {
            $row++;
            $sheet->setCellValue("A{$row}", $config['reportPurpose']);
            $sheet->mergeCells("A{$row}:{$lastCol}{$row}");
            $sheet->getStyle("A{$row}")->getFont()->setItalic(true)->setSize(10)->getColor()->setARGB('FF2563EB');
        }

        $row += 2;

        if (! empty($config['summaryCards'])) {
            $sheet->setCellValue("A{$row}", 'Summary');
            $sheet->mergeCells("A{$row}:{$lastCol}{$row}");
            $sheet->getStyle("A{$row}")->getFont()->setBold(true)->setSize(11);
            $row++;

            $summaryHeaderRow = $row;
            $sheet->setCellValue("A{$row}", 'Metric');
            $sheet->setCellValue("B{$row}", 'Value');
            $sheet->mergeCells("B{$row}:{$lastCol}{$row}");
            $this->styleTableHeader($sheet, "A{$summaryHeaderRow}:{$lastCol}{$summaryHeaderRow}", $accentArgb);
            $row++;

            foreach ($config['summaryCards'] as $card) {
                $sheet->setCellValue("A{$row}", $card['label']);
                $sheet->setCellValue("B{$row}", $card['value']);
                $sheet->mergeCells("B{$row}:{$lastCol}{$row}");
                $tone = $card['tone'] ?? '';
                if ($tone === 'negative') {
                    $sheet->getStyle("B{$row}")->getFont()->getColor()->setARGB('FFDC2626');
                } elseif ($tone === 'positive') {
                    $sheet->getStyle("B{$row}")->getFont()->getColor()->setARGB('FF16A34A');
                }
                $sheet->getStyle("A{$row}:{$lastCol}{$row}")->getFill()
                    ->setFillType(Fill::FILL_SOLID)
                    ->getStartColor()->setARGB($row % 2 === 0 ? 'FFF9FAFB' : 'FFFFFFFF');
                $row++;
            }

            $sheet->getStyle("A{$summaryHeaderRow}:{$lastCol}".($row - 1))
                ->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
            $row++;
        }

        if (! empty($config['insightLines'])) {
            $sheet->setCellValue("A{$row}", 'Key Insights');
            $sheet->mergeCells("A{$row}:{$lastCol}{$row}");
            $sheet->getStyle("A{$row}")->getFont()->setBold(true)->setSize(11);
            $row++;

            foreach ($config['insightLines'] as $line) {
                $sheet->setCellValue("A{$row}", $line);
                $sheet->mergeCells("A{$row}:{$lastCol}{$row}");
                $sheet->getStyle("A{$row}:{$lastCol}{$row}")->getFill()
                    ->setFillType(Fill::FILL_SOLID)
                    ->getStartColor()->setARGB('FFF0F9FF');
                $row++;
            }
            $row++;
        }

        $trendChartMeta = null;
        if (! empty($config['trendBlock'])) {
            [$row, $trendChartMeta] = $this->writeTrendBlock(
                $sheet,
                $config['trendBlock'],
                $row,
                $lastCol,
                $accentArgb,
            );
        }

        $dataHeaderRow = $row;
        foreach ($headers as $index => $header) {
            $sheet->setCellValue($this->columnLetter($index).$row, $header);
        }
        $this->styleTableHeader($sheet, "A{$dataHeaderRow}:{$lastCol}{$dataHeaderRow}", $accentArgb);
        $row++;

        $dataStartRow = $row;
        foreach ($rows as $rowData) {
            foreach ($rowData as $colIndex => $value) {
                $cell = $this->columnLetter($colIndex).$row;
                $sheet->setCellValue($cell, $value);
                if (is_numeric($value) && $colIndex > 0) {
                    $sheet->getStyle($cell)->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_NUMBER_COMMA_SEPARATED1);
                }
            }

            $firstCell = (string) ($rowData[0] ?? '');
            $isTotalRow = str_contains(strtolower($firstCell), 'total')
                || str_contains(strtolower($firstCell), 'net sales')
                || str_contains(strtolower((string) ($rowData[5] ?? '')), 'period')
                || str_contains(strtolower((string) ($rowData[5] ?? '')), 'receipt totals');

            if ($isTotalRow) {
                $sheet->getStyle("A{$row}:{$lastCol}{$row}")->getFont()->setBold(true);
                $sheet->getStyle("A{$row}:{$lastCol}{$row}")->getFill()
                    ->setFillType(Fill::FILL_SOLID)
                    ->getStartColor()->setARGB('FFF8FAFC');
            } elseif ($row % 2 === 0) {
                $sheet->getStyle("A{$row}:{$lastCol}{$row}")->getFill()
                    ->setFillType(Fill::FILL_SOLID)
                    ->getStartColor()->setARGB('FFFAFAFA');
            }
            $row++;
        }

        $dataEndRow = $row - 1;
        $sheet->getStyle("A{$dataHeaderRow}:{$lastCol}{$dataEndRow}")
            ->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);

        $row += 1;
        $this->writeBrandFooter($sheet, $row, $lastCol);
        $row += 3;

        foreach (range(0, count($headers) - 1) as $index) {
            $sheet->getColumnDimension($this->columnLetter($index))->setAutoSize(true);
        }

        if (! empty($config['chart']) && $dataEndRow >= $dataStartRow) {
            $chartEndRow = $dataEndRow - (int) ($config['chart']['excludeLastRows'] ?? 0);
            if ($chartEndRow >= $dataStartRow) {
                $this->attachBarChart($sheet, $config['chart'], $dataStartRow, $chartEndRow, $dataEndRow + 4, $lastCol);
            }
        }

        if ($trendChartMeta) {
            $trendEnd = $trendChartMeta['end'] - (int) ($trendChartMeta['chart']['excludeLastRows'] ?? 0);
            if ($trendEnd >= $trendChartMeta['start']) {
                $this->attachBarChart(
                    $sheet,
                    $trendChartMeta['chart'],
                    $trendChartMeta['start'],
                    $trendEnd,
                    $trendEnd + 4,
                    $lastCol,
                );
            }
        }

        $writer = new Xlsx($spreadsheet);
        $writer->setIncludeCharts(true);
        $temp = tempnam(sys_get_temp_dir(), 'rpt');
        $writer->save($temp);

        return response()->download($temp, $config['filename'].'.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])->deleteFileAfterSend(true);
    }

    /** @deprecated Use downloadRichXlsx */
    public function downloadXlsx(string $filename, array $headers, array $rows, ?string $title = null): BinaryFileResponse
    {
        return $this->downloadRichXlsx([
            'filename' => $filename,
            'business' => new Business(['name' => $title ?? 'Report', 'currency' => 'UGX']),
            'reportTitle' => $title ?? 'Report',
            'headers' => $headers,
            'rows' => $rows,
        ]);
    }

    public function downloadCsv(string $filename, array $headers, array $rows): Response
    {
        $lines = array_map(
            fn ($row) => implode(',', array_map(fn ($value) => '"'.str_replace('"', '""', (string) $value).'"', $row)),
            array_merge([$headers], $rows),
        );

        return response(implode("\n", $lines), 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="'.$filename.'.csv"',
        ]);
    }

    public function downloadPdf(string $view, array $data, string $filename, string $orientation = 'portrait')
    {
        $pdf = Pdf::loadView($view, $data);
        $pdf->setPaper('a4', $orientation);

        return $pdf->download($filename.'.pdf');
    }

    /**
     * @param  array{title: string, headers: list<string>, rows: list<list<mixed>>, chart?: array{title: string, categoryCol: int, valueCol: int, excludeLastRows?: int}|null}  $block
     * @return array{0: int, 1: array{start: int, end: int, chart: array{title: string, categoryCol: int, valueCol: int, excludeLastRows?: int}}|null}
     */
    private function writeTrendBlock($sheet, array $block, int $row, string $lastCol, string $accentArgb): array
    {
        $headers = $block['headers'];
        $blockLastCol = $this->columnLetter(max(0, count($headers) - 1));

        $sheet->setCellValue("A{$row}", $block['title']);
        $sheet->mergeCells("A{$row}:{$lastCol}{$row}");
        $sheet->getStyle("A{$row}")->getFont()->setBold(true)->setSize(11);
        $row++;

        $headerRow = $row;
        foreach ($headers as $index => $header) {
            $sheet->setCellValue($this->columnLetter($index).$row, $header);
        }
        $this->styleTableHeader($sheet, "A{$headerRow}:{$blockLastCol}{$headerRow}", $accentArgb);
        $row++;

        $dataStart = $row;
        foreach ($block['rows'] as $rowData) {
            foreach ($rowData as $colIndex => $value) {
                $cell = $this->columnLetter($colIndex).$row;
                $sheet->setCellValue($cell, $value);
                if (is_numeric($value) && $colIndex > 0) {
                    $sheet->getStyle($cell)->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_NUMBER_COMMA_SEPARATED1);
                }
            }
            $row++;
        }

        $dataEnd = $row - 1;
        $sheet->getStyle("A{$headerRow}:{$blockLastCol}{$dataEnd}")
            ->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);

        $chartMeta = null;
        if (! empty($block['chart']) && $dataEnd >= $dataStart) {
            $chartMeta = [
                'start' => $dataStart,
                'end' => $dataEnd,
                'chart' => $block['chart'],
            ];
        }

        return [$row + 1, $chartMeta];
    }

    private function writeBrandFooter($sheet, int $row, string $lastCol): void
    {
        $taglineCell = "A{$row}";
        $sheet->setCellValue($taglineCell, ReportMetricsService::BRAND_TAGLINE);
        $sheet->mergeCells("{$taglineCell}:{$lastCol}{$row}");
        $sheet->getStyle($taglineCell)->getFont()->setBold(true)->getColor()->setARGB('FF2563EB');
        $sheet->getCell($taglineCell)->getHyperlink()->setUrl(ReportMetricsService::BRAND_CUSTOSELL_URL);

        $row++;
        $custosellCell = "A{$row}";
        $sheet->setCellValue($custosellCell, 'Powered by Custosell');
        $sheet->mergeCells("{$custosellCell}:{$lastCol}{$row}");
        $sheet->getStyle($custosellCell)->getFont()->setSize(9)->getColor()->setARGB('FF6B7280');
        $sheet->getCell($custosellCell)->getHyperlink()->setUrl(ReportMetricsService::BRAND_CUSTOSELL_URL);

        $row++;
        $custosparkCell = "A{$row}";
        $sheet->setCellValue($custosparkCell, 'A product of Custospark Company Ltd');
        $sheet->mergeCells("{$custosparkCell}:{$lastCol}{$row}");
        $sheet->getStyle($custosparkCell)->getFont()->setSize(9)->getColor()->setARGB('FF6B7280');
        $sheet->getCell($custosparkCell)->getHyperlink()->setUrl(ReportMetricsService::BRAND_CUSTOSPARK_URL);
    }

    /**
     * @param  array{title: string, categoryCol: int, valueCol: int}  $chart
     */
    private function attachBarChart($sheet, array $chart, int $start, int $end, int $chartTopRow, string $lastCol = 'H'): void
    {
        if ($end < $start) {
            return;
        }

        $catCol = $this->columnLetter($chart['categoryCol']);
        $valCol = $this->columnLetter($chart['valueCol']);
        $pointCount = $end - $start + 1;

        $seriesLabels = [new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_STRING, 'Report!$'.$valCol.'$'.($start - 1), null, 1)];
        $categories = [new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_STRING, 'Report!$'.$catCol.'$'.$start.':$'.$catCol.'$'.$end, null, $pointCount)];
        $values = [new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_NUMBER, 'Report!$'.$valCol.'$'.$start.':$'.$valCol.'$'.$end, null, $pointCount)];

        $series = new DataSeries(
            DataSeries::TYPE_BARCHART,
            DataSeries::GROUPING_CLUSTERED,
            range(0, count($values) - 1),
            $seriesLabels,
            $categories,
            $values,
        );
        $series->setPlotDirection(DataSeries::DIRECTION_COL);

        $plotArea = new PlotArea(null, [$series]);
        $legend = new Legend(Legend::POSITION_RIGHT, null, false);
        $chartObj = new Chart('chart_'.md5($chart['title'].$chartTopRow), new ChartTitle($chart['title']), $legend, $plotArea);
        $chartObj->setTopLeftPosition('A'.$chartTopRow);
        $chartObj->setBottomRightPosition($lastCol.($chartTopRow + 14));

        $sheet->addChart($chartObj);
    }

    private function styleHeaderBand($sheet, string $range, string $accentArgb, int $fontSize): void
    {
        $sheet->getStyle($range)->getFont()->setBold(true)->setSize($fontSize)->getColor()->setARGB('FFFFFFFF');
        $sheet->getStyle($range)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB($accentArgb);
        $sheet->getStyle($range)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    }

    private function styleTableHeader($sheet, string $range, string $accentArgb): void
    {
        $sheet->getStyle($range)->getFont()->setBold(true)->getColor()->setARGB('FFFFFFFF');
        $sheet->getStyle($range)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB($accentArgb);
        $sheet->getStyle($range)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    }

    private function columnLetter(int $index): string
    {
        $letter = '';
        $index++;

        while ($index > 0) {
            $mod = ($index - 1) % 26;
            $letter = chr(65 + $mod).$letter;
            $index = intdiv($index - 1, 26);
        }

        return $letter;
    }
}
