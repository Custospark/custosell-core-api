@extends('reports.layouts.base')

@section('content')
  @php $ccy = $business->currency; @endphp
  <table class="data">
    <colgroup>
      <col style="width:11%">
      <col style="width:8%">
      <col style="width:10%">
      <col style="width:4%">
      <col style="width:9%">
      <col style="width:9%">
      <col style="width:15%">
      <col style="width:14%">
      <col style="width:20%">
    </colgroup>
    <thead>
      <tr>
        <th class="text-left">Receipt</th>
        <th class="text-left">Date</th>
        <th class="text-left">Cashier</th>
        <th class="col-center">Items</th>
        <th class="text-left">Payment</th>
        <th class="text-left">Status</th>
        <th class="col-money">Gross ({{ $ccy }})</th>
        <th class="col-money">Refunds ({{ $ccy }})</th>
        <th class="col-money">Net ({{ $ccy }})</th>
      </tr>
    </thead>
    <tbody>
      @foreach($sales as $sale)
        @php
          $row = $metrics->saleRow($sale);
          $badgeClass = match($sale->payment_status) {
            'refunded' => 'badge-refunded',
            'partially_refunded' => 'badge-partial',
            default => 'badge-paid',
          };
        @endphp
        <tr>
          <td class="text-left">
            {{ $sale->receipt_number }}@if($sale->customer?->name)<br><span class="text-muted">{{ $sale->customer->name }}</span>@endif
          </td>
          <td class="text-left">{{ $sale->sale_date->format('M d, Y') }}</td>
          <td class="text-left">{{ $sale->user?->name ?? 'N/A' }}</td>
          <td class="text-center col-num">{{ $sale->saleItems->count() }}</td>
          <td class="text-left">{{ $metrics->paymentMethodLabel($metrics->normalizePaymentMethod($sale->payment_method)) }}</td>
          <td class="text-left"><span class="badge {{ $badgeClass }}">{{ $metrics->paymentStatusLabel($sale->payment_status) }}</span></td>
          <td class="col-money">{{ $formatter->formatTableNumber($row['gross']) }}</td>
          <td class="col-money {{ $row['refunds'] > 0 ? 'text-red' : '' }}">{{ $row['refunds'] > 0 ? '-'.$formatter->formatTableNumber($row['refunds']) : $formatter->formatTableNumber(0) }}</td>
          <td class="col-money amount-emphasis">{{ $formatter->formatTableNumber($row['net_after_refunds']) }}</td>
        </tr>
        @if($sale->saleItems->isNotEmpty())
          <tr class="line-items-row">
            <td colspan="9" class="line-items text-left">
              @foreach($sale->saleItems as $item)
                <span>{{ $item->product_name }} x {{ $item->quantity }} @ {{ $formatter->formatTableNumber((float)$item->unit_price) }}@if($item->refunded_quantity > 0) ({{ $item->refunded_quantity }} refunded)@endif</span>
              @endforeach
            </td>
          </tr>
        @endif
      @endforeach
      <tr class="total-row">
        <td colspan="6" class="text-left">Receipt totals</td>
        <td class="col-money">{{ $formatter->formatTableNumber($saleTotals['gross']) }}</td>
        <td class="col-money text-red">-{{ $formatter->formatTableNumber($saleTotals['refunds']) }}</td>
        <td class="col-money">{{ $formatter->formatTableNumber($saleTotals['net_after_refunds']) }}</td>
      </tr>
      <tr class="total-row">
        <td colspan="6" class="text-left">Period expenses</td>
        <td class="col-money text-muted">-</td>
        <td class="col-money text-muted">-</td>
        <td class="col-money text-red">-{{ $formatter->formatTableNumber($period['expenses']) }}</td>
      </tr>
      <tr class="total-row">
        <td colspan="6" class="text-left">{{ \App\Services\ReportExportService::NET_SALES_FORMULA_LABEL }}</td>
        <td class="col-money text-muted">-</td>
        <td class="col-money text-muted">-</td>
        <td class="col-money amount-emphasis">{{ $formatter->formatTableNumber($period['net_sales']) }}</td>
      </tr>
    </tbody>
  </table>
@endsection
