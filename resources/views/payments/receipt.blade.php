@extends('reports.layouts.base')

@section('content')
@php
  $currency = $currency ?? ($business->currency ?? 'UGX');
  $methodLabel = ucfirst(str_replace('_', ' ', (string) $payment->payment_method));
  $tendered = (float) ($payment->amount_tendered ?? $payment->amount);
  $change = $payment->change_given !== null ? (float) $payment->change_given : 0;
  $lineItems = $lineItems ?? [];
  $subtotal = (float) ($subtotal ?? 0);
  $discount = (float) ($discount ?? 0);
  $taxTotal = (float) ($taxTotal ?? 0);
  $totalRefunded = (float) ($totalRefunded ?? 0);
  $billTotal = (float) ($billTotal ?? $totalBill);
  $taxIsExclusive = $taxTotal > 0 && abs($subtotal - $discount + $taxTotal - $billTotal) < 0.02;
  $showTax = $taxTotal > 0 && ($taxIsExclusive || $taxTotal <= $subtotal + 0.02);
  $taxLabel = $taxIsExclusive ? 'VAT' : 'VAT (incl.)';
@endphp

<table style="width:100%; margin-bottom:14px; border-collapse:collapse;">
  <tr>
    <td style="width:50%; vertical-align:top;">
      <p style="font-size:8.5px; font-weight:bold; color:#6b7280; text-transform:uppercase; margin:0 0 6px 0;">Payment Details</p>
      <p style="font-size:9.5px; color:#374151; margin:0 0 2px 0;"><strong>Receipt #:</strong> {{ $payment->receipt_number }}</p>
      <p style="font-size:9.5px; color:#374151; margin:0 0 2px 0;"><strong>Date:</strong> {{ $payment->paid_at?->format('M d, Y H:i') }}</p>
      <p style="font-size:9.5px; color:#374151; margin:0 0 2px 0;"><strong>Method:</strong> {{ $methodLabel }}</p>
      <p style="font-size:9.5px; color:#374151; margin:0 0 2px 0;"><strong>{{ $referenceType }}:</strong> {{ $referenceLabel }}</p>
      @if(!empty($customerName))
        <p style="font-size:9.5px; color:#374151; margin:0 0 2px 0;"><strong>Customer:</strong> {{ $customerName }}</p>
      @endif
      @if($tendered > 0)
        <p style="font-size:9.5px; color:#374151; margin:4px 0 0 0;"><strong>Amount tendered:</strong> {{ $formatter->formatMoney($tendered, $currency) }}</p>
      @endif
      @if($change > 0)
        <p style="font-size:9.5px; color:#047857; margin:0;"><strong>Change given:</strong> {{ $formatter->formatMoney($change, $currency) }}</p>
      @endif
    </td>
    <td style="width:50%; vertical-align:top; text-align:right;">
      <p style="font-size:8.5px; font-weight:bold; color:#6b7280; text-transform:uppercase; margin:0 0 6px 0;">Amount</p>
      <p style="font-size:18px; font-weight:bold; color:#047857; margin:0 0 4px 0;">{{ $formatter->formatMoney((float) $payment->amount, $currency) }}</p>
      <p style="font-size:9px; color:#6b7280; margin:0;">This payment</p>
    </td>
  </tr>
</table>

@if(count($lineItems) > 0)
  <div class="section-title">Items Purchased</div>
  <table class="data">
    <thead>
      <tr>
        <th class="text-left">Item</th>
        <th class="col-qty">Qty</th>
        <th class="col-money">Unit</th>
        <th class="col-money">Total</th>
      </tr>
    </thead>
    <tbody>
      @foreach($lineItems as $item)
        <tr>
          <td class="text-left">
            {{ $item['name'] }}
            @if(($item['refunded_quantity'] ?? 0) > 0)
              <span style="color:#dc2626; font-size:8px;">({{ $item['refunded_quantity'] }} refunded)</span>
            @endif
            @if(($item['discount'] ?? 0) > 0)
              <span style="color:#059669; font-size:8px;">(-{{ $formatter->formatMoney((float) $item['discount'], $currency) }})</span>
            @endif
          </td>
          <td class="col-qty">{{ $item['quantity'] }}</td>
          <td class="col-money">{{ $formatter->formatMoney((float) $item['unit_price'], $currency) }}</td>
          <td class="col-money">{{ $formatter->formatMoney((float) $item['subtotal'], $currency) }}</td>
        </tr>
      @endforeach
    </tbody>
  </table>

  <table class="data" style="margin-top:8px;">
    <tbody>
      @if($subtotal > 0)
        <tr><td class="text-left">Subtotal</td><td class="col-money">{{ $formatter->formatMoney($subtotal, $currency) }}</td></tr>
      @endif
      @if($discount > 0)
        <tr><td class="text-left">Discount</td><td class="col-money" style="color:#059669;">-{{ $formatter->formatMoney($discount, $currency) }}</td></tr>
      @endif
      @if($showTax)
        <tr><td class="text-left">{{ $taxLabel }}</td><td class="col-money">{{ $formatter->formatMoney($taxTotal, $currency) }}</td></tr>
      @endif
      @if($totalRefunded > 0)
        <tr><td class="text-left">Refunded</td><td class="col-money" style="color:#dc2626;">-{{ $formatter->formatMoney($totalRefunded, $currency) }}</td></tr>
      @endif
      <tr>
        <td class="text-left"><strong>TOTAL</strong></td>
        <td class="col-money amount-emphasis"><strong>{{ $formatter->formatMoney($billTotal, $currency) }}</strong></td>
      </tr>
    </tbody>
  </table>
@endif

<div class="section-title">Payment Summary</div>

<table class="data">
  <tbody>
    <tr>
      <td class="text-left">Total bill</td>
      <td class="col-money amount-emphasis">{{ $formatter->formatMoney($totalBill, $currency) }}</td>
    </tr>
    <tr>
      <td class="text-left">Previously paid</td>
      <td class="col-money">{{ $formatter->formatMoney($previousPaid, $currency) }}</td>
    </tr>
    <tr>
      <td class="text-left"><strong>This payment</strong></td>
      <td class="col-money amount-emphasis">{{ $formatter->formatMoney((float) $payment->amount, $currency) }}</td>
    </tr>
    <tr>
      <td class="text-left"><strong>Total paid to date</strong></td>
      <td class="col-money">{{ $formatter->formatMoney($totalPaid, $currency) }}</td>
    </tr>
    <tr>
      <td class="text-left"><strong>Balance remaining</strong></td>
      <td class="col-money" style="color: {{ $balanceAfter > 0 ? '#b45309' : '#047857' }};">
        <strong>{{ $formatter->formatMoney($balanceAfter, $currency) }}</strong>
      </td>
    </tr>
  </tbody>
</table>

@if($balanceAfter > 0)
  <p style="margin-top:12px; font-size:9px; color:#6b7280; text-align:center;">
    Please retain this receipt. Outstanding balance must be settled to complete payment.
  </p>
@else
  <p style="margin-top:12px; font-size:9px; color:#047857; text-align:center; font-weight:bold;">
    PAID IN FULL — Thank you for your business.
  </p>
@endif
@endsection
