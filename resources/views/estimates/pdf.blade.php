@extends('reports.layouts.base')

@section('content')
@php
  $items = $estimate->lineItems ?? collect();
  $currency = $currency ?? ($business->currency ?? 'UGX');
  $statusLabel = $statusLabel ?? ucfirst(str_replace('_', ' ', (string) $estimate->status));
@endphp

<table style="width:100%; margin-bottom:14px; border-collapse:collapse;">
  <tr>
    <td style="width:52%; vertical-align:top; padding-right:16px;">
      <p style="font-size:8.5px; font-weight:bold; color:#6b7280; text-transform:uppercase; margin:0 0 6px 0; letter-spacing:0.4px;">Prepared For</p>
      @if($estimate->customer)
        <p style="font-size:11px; font-weight:bold; color:#111827; margin:0 0 3px 0;">{{ $estimate->customer->name }}</p>
        @if($estimate->customer->phone)
          <p style="font-size:9.5px; color:#4b5563; margin:0 0 2px 0;">Tel: {{ $estimate->customer->phone }}</p>
        @endif
        @if($estimate->customer->email ?? false)
          <p style="font-size:9.5px; color:#4b5563; margin:0;">{{ $estimate->customer->email }}</p>
        @endif
      @else
        <p style="font-size:10px; color:#6b7280; margin:0;">Prospective Customer</p>
      @endif
    </td>
    <td style="width:48%; vertical-align:top; text-align:right;">
      <p style="font-size:8.5px; font-weight:bold; color:#6b7280; text-transform:uppercase; margin:0 0 6px 0; letter-spacing:0.4px;">Estimate Details</p>
      <p style="font-size:9.5px; color:#374151; margin:0 0 2px 0;"><strong>Number:</strong> {{ $estimate->estimate_number }}</p>
      <p style="font-size:9.5px; color:#374151; margin:0 0 2px 0;"><strong>Version:</strong> {{ $estimate->version }}</p>
      @if($estimate->valid_until)
        <p style="font-size:9.5px; color:#374151; margin:0 0 2px 0;"><strong>Valid Until:</strong> {{ $estimate->valid_until->format('M d, Y') }}</p>
      @endif
      <p style="font-size:9.5px; color:#374151; margin:0;">
        <strong>Status:</strong> {{ $statusLabel }}
      </p>
    </td>
  </tr>
</table>

@if($estimate->title)
  <p style="font-size:12px; font-weight:bold; color:#111827; margin:0 0 12px 0;">{{ $estimate->title }}</p>
@endif

<div class="section-title">Scope of Work</div>

<table class="data">
  <colgroup>
    <col style="width:40%">
    <col style="width:10%">
    <col style="width:16%">
    <col style="width:17%">
    <col style="width:17%">
  </colgroup>
  <thead>
    <tr>
      <th class="text-left">Description</th>
      <th class="col-money">Qty</th>
      <th class="col-money">Unit Price</th>
      <th class="col-money">Amount</th>
      <th class="col-money">Type</th>
    </tr>
  </thead>
  <tbody>
    @forelse ($items as $item)
      <tr>
        <td class="text-left">
          {{ $item->description }}
          @if($item->product?->sku)
            <br><span class="text-muted">SKU: {{ $item->product->sku }}</span>
          @endif
        </td>
        <td class="col-money">{{ $formatter->formatTableNumber((float) $item->quantity) }}</td>
        <td class="col-money">{{ $formatter->formatMoney((float) $item->unit_price, $currency) }}</td>
        <td class="col-money amount-emphasis">{{ $formatter->formatMoney((float) $item->total_price, $currency) }}</td>
        <td class="col-money text-muted">{{ ucfirst($item->type) }}</td>
      </tr>
    @empty
      <tr>
        <td colspan="5" class="text-center text-muted">No line items</td>
      </tr>
    @endforelse
  </tbody>
</table>

<table class="data" style="margin-top:10px;width:52%;margin-left:auto;">
  <tbody>
    <tr>
      <td class="text-left">Subtotal</td>
      <td class="col-money">{{ $formatter->formatMoney((float) $estimate->subtotal, $currency) }}</td>
    </tr>
    @if((float) $estimate->discount_amount > 0)
      <tr>
        <td class="text-left">Discount</td>
        <td class="col-money">-{{ $formatter->formatMoney((float) $estimate->discount_amount, $currency) }}</td>
      </tr>
    @endif
    @if((float) $estimate->tax_total > 0)
      <tr>
        <td class="text-left">Tax ({{ number_format((float) $estimate->tax_rate, 2) }}%)</td>
        <td class="col-money">{{ $formatter->formatMoney((float) $estimate->tax_total, $currency) }}</td>
      </tr>
    @endif
    <tr class="total-row">
      <td class="text-left">Total</td>
      <td class="col-money">{{ $formatter->formatMoney((float) $estimate->total, $currency) }}</td>
    </tr>
  </tbody>
</table>

@if($estimate->notes)
  <div style="margin-top:14px;padding:10px 12px;background:#f9fafb;border:1px solid #e5e7eb;border-radius:6px;">
    <p style="font-size:8.5px;font-weight:bold;color:#6b7280;margin:0 0 4px 0;text-transform:uppercase;letter-spacing:0.3px;">Notes</p>
    <p style="font-size:9.5px;color:#374151;margin:0;line-height:1.5;">{{ $estimate->notes }}</p>
  </div>
@endif

@if($estimate->terms)
  <div style="margin-top:12px;padding:10px 12px;background:#f0fdf4;border:1px solid #bbf7d0;border-radius:6px;">
    <p style="font-size:8.5px;font-weight:bold;color:#166534;margin:0 0 4px 0;text-transform:uppercase;letter-spacing:0.3px;">Terms & Conditions</p>
    <p style="font-size:9.5px;color:#374151;margin:0;line-height:1.5;">{{ $estimate->terms }}</p>
  </div>
@endif

@if($estimate->createdBy)
  <p style="margin-top:12px;font-size:8.5px;color:#9ca3af;">
    Prepared by {{ $estimate->createdBy->name ?? 'Staff' }}
  </p>
@endif
@endsection
