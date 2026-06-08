@extends('reports.layouts.base')

@section('content')
  @php $ccy = $business->currency; @endphp

  @include('reports.partials.product-ranking', [
    'title' => 'Top sellers by net revenue',
    'subtitle' => 'Highest earning products after refunds for this period.',
    'rows' => $top_by_net ?? $top ?? [],
  ])

  @include('reports.partials.product-ranking', [
    'title' => 'Top sellers by quantity',
    'subtitle' => 'Products that moved the most units in this period.',
    'rows' => $top_by_quantity ?? [],
  ])

  @include('reports.partials.product-ranking', [
    'title' => 'Slow movers (lowest units sold)',
    'subtitle' => 'Products that sold, but at the lowest volume. Consider promotions or discontinuing.',
    'rows' => $slowest_sold ?? $bottom ?? [],
  ])

  @if(!empty($no_sales) && count($no_sales) > 0)
    <div class="section-title">No sales this period ({{ $no_sales_count ?? count($no_sales) }} active products)</div>
    <p class="ranking-subtitle">Active catalog products with zero units sold in the selected date range.</p>
    <table class="data ranking-table">
      <colgroup>
        <col style="width:70%">
        <col style="width:30%">
      </colgroup>
      <thead>
        <tr>
          <th class="text-left">Product</th>
          <th class="col-num">Units sold</th>
        </tr>
      </thead>
      <tbody>
        @foreach(array_slice($no_sales, 0, 15) as $product)
          <tr>
            <td class="text-left">{{ $product['product_name'] }}</td>
            <td class="col-num">0</td>
          </tr>
        @endforeach
      </tbody>
    </table>
    @if(($no_sales_count ?? count($no_sales)) > 15)
      <p class="ranking-subtitle">Showing 15 of {{ $no_sales_count ?? count($no_sales) }} products with no sales.</p>
    @endif
  @endif

  <div class="section-title">Full product breakdown ({{ $ccy }})</div>
  <table class="data">
    <colgroup>
      <col style="width:38%">
      <col style="width:10%">
      <col style="width:17%">
      <col style="width:17%">
      <col style="width:18%">
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
      @forelse($products as $product)
        <tr>
          <td class="text-left">{{ $product['product_name'] }}</td>
          <td class="col-num">{{ $product['quantity_sold'] }}</td>
          <td class="col-money">{{ $formatter->formatTableNumber($product['gross_revenue']) }}</td>
          <td class="col-money {{ $product['refunds'] > 0 ? 'text-red' : '' }}">{{ $product['refunds'] > 0 ? '-'.$formatter->formatTableNumber($product['refunds']) : $formatter->formatTableNumber(0) }}</td>
          <td class="col-money amount-emphasis">{{ $formatter->formatTableNumber($product['net_after_refunds']) }}</td>
        </tr>
      @empty
        <tr>
          <td colspan="5" class="text-center text-muted">No product sales found for this period.</td>
        </tr>
      @endforelse
    </tbody>
  </table>
@endsection
