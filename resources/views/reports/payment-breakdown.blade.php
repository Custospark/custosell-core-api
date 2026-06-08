@extends('reports.layouts.base')

@section('content')
  @php $ccy = $business->currency; @endphp
  <table class="data">
    <colgroup>
      <col style="width:20%">
      <col style="width:12%">
      <col style="width:17%">
      <col style="width:17%">
      <col style="width:22%">
      <col style="width:12%">
    </colgroup>
    <thead>
      <tr>
        <th class="text-left">Method</th>
        <th class="col-num">Txns</th>
        <th class="col-money">Gross ({{ $ccy }})</th>
        <th class="col-money">Refunds ({{ $ccy }})</th>
        <th class="col-money">Net ({{ $ccy }})</th>
        <th class="col-num">Share</th>
      </tr>
    </thead>
    <tbody>
      @foreach($breakdown as $row)
        <tr>
          <td class="text-left">{{ $row['label'] }}</td>
          <td class="col-num">{{ $row['count'] }}</td>
          <td class="col-money">{{ $formatter->formatTableNumber($row['gross']) }}</td>
          <td class="col-money {{ $row['refunds'] > 0 ? 'text-red' : '' }}">{{ $row['refunds'] > 0 ? '-'.$formatter->formatTableNumber($row['refunds']) : $formatter->formatTableNumber(0) }}</td>
          <td class="col-money amount-emphasis">{{ $formatter->formatTableNumber($row['net']) }}</td>
          <td class="col-num">{{ $row['share_pct'] }}%</td>
        </tr>
      @endforeach
      <tr class="total-row">
        <td class="text-left">Total</td>
        <td class="col-num">{{ $totals['count'] }}</td>
        <td class="col-money">{{ $formatter->formatTableNumber($totals['gross']) }}</td>
        <td class="col-money text-red">-{{ $formatter->formatTableNumber($totals['refunds']) }}</td>
        <td class="col-money">{{ $formatter->formatTableNumber($totals['net']) }}</td>
        <td class="col-num">100%</td>
      </tr>
    </tbody>
  </table>
@endsection
