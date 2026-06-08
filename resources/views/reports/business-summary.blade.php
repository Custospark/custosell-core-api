@extends('reports.layouts.base')

@section('content')
  @php $ccy = $business->currency; @endphp
  @include('reports.partials.trend-chart', ['trend' => $trend ?? [], 'accent' => $accent ?? '#1e40af'])

  <table class="data">
    <colgroup>
      <col style="width:62%">
      <col style="width:38%">
    </colgroup>
    <thead>
      <tr>
        <th class="text-left">Metric</th>
        <th class="col-money">Amount ({{ $ccy }})</th>
      </tr>
    </thead>
    <tbody>
      <tr><td class="text-left">Gross Sales</td><td class="col-money">{{ $formatter->formatTableNumber($summary['gross_sales']) }}</td></tr>
      <tr><td class="text-left text-red">Refunds</td><td class="col-money text-red">-{{ $formatter->formatTableNumber($summary['refunds']) }}</td></tr>
      <tr><td class="text-left text-red">Expenses</td><td class="col-money text-red">-{{ $formatter->formatTableNumber($summary['expenses']) }}</td></tr>
      <tr class="total-row"><td class="text-left">Net Sales</td><td class="col-money amount-emphasis">{{ $formatter->formatTableNumber($summary['net_sales']) }}</td></tr>
      <tr><td class="text-left">Transactions</td><td class="col-num">{{ $summary['transactions'] }}</td></tr>
      <tr><td class="text-left">Refund rate</td><td class="col-num">{{ $summary['refund_rate_pct'] }}%</td></tr>
      <tr><td class="text-left">Expense ratio</td><td class="text-left">{{ $summary['expense_ratio_pct'] }}% of gross</td></tr>
    </tbody>
  </table>
@endsection
