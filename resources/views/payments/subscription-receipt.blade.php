@extends('reports.layouts.base')

@section('content')
@php
  $natural = $natural ?? true;
  $currency = $currency ?? 'USD';
  $plan = $plan ?? ($subscription->plan ?? null);
  $billingCycle = $billingCycle ?? ($subscription->billing_cycle ?? 'monthly');
  $amountPaid = $amountPaid ?? (float) $payment->amount;
  $amount = $amount ?? (float) $payment->amount;
  $monthlyRate = $monthlyRate ?? 0.0;
  $paymentTypeLabel = $paymentTypeLabel ?? ucfirst(str_replace('_', ' ', (string) $payment->payment_type->value));
  $methodLabel = $methodLabel ?? ucfirst(str_replace('_', ' ', (string) $payment->method->value));
  $paidAt = $paidAt ?? ($payment->paid_at ?? $payment->created_at);
@endphp

<table style="width:100%; margin-bottom:16px; border-collapse:collapse;">
  <tr>
    <td style="width:52%; vertical-align:top; padding-right:16px;">
      <p style="font-size:8.5px; font-weight:bold; color:#6b7280; text-transform:uppercase; margin:0 0 6px 0; letter-spacing:0.4px;">Billed To</p>
      <p style="font-size:11px; font-weight:bold; color:#111827; margin:0 0 3px 0;">{{ $subscriber->name }}</p>
      @if($subscriber->email)
        <p style="font-size:9.5px; color:#4b5563; margin:0 0 2px 0;">{{ $subscriber->email }}</p>
      @endif
      @if($subscriber->phone)
        <p style="font-size:9.5px; color:#4b5563; margin:0;">Tel: {{ $subscriber->phone }}</p>
      @endif
    </td>
    <td style="width:48%; vertical-align:top; text-align:right;">
      <p style="font-size:8.5px; font-weight:bold; color:#6b7280; text-transform:uppercase; margin:0 0 2px 0; letter-spacing:0.4px;">Payment Receipt</p>
      @if($payment->transaction_reference)
        <p style="font-size:9.5px; color:#374151; margin:0 0 2px 0;"><strong>Receipt No:</strong> {{ $payment->transaction_reference }}</p>
      @endif
      @if($payment->gateway_transaction_id)
        <p style="font-size:9.5px; color:#374151; margin:0 0 2px 0;"><strong>Gateway ID:</strong> {{ $payment->gateway_transaction_id }}</p>
      @endif
      <p style="font-size:9.5px; color:#374151; margin:0 0 2px 0;"><strong>Date:</strong> {{ $paidAt?->format('M d, Y') }}</p>
      <p style="font-size:9.5px; color:#374151; margin:0 0 2px 0;"><strong>Time:</strong> {{ $paidAt?->format('H:i') }}</p>
      <p style="font-size:9.5px; color:#374151; margin:0;">
        <strong>Status:</strong>
        <span class="badge badge-paid">{{ $payment->status->value }}</span>
      </p>
    </td>
  </tr>
</table>

<div class="section-title">Subscription</div>

<table class="data">
  <colgroup>
    <col style="width:46%">
    <col style="width:54%">
  </colgroup>
  <tbody>
    <tr>
      <td class="text-left">Product</td>
      <td class="text-right">Custosell - {{ $plan?->name ?? 'Subscription' }}</td>
    </tr>
    @if($subscription->next_billing_date)
      <tr>
        <td class="text-left">Next billing date</td>
        <td class="text-right">{{ $subscription->next_billing_date->format('M d, Y') }}</td>
      </tr>
    @endif
    <tr>
      <td class="text-left">Payment type</td>
      <td class="text-right capitalize">{{ $paymentTypeLabel }}</td>
    </tr>
    @if($topUpMonths)
      <tr>
        <td class="text-left">Top-up months</td>
        <td class="text-right">{{ $topUpMonths }}</td>
      </tr>
    @endif
  </tbody>
</table>

<div class="section-title">Amount</div>

<table class="data">
  <tbody>
    @if($monthlyRate > 0)
      <tr>
        <td class="text-left">Plan rate ({{ $billingCycle }} / month)</td>
        <td class="col-money">{{ $formatter->formatMoney($monthlyRate, 'USD') }}</td>
      </tr>
    @endif
    @if(($referralDiscountUsd ?? 0) > 0)
      <tr>
        <td class="text-left">Referral discount</td>
        <td class="col-money" style="color:#059669;">-{{ $formatter->formatMoney($referralDiscountUsd, $currency) }}</td>
      </tr>
    @endif
    @if(($billingCreditUsd ?? 0) > 0)
      <tr>
        <td class="text-left">Billing credit applied</td>
        <td class="col-money" style="color:#059669;">-{{ $formatter->formatMoney($billingCreditUsd, $currency) }}</td>
      </tr>
    @endif
    @if(($originalAmountUsd ?? 0) > $amountPaid && (($referralDiscountUsd ?? 0) + ($billingCreditUsd ?? 0)) < (float) $originalAmountUsd - $amountPaid)
      <tr>
        <td class="text-left">Deductions applied</td>
        <td class="col-money" style="color:#059669;">-{{ $formatter->formatMoney(max(0, (float) $originalAmountUsd - $amountPaid), $currency) }}</td>
      </tr>
    @endif
    <tr>
      <td class="text-left"><strong>TOTAL PAID</strong></td>
      <td class="col-money amount-emphasis"><strong>{{ $formatter->formatMoney($amountPaid, $currency) }}</strong></td>
    </tr>
  </tbody>
</table>

<p style="margin-top:14px; font-size:9.5px; color:#374151; line-height:1.5;">
  This is an official receipt for a Custosell subscription payment processed by
  <strong>Custospark Company Ltd</strong>. Please retain it for your records. If you have
  any questions about this charge, please contact
  <strong>Custospark Company Ltd</strong> support at
  {{ config('brand.company_email', 'support@custosell.com') }}.
</p>

<div class="section-title" style="margin-top:16px;">Questions? Contact Us</div>

<table class="data">
  <tbody>
    <tr>
      <td class="text-left">Email</td>
      <td class="text-right">{{ $business->contact_email }}</td>
    </tr>
    <tr>
      <td class="text-left">Website</td>
      <td class="text-right">{{ $business->contact_website }}</td>
    </tr>
  </tbody>
</table>

<p style="margin-top:10px; font-size:9px; color:#047857; text-align:center; font-weight:bold;">
  PAID IN FULL - Thank you for your business.
</p>
@endsection