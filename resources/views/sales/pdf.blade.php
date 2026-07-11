@extends('reports.layouts.base')

@section('content')
@php
  $items = $sale->saleItems ?? collect();
  $currency = $currency ?? ($business->currency ?? 'UGX');
  $cashierName = $sale->user?->name ?? '—';
  $customer = $sale->customer;
  $discount = (float) $sale->discount_amount;
  $taxTotal = (float) $sale->tax_total;
  $tenderedRaw = $sale->amount_tendered ? (float) $sale->amount_tendered : null;
  $changeRaw = $sale->change_given ? (float) $sale->change_given : null;
  $payments = $sale->payments ?? collect();
  $hasInstallments = $payments->count() > 0;
  $displayTendered = $hasInstallments
    ? ($payments[0]->amount_tendered ?? $payments[0]->amount ?? $tenderedRaw)
    : $tenderedRaw;
  $displayChange = $hasInstallments
    ? ($payments[0]->change_given ?? $changeRaw)
    : $changeRaw;
  $location = collect([$business->address, $business->city ?? $business->state, $business->country])
    ->filter(fn($v) => !empty($v))->join(', ');
@endphp

<table style="width:100%; margin-bottom:14px; border-collapse:collapse;">
  <tr>
    <td style="width:52%; vertical-align:top; padding-right:16px;">
      <p style="font-size:8.5px; font-weight:bold; color:#6b7280; text-transform:uppercase; margin:0 0 6px 0; letter-spacing:0.4px;">Customer</p>
      @if($customer)
        <p style="font-size:11px; font-weight:bold; color:#111827; margin:0 0 3px 0;">{{ $customer->name }}</p>
        @if($customer->phone)
          <p style="font-size:9.5px; color:#4b5563; margin:0 0 2px 0;">Tel: {{ $customer->phone }}</p>
        @endif
        @if($customer->email ?? false)
          <p style="font-size:9.5px; color:#4b5563; margin:0;">{{ $customer->email }}</p>
        @endif
      @else
        <p style="font-size:10px; color:#6b7280; margin:0;">Walk-in Customer</p>
      @endif
      <p style="font-size:8.5px; color:#9ca3af; margin-top:6px;">Cashier: {{ $cashierName }}</p>
    </td>
    <td style="width:48%; vertical-align:top; text-align:right;">
      <p style="font-size:8.5px; font-weight:bold; color:#6b7280; text-transform:uppercase; margin:0 0 6px 0; letter-spacing:0.4px;">Receipt Details</p>
      <p style="font-size:9.5px; color:#374151; margin:0 0 2px 0;"><strong>Number:</strong> {{ $sale->receipt_number }}</p>
      <p style="font-size:9.5px; color:#374151; margin:0 0 2px 0;"><strong>Date:</strong> {{ $sale->created_at?->format('M d, Y H:i') }}</p>
      <p style="font-size:9.5px; color:#374151; margin:0;">
        <strong>Status:</strong>
        @if($sale->payment_status === 'refunded')
          <span class="badge badge-refunded">Refunded</span>
        @elseif($sale->payment_status === 'partially_refunded')
          <span class="badge badge-partial">Partially Refunded</span>
        @elseif($balanceDue > 0.009)
          <span class="badge badge-partial">Partially Paid</span>
        @else
          <span class="badge badge-paid">Paid</span>
        @endif
      </p>
    </td>
  </tr>
</table>

<div class="section-title">Items</div>

<table class="data">
  <colgroup>
    <col style="width:46%">
    <col style="width:14%">
    <col style="width:20%">
    <col style="width:20%">
  </colgroup>
  <thead>
    <tr>
      <th class="text-left">Item</th>
      <th class="col-money">Qty</th>
      <th class="col-money">Price</th>
      <th class="col-money">Total</th>
    </tr>
  </thead>
  <tbody>
    @forelse ($items as $item)
      <tr>
        <td class="text-left">
          {{ $item->product_name }}
          @if((float) $item->refunded_quantity > 0)
            <br><span class="text-muted">({{ (int) $item->refunded_quantity }} refunded)</span>
          @endif
        </td>
        <td class="col-money">{{ $formatter->formatTableNumber((int) $item->quantity) }}</td>
        <td class="col-money">{{ $formatter->formatMoney((float) $item->unit_price, $currency) }}</td>
        <td class="col-money amount-emphasis">{{ $formatter->formatMoney((float) $item->subtotal, $currency) }}</td>
      </tr>
    @empty
      <tr>
        <td colspan="4" class="text-center text-muted">No items</td>
      </tr>
    @endforelse
  </tbody>
