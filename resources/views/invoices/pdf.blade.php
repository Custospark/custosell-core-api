@extends('reports.layouts.base')

@section('content')
@php
  $items = $invoice->items ?? collect();
  $currency = $currency ?? ($business->currency ?? 'UGX');
  $balanceDue = $balanceDue ?? max(0, (float) $invoice->total_amount - (float) $invoice->amount_paid);
  $statusLabel = $statusLabel ?? ucfirst(str_replace('_', ' ', (string) $invoice->status));
@endphp

<table style="width:100%; margin-bottom:14px; border-collapse:collapse;">
  <tr>
    <td style="width:52%; vertical-align:top; padding-right:16px;">
      <p style="font-size:8.5px; font-weight:bold; color:#6b7280; text-transform:uppercase; margin:0 0 6px 0; letter-spacing:0.4px;">Bill To</p>
      @if($invoice->customer)
        <p style="font-size:11px; font-weight:bold; color:#111827; margin:0 0 3px 0;">{{ $invoice->customer->name }}</p>
        @if($invoice->customer->phone)
          <p style="font-size:9.5px; color:#4b5563; margin:0 0 2px 0;">Tel: {{ $invoice->customer->phone }}</p>
        @endif
        @if($invoice->customer->email ?? false)
          <p style="font-size:9.5px; color:#4b5563; margin:0;">{{ $invoice->customer->email }}</p>
        @endif
      @else
        <p style="font-size:10px; color:#6b7280; margin:0;">Walk-in Customer</p>
      @endif
    </td>
    <td style="width:48%; vertical-align:top; text-align:right;">
      <p style="font-size:8.5px; font-weight:bold; color:#6b7280; text-transform:uppercase; margin:0 0 6px 0; letter-spacing:0.4px;">Invoice Details</p>
      <p style="font-size:9.5px; color:#374151; margin:0 0 2px 0;"><strong>Number:</strong> {{ $invoice->invoice_number }}</p>
      <p style="font-size:9.5px; color:#374151; margin:0 0 2px 0;"><strong>Issue Date:</strong> {{ $invoice->issue_date?->format('M d, Y') }}</p>
      <p style="font-size:9.5px; color:#374151; margin:0 0 2px 0;"><strong>Due Date:</strong> {{ $invoice->due_date?->format('M d, Y') }}</p>
      <p style="font-size:9.5px; color:#374151; margin:0;">
        <strong>Status:</strong>
        @if($invoice->status === 'paid')
          <span class="badge badge-paid">{{ $statusLabel }}</span>
        @elseif($invoice->status === 'partially_paid')
          <span class="badge badge-partial">{{ $statusLabel }}</span>
        @elseif($invoice->status === 'cancelled')
          <span class="badge badge-refunded">{{ $statusLabel }}</span>
        @else
          {{ $statusLabel }}
        @endif
      </p>
    </td>
  </tr>
</table>

<div class="section-title">Line Items</div>

<table class="data">
  <colgroup>
    <col style="width:46%">
    <col style="width:14%">
    <col style="width:20%">
    <col style="width:20%">
  </colgroup>
  <thead>
    <tr>
      <th class="text-left">Description</th>
      <th class="col-money">Qty</th>
      <th class="col-money">Unit Price</th>
      <th class="col-money">Amount</th>
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
        <td class="col-money amount-emphasis">{{ $formatter->formatMoney((float) $item->subtotal, $currency) }}</td>
      </tr>
    @empty
      <tr>
        <td colspan="4" class="text-center text-muted">No line items</td>
      </tr>
    @endforelse
  </tbody>
</table>

<table class="data" style="margin-top:10px;width:52%;margin-left:auto;">
  <tbody>
    <tr>
      <td class="text-left">Subtotal</td>
      <td class="col-money">{{ $formatter->formatMoney((float) $invoice->subtotal, $currency) }}</td>
    </tr>
    @if((float) $invoice->tax_total > 0)
      <tr>
        <td class="text-left">Tax / VAT</td>
        <td class="col-money">{{ $formatter->formatMoney((float) $invoice->tax_total, $currency) }}</td>
      </tr>
    @endif
    <tr class="total-row">
      <td class="text-left">Total</td>
      <td class="col-money">{{ $formatter->formatMoney((float) $invoice->total_amount, $currency) }}</td>
    </tr>
    <tr>
      <td class="text-left">Amount Paid</td>
      <td class="col-money">{{ $formatter->formatMoney((float) $invoice->amount_paid, $currency) }}</td>
    </tr>
    <tr class="total-row">
      <td class="text-left">Balance Due</td>
      <td class="col-money {{ $balanceDue > 0 ? 'text-red' : '' }}">{{ $formatter->formatMoney($balanceDue, $currency) }}</td>
    </tr>
  </tbody>
