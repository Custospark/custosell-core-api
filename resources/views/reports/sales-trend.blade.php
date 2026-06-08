@extends('reports.layouts.base')

@section('content')
  @php $ccy = $business->currency; @endphp
  <div class="section-title">Daily breakdown ({{ $ccy }})</div>
  <table class="data">
    <colgroup>
      <col style="width:16%">
      <col style="width:17%">
      <col style="width:17%">
      <col style="width:17%">
      <col style="width:18%">
      <col style="width:15%">
    </colgroup>
    <thead>
      <tr>
        <th class="text-left">Date</th>
        <th class="col-money">Gross ({{ $ccy }})</th>
        <th class="col-money">Refunds ({{ $ccy }})</th>
        <th class="col-money">Expenses ({{ $ccy }})</th>
        <th class="col-money">Net Sales ({{ $ccy }})</th>
        <th class="col-num">Txns</th>
      </tr>
    </thead>
    <tbody>
      @foreach($trend as $day)
        <tr>
          <td class="text-left">{{ \Carbon\Carbon::parse($day['date'])->format('M d, Y') }}</td>
          <td class="col-money">{{ $formatter->formatTableNumber($day['gross_sales']) }}</td>
          <td class="col-money {{ $day['refunds'] > 0 ? 'text-red' : '' }}">{{ $day['refunds'] > 0 ? '-'.$formatter->formatTableNumber($day['refunds']) : $formatter->formatTableNumber(0) }}</td>
          <td class="col-money {{ $day['expenses'] > 0 ? 'text-red' : '' }}">{{ $day['expenses'] > 0 ? '-'.$formatter->formatTableNumber($day['expenses']) : $formatter->formatTableNumber(0) }}</td>
          <td class="col-money amount-emphasis">{{ $formatter->formatTableNumber($day['net_sales']) }}</td>
          <td class="col-num">{{ $day['transactions'] }}</td>
        </tr>
      @endforeach
      <tr class="total-row">
        <td class="text-left">Total</td>
        <td class="col-money">{{ $formatter->formatTableNumber($totals['gross_sales']) }}</td>
        <td class="col-money text-red">-{{ $formatter->formatTableNumber($totals['refunds']) }}</td>
        <td class="col-money text-red">-{{ $formatter->formatTableNumber($totals['expenses']) }}</td>
        <td class="col-money">{{ $formatter->formatTableNumber($totals['net_sales']) }}</td>
        <td class="col-num">{{ $totals['transactions'] }}</td>
      </tr>
    </tbody>
  </table>
@endsection
