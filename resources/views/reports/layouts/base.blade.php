<!DOCTYPE html>
<html>
<head>
  <meta charset="utf-8">
  <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
  <title>{{ $business->name }} - {{ $reportTitle }}</title>
  <style>
    @page { margin: 28px 24px; }
    * { box-sizing: border-box; }
    body {
      font-family: DejaVu Sans, sans-serif;
      font-size: 10px;
      line-height: 1.5;
      color: #1f2937;
      margin: 0;
      padding: 0;
    }
    .header {
      text-align: center;
      margin-bottom: 16px;
      border-bottom: 2px solid {{ $accent }};
      padding-bottom: 12px;
    }
    .header h1 {
      font-family: DejaVu Sans, sans-serif;
      font-size: 20px;
      font-weight: bold;
      color: {{ $accent }};
      margin: 0 0 4px 0;
      text-transform: uppercase;
      letter-spacing: 0.3px;
    }
    .header p { margin: 1px 0; color: #4b5563; font-size: 9.5px; }
    .report-title { text-align: center; margin-bottom: 8px; }
    .report-title h2 {
      font-family: DejaVu Sans, sans-serif;
      font-size: 15px;
      font-weight: bold;
      color: #111827;
      margin: 0;
    }
    .report-title p { font-size: 9.5px; color: #6b7280; margin: 3px 0 0 0; }
    .report-purpose {
      text-align: center;
      font-size: 9.5px;
      color: #2563eb;
      font-style: italic;
      margin-bottom: 12px;
    }
    .insights {
      background: #f0f9ff;
      border: 1px solid #bae6fd;
      padding: 10px 12px;
      margin-bottom: 14px;
    }
    .insights h4 {
      margin: 0 0 6px 0;
      font-size: 10px;
      font-weight: bold;
      color: #0369a1;
      text-transform: uppercase;
    }
    .insights ul { margin: 0; padding-left: 16px; }
    .insights li { margin: 2px 0; font-size: 9.5px; color: #0c4a6e; }
    .summary-grid {
      display: table;
      width: 100%;
      margin: 0 0 14px 0;
      border-collapse: separate;
      border-spacing: 6px 0;
    }
    .summary-card {
      display: table-cell;
      width: 25%;
      background: #f9fafb;
      border: 1px solid #e5e7eb;
      padding: 8px;
      vertical-align: top;
    }
    .summary-card .label {
      font-size: 8.5px;
      text-transform: uppercase;
      color: #6b7280;
      margin-bottom: 4px;
    }
    .summary-card .value {
      font-size: 12px;
      font-weight: bold;
      color: #111827;
      line-height: 1.3;
      word-wrap: break-word;
    }
    .summary-card .value.negative { color: #dc2626; }
    .summary-card .value.positive { color: #16a34a; }
    table.data {
      width: 100%;
      border-collapse: collapse;
      margin-top: 8px;
      table-layout: fixed;
      font-family: DejaVu Sans, sans-serif;
    }
    table.data thead { display: table-header-group; }
    table.data th,
    table.data td {
      border: 1px solid #cbd5e1;
      padding: 6px 5px;
      font-size: 9px;
      line-height: 1.4;
      vertical-align: top;
      font-family: DejaVu Sans, sans-serif;
      overflow: hidden;
    }
    table.data th {
      background: {{ $accent }};
      color: #ffffff;
      text-align: left;
      font-size: 8px;
      font-weight: bold;
      text-transform: uppercase;
      vertical-align: middle;
      padding-top: 7px;
      padding-bottom: 7px;
    }
    table.data tbody tr:nth-child(even) td { background: #f9fafb; }
    table.data tbody tr.line-items-row td {
      background: #f3f4f6 !important;
      border-top: none;
      padding-top: 3px;
      padding-bottom: 5px;
    }
    .text-right { text-align: right !important; }
    .text-center { text-align: center !important; }
    .text-left { text-align: left !important; word-wrap: break-word; }
    table.data td.col-money,
    table.data td.col-num,
    table.data th.col-money,
    table.data th.col-num {
      text-align: right !important;
      white-space: normal;
      word-wrap: break-word;
      font-size: 8.5px;
      line-height: 1.45;
      padding-left: 4px;
      padding-right: 5px;
    }
    table.data th.col-center { text-align: center !important; }
    .amount-emphasis { font-weight: bold; }
    .text-red { color: #dc2626; font-weight: bold; }
    .text-muted { color: #6b7280; font-size: 8.5px; }
    .total-row td {
      font-weight: bold;
      border-top: 2px solid {{ $accent }};
      background: #f8fafc !important;
      vertical-align: middle;
    }
    .section-title {
      font-size: 11px;
      font-weight: bold;
      color: #374151;
      margin: 14px 0 6px 0;
    }
    .line-items { margin: 0; font-size: 8.5px; color: #4b5563; line-height: 1.5; }
    .line-items span { display: inline; margin-right: 8px; }
    .badge {
      display: inline-block;
      padding: 2px 5px;
      font-size: 8px;
      font-weight: bold;
      line-height: 1.2;
    }
    .badge-paid { background: #dcfce7; color: #166534; }
    .badge-partial { background: #fef3c7; color: #92400e; }
    .badge-refunded { background: #fee2e2; color: #991b1b; }
    .footer {
      text-align: center;
      color: #9ca3af;
      font-size: 8.5px;
      margin-top: 20px;
      border-top: 1px solid #e5e7eb;
      padding-top: 10px;
    }
    .brand-tagline { color: #2563eb; font-size: 9.5px; font-weight: bold; margin-bottom: 2px; }
    .brand-footer { color: #6b7280; font-size: 8.5px; }
    .brand-footer a, .brand-tagline a { color: #2563eb; text-decoration: underline; }
    .trend-chart-wrap { margin: 0 0 14px 0; page-break-inside: avoid; }
    table.chart-trend {
      width: 100%;
      border-collapse: collapse;
      margin-bottom: 4px;
      font-family: DejaVu Sans, sans-serif;
    }
    table.chart-trend th,
    table.chart-trend td {
      border: 1px solid #d1d5db;
      padding: 5px 5px;
      font-size: 8.5px;
      vertical-align: middle;
    }
    table.chart-trend th {
      background: #f1f5f9;
      color: #374151;
      font-weight: bold;
    }
    table.chart-trend tbody tr:nth-child(even) td { background: #fafafa; }
    .chart-bar-cell { padding: 4px 5px; }
    .bar-track { border-collapse: collapse; }
    .chart-legend { font-size: 9px; color: #4b5563; margin: 0 0 8px 0; }
    .legend-item { margin-right: 16px; }
    .legend-swatch {
      display: inline-block;
      width: 10px;
      height: 10px;
      margin-right: 4px;
      vertical-align: middle;
      border: 1px solid #cbd5e1;
    }
    .legend-gross { background-color: #e2e8f0; }
    .chart-caption { font-size: 8.5px; color: #6b7280; margin: 6px 0 0 0; }
    .ranking-subtitle { font-size: 8.5px; color: #6b7280; margin: 0 0 6px 0; }
    table.ranking-table { margin-bottom: 12px; }
  </style>
</head>
<body>
  <div class="header">
    <h1>{{ $business->name }}</h1>
    @if($business->address)<p>{{ $business->address }}</p>@endif
    @php $location = collect([$business->city, $business->state, $business->country])->filter()->implode(', '); @endphp
    @if($location)<p>{{ $location }}</p>@endif
    @if($business->phone)<p>Tel: {{ $business->phone }}</p>@endif
    @if($business->email)<p>{{ $business->email }}</p>@endif
    @if($business->tax_id)<p>Tax ID: {{ $business->tax_id }}</p>@endif
    @if($business->website)<p>{{ $business->website }}</p>@endif
  </div>

  <div class="report-title">
    <h2>{{ $reportTitle }}</h2>
    @if(!empty($reportSubtitle))<p>{{ $reportSubtitle }}</p>@endif
  </div>

  @if(!empty($reportPurpose))
    <div class="report-purpose">{{ $reportPurpose }}</div>
  @endif

  @if(!empty($summaryCards))
    <div class="summary-grid">
      @foreach(array_slice($summaryCards, 0, 4) as $card)
        <div class="summary-card">
          <div class="label">{{ $card['label'] }}</div>
          <div class="value {{ $card['tone'] ?? '' }}">{{ $card['value'] }}</div>
        </div>
      @endforeach
    </div>
  @endif

  @if(!empty($insights) && !empty($insights['best_day']))
    <div class="insights">
      <h4>Key Insights</h4>
      <ul>
        @if(!empty($insights['best_day']))
          <li>Best day: {{ $insights['best_day']['date'] }} - {{ $formatter->formatMoney($insights['best_day']['net_sales'], $business->currency) }} net sales</li>
        @endif
        @if(!empty($insights['worst_day']))
          <li>Weakest day: {{ $insights['worst_day']['date'] }} - {{ $formatter->formatMoney($insights['worst_day']['net_sales'], $business->currency) }} net sales</li>
        @endif
        <li>Average net sales/day: {{ $formatter->formatMoney($insights['avg_net_sales'], $business->currency) }}</li>
        <li>Refund rate: {{ $insights['refund_rate_pct'] }}% | Expense ratio: {{ $insights['expense_ratio_pct'] }}% of gross</li>
      </ul>
    </div>
  @endif

  @yield('content')

  <div class="footer">
    @if($business->receipt_footer)<p>{{ $business->receipt_footer }}</p>@endif
    <p class="brand-tagline">
      <a href="{{ \App\Services\ReportMetricsService::BRAND_CUSTOSELL_URL }}">{{ $brandTagline ?? config('brand.tagline') }}</a>
    </p>
    <p class="brand-footer">
      Powered by <a href="{{ \App\Services\ReportMetricsService::BRAND_CUSTOSELL_URL }}">Custosell</a>
      | A product of <a href="{{ \App\Services\ReportMetricsService::BRAND_CUSTOSPARK_URL }}">Custospark Company Ltd</a>
    </p>
    <p>Generated on {{ now()->format('M d, Y H:i:s') }} - {{ $business->name }}</p>
  </div>
</body>
</html>