</table>

@if($invoice->notes)
  <div style="margin-top:14px;padding:10px 12px;background:#f9fafb;border:1px solid #e5e7eb;border-radius:6px;">
    <p style="font-size:8.5px;font-weight:bold;color:#6b7280;margin:0 0 4px 0;text-transform:uppercase;letter-spacing:0.3px;">Notes</p>
    <p style="font-size:9.5px;color:#374151;margin:0;line-height:1.5;">{{ $invoice->notes }}</p>
  </div>
@endif

@php
  $hasBank = $business->payment_bank_name || $business->payment_bank_account_number;
  $hasMobileMoney = $business->payment_mobile_money_provider || $business->payment_mobile_money_number;
  $hasPaymentDetails = $hasBank || $hasMobileMoney || $business->payment_instructions;
@endphp

@if($hasPaymentDetails)
  <div style="margin-top:16px;padding:12px 14px;background:#f0f9ff;border:1px solid #bae6fd;border-radius:6px;page-break-inside:avoid;">
    <p style="font-size:10px;font-weight:bold;color:#0369a1;margin:0 0 8px 0;text-transform:uppercase;letter-spacing:0.4px;">How to Pay</p>

    @if($hasBank)
      <p style="font-size:9px;font-weight:bold;color:#374151;margin:0 0 4px 0;">Bank Transfer</p>
      <table style="width:100%;border-collapse:collapse;margin-bottom:10px;">
        @if($business->payment_bank_name)
          <tr>
            <td style="font-size:9px;color:#6b7280;padding:1px 8px 1px 0;width:38%;vertical-align:top;">Bank</td>
            <td style="font-size:9.5px;color:#111827;font-weight:bold;">{{ $business->payment_bank_name }}</td>
          </tr>
        @endif
        @if($business->payment_bank_account_name)
          <tr>
            <td style="font-size:9px;color:#6b7280;padding:1px 8px 1px 0;vertical-align:top;">Account Name</td>
            <td style="font-size:9.5px;color:#111827;">{{ $business->payment_bank_account_name }}</td>
          </tr>
        @endif
        @if($business->payment_bank_account_number)
          <tr>
            <td style="font-size:9px;color:#6b7280;padding:1px 8px 1px 0;vertical-align:top;">Account Number</td>
            <td style="font-size:9.5px;color:#111827;font-weight:bold;letter-spacing:0.3px;">{{ $business->payment_bank_account_number }}</td>
          </tr>
        @endif
        @if($business->payment_bank_branch)
          <tr>
            <td style="font-size:9px;color:#6b7280;padding:1px 8px 1px 0;vertical-align:top;">Branch</td>
            <td style="font-size:9.5px;color:#111827;">{{ $business->payment_bank_branch }}</td>
          </tr>
        @endif
      </table>
    @endif

    @if($hasMobileMoney)
      <p style="font-size:9px;font-weight:bold;color:#374151;margin:0 0 4px 0;">Mobile Money</p>
      <table style="width:100%;border-collapse:collapse;margin-bottom:4px;">
        @if($business->payment_mobile_money_provider)
          <tr>
            <td style="font-size:9px;color:#6b7280;padding:1px 8px 1px 0;width:38%;vertical-align:top;">Provider</td>
            <td style="font-size:9.5px;color:#111827;font-weight:bold;">{{ $business->payment_mobile_money_provider }}</td>
          </tr>
        @endif
        @if($business->payment_mobile_money_account_name)
          <tr>
            <td style="font-size:9px;color:#6b7280;padding:1px 8px 1px 0;vertical-align:top;">Registered Name</td>
            <td style="font-size:9.5px;color:#111827;">{{ $business->payment_mobile_money_account_name }}</td>
          </tr>
        @endif
        @if($business->payment_mobile_money_number)
          <tr>
            <td style="font-size:9px;color:#6b7280;padding:1px 8px 1px 0;vertical-align:top;">Number</td>
            <td style="font-size:9.5px;color:#111827;font-weight:bold;">{{ $business->payment_mobile_money_number }}</td>
          </tr>
        @endif
      </table>
    @endif

    @if($business->payment_instructions)
      <p style="font-size:9px;color:#0c4a6e;margin:8px 0 0 0;line-height:1.5;">{{ $business->payment_instructions }}</p>
    @endif
  </div>
@endif

@if($invoice->createdBy)
  <p style="margin-top:12px;font-size:8.5px;color:#9ca3af;">
    Prepared by {{ $invoice->createdBy->name ?? 'Staff' }}
  </p>
@endif
@endsection
