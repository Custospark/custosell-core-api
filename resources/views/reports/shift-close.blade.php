@extends('reports.layouts.base')

@section('content')
  @php
    $ccy = $business->currency;
    $shift = $report['shift'];
  @endphp

  <p class="ranking-subtitle" style="text-align:center;margin-bottom:12px;">
    {{ $report['cashier'] }}
    · In {{ $shift->clock_in->format('M d, Y H:i') }}
    @if($shift->clock_out)
      · Out {{ $shift->clock_out->format('M d, Y H:i') }}
      @if(!empty($report['duration'])) · {{ $report['duration'] }} @endif
    @else
      · As of {{ now()->format('M d, Y H:i') }}
    @endif
  </p>

  <p class="ranking-subtitle" style="text-align:center;margin-bottom:10px;">
    Net sales = gross sales - refunds - shift expenses
  </p>

  <p class="section-title">Shift totals</p>
  <table class="data">
    <colgroup><col style="width:62%"><col style="width:38%"></colgroup>
    <thead>
      <tr>
        <th class="text-left">Item</th>
        <th class="col-money">{{ $ccy }}</th>
      </tr>
    </thead>
    <tbody>
      <tr><td class="text-left">Gross sales</td><td class="col-money">{{ $formatter->formatTableNumber($report['gross_sales']) }}</td></tr>
      @if($report['refunds'] > 0)
        <tr><td class="text-left text-red">Refunds</td><td class="col-money text-red">-{{ $formatter->formatTableNumber($report['refunds']) }}</td></tr>
      @endif
      @if($report['shift_expenses'] > 0)
        <tr><td class="text-left text-red">Shift expenses</td><td class="col-money text-red">-{{ $formatter->formatTableNumber($report['shift_expenses']) }}</td></tr>
      @endif
      <tr class="total-row"><td class="text-left">Net sales</td><td class="col-money amount-emphasis">{{ $formatter->formatTableNumber($report['net_sales']) }}</td></tr>
      <tr><td class="text-left text-muted" style="padding-left:14px;">Cash collected</td><td class="col-money">{{ $formatter->formatTableNumber($report['cash']) }}</td></tr>
      <tr><td class="text-left text-muted" style="padding-left:14px;">Mobile money</td><td class="col-money">{{ $formatter->formatTableNumber($report['mobile_money']) }}</td></tr>
      <tr><td class="text-left text-muted" style="padding-left:14px;">Card / other</td><td class="col-money">{{ $formatter->formatTableNumber($report['card_other']) }}</td></tr>
      <tr class="total-row"><td class="text-left">Cash at handover</td><td class="col-money amount-emphasis">{{ $formatter->formatTableNumber($report['cash_handover']) }}</td></tr>
    </tbody>
  </table>

  <div style="margin:16px 0;padding:14px 16px;text-align:center;border:2px solid {{ $accent }};background:#eff6ff;border-radius:8px;">
    <p style="margin:0 0 4px 0;font-size:9px;font-weight:bold;text-transform:uppercase;color:#1d4ed8;letter-spacing:0.3px;">
      Cash at Handover
    </p>
    <p style="margin:0;font-size:20px;font-weight:bold;color:#1e3a8a;">
      {{ $formatter->formatMoney($report['cash_handover'], $ccy) }}
    </p>
    <p style="margin:6px 0 0 0;font-size:8.5px;color:#6b7280;">
      Cash collected minus shift expenses paid from the drawer
    </p>
  </div>

  <p class="ranking-subtitle" style="text-align:center;">
    {{ $report['transaction_count'] }} transaction{{ $report['transaction_count'] === 1 ? '' : 's' }} this shift
  </p>
@endsection