</table>

<table class="data" style="margin-top:10px;width:52%;margin-left:auto;">
  <tbody>
    <tr>
      <td class="text-left">Subtotal</td>
      <td class="col-money">{{ $formatter->formatMoney((float) $sale->subtotal, $currency) }}</td>
    </tr>
    @if($discount > 0)
      <tr>
        <td class="text-left">Discount</td>
        <td class="col-money" style="color:#16a34a;">-{{ $formatter->formatMoney($discount, $currency) }}</td>
      </tr>
    @endif
    @if($taxTotal > 0)
      <tr>
        <td class="text-left">VAT</td>
        <td class="col-money">{{ $formatter->formatMoney($taxTotal, $currency) }}</td>
      </tr>
    @endif
    <tr class="total-row">
      <td class="text-left">Total</td>
      <td class="col-money">{{ $formatter->formatMoney((float) $sale->total_amount, $currency) }}</td>
    </tr>
    @if($totalRefunded > 0.005)
      <tr>
        <td class="text-left" style="color:#dc2626;">Total Refunded</td>
        <td class="col-money" style="color:#dc2626;">-{{ $formatter->formatMoney($totalRefunded, $currency) }}</td>
      </tr>
      <tr class="total-row">
        <td class="text-left">Net Total</td>
        <td class="col-money">{{ $formatter->formatMoney($netAmount, $currency) }}</td>
      </tr>
    @endif
    <tr>
      <td class="text-left">Amount Paid</td>
      <td class="col-money">{{ $formatter->formatMoney((float) $sale->amount_paid, $currency) }}</td>
    </tr>
    @if($balanceDue > 0.009)
      <tr class="total-row">
        <td class="text-left" style="color:#d97706;">Balance Due</td>
        <td class="col-money" style="color:#d97706;">{{ $formatter->formatMoney($balanceDue, $currency) }}</td>
      </tr>
    @endif
  </tbody>
</table>

<div style="margin-top:12px; padding:10px 12px; background:#f0fdf4; border:1px solid #bbf7d0; border-radius:6px;">
  <p style="font-size:9px; color:#166534; margin:0;">
    <strong>Payment Method:</strong> {{ ucfirst(str_replace('_', ' ', $sale->payment_method)) }}
  </p>
  @if($displayTendered !== null && $displayTendered > 0)
    <p style="font-size:9px; color:#166534; margin:4px 0 0 0;">
      <strong>Amount Tendered:</strong> {{ $formatter->formatMoney($displayTendered, $currency) }}
    </p>
  @endif
  @if($displayChange !== null && $displayChange > 0)
    <p style="font-size:9px; color:#166534; margin:4px 0 0 0;">
      <strong>Change Given:</strong> {{ $formatter->formatMoney($displayChange, $currency) }}
    </p>
  @endif
  @if($hasInstallments && $payments->count() > 1)
    <div style="margin-top:6px; padding-top:6px; border-top:1px dashed #bbf7d0;">
      <p style="font-size:8px; color:#166534; margin:0 0 4px 0; text-transform:uppercase; letter-spacing:0.3px;">
        Installments ({{ $payments->count() }})
      </p>
      @foreach($payments as $i => $p)
        <p style="font-size:9px; color:#166534; margin:0 0 2px 0;">
          #{{ $i + 1 }} {{ $p->receipt_number }} — {{ $formatter->formatMoney((float) $p->amount, $currency) }}
        </p>
      @endforeach
    </div>
  @endif
</div>

@if($sale->notes)
  <div style="margin-top:14px;padding:10px 12px;background:#f9fafb;border:1px solid #e5e7eb;border-radius:6px;">
    <p style="font-size:8.5px;font-weight:bold;color:#6b7280;margin:0 0 4px 0;text-transform:uppercase;letter-spacing:0.3px;">Notes</p>
    <p style="font-size:9.5px;color:#374151;margin:0;line-height:1.5;">{{ $sale->notes }}</p>
  </div>
@endif

<p style="margin-top:12px;font-size:8.5px;color:#9ca3af;">
  Prepared by {{ $cashierName }}
  @if($location)
    · {{ $location }}
  @endif
</p>
@endsection
