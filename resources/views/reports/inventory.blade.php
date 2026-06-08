@extends('reports.layouts.base')

@section('content')
  @php $ccy = $business->currency; @endphp
  <table class="data">
    <colgroup>
      <col style="width:18%">
      <col style="width:13%">
      <col style="width:10%">
      <col style="width:7%">
      <col style="width:7%">
      <col style="width:13%">
      <col style="width:15%">
      <col style="width:17%">
    </colgroup>
    <thead>
      <tr>
        <th class="text-left">Product</th>
        <th class="text-left">Category</th>
        <th class="text-left">SKU</th>
        <th class="col-num">Stock</th>
        <th class="col-num">Min</th>
        <th class="col-money">Unit ({{ $ccy }})</th>
        <th class="col-money">Value ({{ $ccy }})</th>
        <th class="text-left">Status</th>
      </tr>
    </thead>
    <tbody>
      @foreach($products as $product)
        @php
          $stockValue = (float) $product->unit_price * (int) $product->stock_quantity;
          $isLow = $product->stock_quantity <= $product->low_stock_threshold;
        @endphp
        <tr>
          <td class="text-left">{{ $product->name }}</td>
          <td class="text-left">{{ $product->category?->name ?? 'N/A' }}</td>
          <td class="text-left">{{ $product->sku ?? 'N/A' }}</td>
          <td class="col-num {{ $isLow ? 'text-red' : '' }}">{{ $product->stock_quantity }}</td>
          <td class="col-num">{{ $product->low_stock_threshold }}</td>
          <td class="col-money">{{ $formatter->formatTableNumber((float) $product->unit_price) }}</td>
          <td class="col-money">{{ $formatter->formatTableNumber($stockValue) }}</td>
          <td class="text-left">{{ $product->is_active ? ($isLow ? 'Low stock' : 'Active') : 'Inactive' }}</td>
        </tr>
      @endforeach
      <tr class="total-row">
        <td colspan="6" class="text-left">Total inventory value</td>
        <td class="col-money amount-emphasis">{{ $formatter->formatTableNumber($totalValue) }}</td>
        <td class="text-left">{{ $lowStockCount }} low stock</td>
      </tr>
    </tbody>
  </table>
@endsection
