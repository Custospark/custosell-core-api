@php
  $ccy = $business->currency;
  $rankRows = $rows ?? [];
@endphp
@if(count($rankRows) > 0)
  <div class="section-title">{{ $title }}</div>
  @if(!empty($subtitle))
    <p class="ranking-subtitle">{{ $subtitle }}</p>
  @endif
  <table class="data ranking-table">
    <colgroup>
      <col style="width:40%">
      <col style="width:12%">
      <col style="width:16%">
      <col style="width:16%">
      <col style="width:16%">
    </colgroup>
    <thead>
      <tr>
        <th class="text-left">Product</th>
        <th class="col-num">Qty</th>
        <th class="col-money">Gross ({{ $ccy }})</th>
        <th class="col-money">Refunds ({{ $ccy }})</th>
        <th class="col-money">Net ({{ $ccy }})</th>
      </tr>
    </thead>
    <tbody>
      @foreach($rankRows as $product)
        <tr>
          <td class="text-left">{{ $product['product_name'] }}</td>
          <td class="col-num">{{ $product['quantity_sold'] }}</td>
          <td class="col-money">{{ $formatter->formatTableNumber($product['gross_revenue']) }}</td>
          <td class="col-money {{ $product['refunds'] > 0 ? 'text-red' : '' }}">{{ $product['refunds'] > 0 ? '-'.$formatter->formatTableNumber($product['refunds']) : $formatter->formatTableNumber(0) }}</td>
          <td class="col-money amount-emphasis">{{ $formatter->formatTableNumber($product['net_after_refunds']) }}</td>
        </tr>
      @endforeach
    </tbody>
  </table>
@endif
