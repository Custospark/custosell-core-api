@extends('reports.layouts.base')

@section('content')
@php $items = $invoice->items ?? []; @endphp

<table class="data">
  <colgroup><col style="width:50%"><col style="width:15%"><col style="width:15%"><col style="width:20%"></colgroup>
  <thead>
    <tr><th class="text-left">Description</th><th class="col-money">Qty</th><th class="col-money">Price</th><th class="col-money">Subtotal</th></tr>
  </thead>
  <tbody>
    @foreach ($items as $item)
      <tr>
        <td class="text-left">{{ $item->description }}</td>
        <td class="col-money">{{ $item->quantity }}</td>
        <td class="col-money">{{ $formatter->formatTableNumber($item->unit_price) }}</td>
        <td class="col-money">{{ $formatter->formatTableNumber($item->subtotal) }}</td>
      </tr>
    @endforeach
  </tbody>
</table>

<table class="data" style="margin-top:8px;width:50%;margin-left:auto">
  <tr><td class="text-left">Subtotal</td><td class="col-money">{{ $formatter->formatTableNumber($invoice->subtotal) }}</td></tr>
  <tr><td class="text-left">Tax</td><td class="col-money">{{ $formatter->formatTableNumber($invoice->tax_total) }}</td></tr>
  <tr class="total-row"><td class="text-left">Total</td><td class="col-money">{{ $formatter->formatTableNumber($invoice->total_amount) }}</td></tr>
  <tr><td class="text-left">Paid</td><td class="col-money">{{ $formatter->formatTableNumber($invoice->amount_paid) }}</td></tr>
  <tr><td class="text-left">Balance Due</td><td class="col-money">{{ $formatter->formatTableNumber($invoice->total_amount - $invoice->amount_paid) }}</td></tr>
</table>

@if($invoice->notes)
  <div style="margin-top:12px;padding:10px;background:#f9fafb;border:1px solid #e5e7eb;border-radius:6px;">
    <p style="font-size:9px;font-weight:bold;color:#6b7280;margin:0 0 4px 0;text-transform:uppercase;">Notes</p>
    <p style="font-size:9.5px;color:#374151;margin:0;">{{ $invoice->notes }}</p>
  </div>
@endif
@endsection
