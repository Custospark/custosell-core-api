<!DOCTYPE html>
<html>
<head>
  <meta charset="utf-8">
  <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
  <title>Custosell Investment Quotation - {{ $quote['plan']['name'] }}</title>
  <style>
    @page { margin: 28px 24px; }
    * { box-sizing: border-box; }
    body { font-family: DejaVu Sans, sans-serif; font-size: 10px; line-height: 1.5; color: #1f2937; margin: 0; padding: 0; }
    h1 { font-size: 18px; color: #1d4ed8; margin: 0 0 2px 0; }
    h2 { font-size: 12px; color: #111827; margin: 16px 0 6px 0; border-bottom: 1px solid #e5e7eb; padding-bottom: 4px; }
    p { margin: 0 0 4px 0; }
    .muted { color: #6b7280; font-size: 9px; }
    table { width: 100%; border-collapse: collapse; margin: 6px 0 10px 0; }
    th { background: #eff6ff; color: #1e40af; font-size: 9px; text-transform: uppercase; text-align: left; padding: 6px 8px; border: 1px solid #dbeafe; }
    td { padding: 6px 8px; border: 1px solid #e5e7eb; vertical-align: top; }
    td.num, th.num { text-align: right; white-space: nowrap; }
    tr.total td { font-weight: bold; background: #eff6ff; font-size: 11px; }
    tr.grand td { font-weight: bold; background: #1d4ed8; color: #ffffff; font-size: 12px; }
    tr.grand td.muted-cell { color: #dbeafe; font-weight: normal; font-size: 9px; }
  </style>
</head>
<body>
  <table style="width:100%; border-bottom:2px solid #1d4ed8; margin-bottom:12px; padding-bottom:10px;">
    <tr>
      <td style="width:64px; vertical-align:middle;">
        @if(!empty($brand['logo']))
          <img src="{{ $brand['logo'] }}" style="height:44px;" alt="">
        @endif
      </td>
      <td style="vertical-align:middle;">
        <p style="font-size:16px; font-weight:bold; color:#111827; margin:0;">{{ $brand['name'] ?? 'Custosell' }}</p>
        <p style="font-size:9px; color:#4b5563; margin:0;">{{ $brand['tagline'] ?? '' }}</p>
      </td>
      <td style="text-align:right; vertical-align:middle;">
        <p style="font-size:9px; color:#4b5563; margin:0;">{{ $brand['url'] ?? '' }}</p>
        <p style="font-size:9px; color:#4b5563; margin:0;">{{ $brand['company'] ?? '' }}</p>
      </td>
    </tr>
  </table>
  <h1>Investment Quotation - {{ $quote['plan']['name'] }}</h1>
  <p class="muted">{{ $quote['plan']['name'] }} plan &middot; {{ ucfirst($quote['plan']['billing']) }} billing
    &middot; {{ $quote['drivers']['tills'] }} till(s) &middot; {{ $quote['drivers']['staff'] }} staff &middot; {{ $quote['drivers']['branches'] }} branch(es)
    &middot; Generated {{ $quote['generated_at'] }}</p>
  @if(!empty($quote['client']['name'] ?? null))
    <p>Prepared for: <strong>{{ $quote['client']['name'] }}</strong>@if(!empty($quote['client']['email'])) &lt;{{ $quote['client']['email'] }}&gt; @endif @if(!empty($quote['client']['phone'])) &middot; {{ $quote['client']['phone'] }} @endif</p>
  @endif
@php
  // Single-currency document: when conversion is available, every figure
  // below renders in the chosen currency so nothing mixes UGX with Naira.
  $cur = $quote['currency'] ?? 'UGX';
  $csym = $quote['currency_symbol'] ?? $cur;
  $useConv = $cur !== 'UGX' && !empty($quote['converted']['available']);
  if ($useConv) {
    $convLines = [];
    foreach (($quote['converted']['lines'] ?? []) as $cl) { $convLines[$cl['code']] = $cl; }
    foreach ($quote['hardware_lines'] as $i => $line) {
      if (isset($convLines[$line['code']])) {
        $quote['hardware_lines'][$i]['unit_ugx'] = $convLines[$line['code']]['unit'];
        $quote['hardware_lines'][$i]['line_total_ugx'] = $convLines[$line['code']]['total'];
      }
    }
    $convCustoms = [];
    foreach (($quote['converted']['customs'] ?? []) as $cc) { $convCustoms[$cc['label']] = $cc['amount']; }
    $ct = $quote['converted']['totals'];
    $pick = fn ($k) => $ct[$k.'_'.$cur] ?? null;
    foreach (['hardware_total_ugx' => 'hardware', 'custom_total_ugx' => 'custom', 'discount_ugx' => 'discount', 'vat_ugx' => 'vat', 'subscription_first_year_ugx' => 'subscription', 'onboarding_ugx' => 'onboarding', 'maintenance_annual_ugx' => 'maintenance', 'grand_total_ugx' => 'grand', 'one_time_ugx' => 'one_time', 'annual_recurring_ugx' => 'annual'] as $field => $short) {
      if (isset($ct[$short.'_'.$cur])) { $quote[$field] = $ct[$short.'_'.$cur]; }
    }
    foreach ($quote['custom_lines'] as $i => $cl) {
      if (isset($convCustoms[$cl['label']])) { $quote['custom_lines'][$i]['amount_ugx'] = $convCustoms[$cl['label']]; }
    }
  } else {
    $cur = 'UGX';
    $csym = 'UGX';
  }
@endphp
  <p class="muted">Quoted by {{ $brand['name'] ?? 'Custosell' }} ({{ $brand['company'] ?? '' }})@if(!empty($quote['rep']['name'] ?? null)) &middot; Rep: <strong>{{ $quote['rep']['name'] }}</strong>@if(!empty($quote['rep']['phone'] ?? null)) ({{ $quote['rep']['phone'] }})@endif @endif - valid 30 days from generation.</p>

  <h2>Hardware &amp; Setup</h2>
  <table>
    <tr><th>Item</th><th>Specs</th><th class="num">Qty</th><th class="num">Unit ({{ $cur }})</th><th class="num">Total ({{ $cur }})</th></tr>
    @foreach($quote['hardware_lines'] as $line)
      <tr>
        <td><strong>{{ $line['name'] }}</strong><br><span class="muted">{{ $line['category'] }}@if($line['per']) &middot; per {{ $line['per'] }}@endif</span></td>
        <td><span class="muted">{{ $line['specs'] }}</span></td>
        <td class="num">{{ $line['qty'] }}</td>
        <td class="num">{{ number_format($line['unit_ugx'], 0) }}</td>
        <td class="num">{{ number_format($line['line_total_ugx'], 0) }}</td>
      </tr>
    @endforeach
    <tr class="total"><td colspan="4">Hardware subtotal</td><td class="num">{{ number_format($quote['hardware_total_ugx'], 0) }}</td></tr>
  </table>

  @if(!empty($quote['custom_lines']))
  <h2>Additional Costs</h2>
  <table>
    <tr><th>Description</th><th class="num">{{ $cur }}</th></tr>
    @foreach($quote['custom_lines'] as $custom)
      <tr><td>{{ $custom['label'] }}</td><td class="num">{{ number_format($custom['amount_ugx'], 0) }}</td></tr>
    @endforeach
    <tr class="total"><td>Additional subtotal</td><td class="num">{{ number_format($quote['custom_total_ugx'], 0) }}</td></tr>
  </table>
  @endif

  @if(!empty($quote['custom_fields']))
  <h2>Additional Details</h2>
  <table>
    @foreach($quote['custom_fields'] as $field)
      <tr><td><strong>{{ $field['label'] }}</strong></td><td>{{ $field['value'] }}</td></tr>
    @endforeach
  </table>
  @endif

  <h2>Software &amp; Services (first year)</h2>
  <table>
    <tr><th>Description</th><th class="num">{{ $cur }}</th><th class="num">USD</th></tr>
    <tr><td>Subscription - {{ $quote['plan']['name'] }} ({{ $quote['plan']['billing'] }}, incl. {{ $quote['plan']['trial_days'] }}-day trial)</td><td class="num">{{ number_format($quote['subscription_first_year_ugx'], 0) }}</td><td class="num">{{ number_format($quote['subscription_first_year_usd'], 2) }}</td></tr>
    <tr><td>One-time onboarding &amp; setup</td><td class="num">{{ number_format($quote['onboarding_ugx'], 0) }}</td><td class="num">{{ number_format($quote['onboarding_usd'], 2) }}</td></tr>
    <tr><td>Annual maintenance (flat, per tier)</td><td class="num">{{ number_format($quote['maintenance_annual_ugx'], 0) }}</td><td class="num">{{ number_format($quote['maintenance_annual_usd'], 2) }}</td></tr>
    @if(($quote['discount_ugx'] ?? 0) > 0)
    <tr><td>Discount ({{ $quote['discount_percent'] }}%)</td><td class="num">-{{ number_format($quote['discount_ugx'], 0) }}</td><td class="num">-{{ number_format($quote['discount_usd'], 2) }}</td></tr>
    @endif
    @if(($quote['vat_ugx'] ?? 0) > 0)
    <tr><td>VAT ({{ $quote['vat_percent'] }}% on hardware after discount + onboarding)</td><td class="num">{{ number_format($quote['vat_ugx'], 0) }}</td><td class="num">{{ number_format($quote['vat_usd'], 2) }}</td></tr>
    @endif
    <tr class="grand"><td>GRAND TOTAL</td><td class="num">{{ number_format($quote['grand_total_ugx'], 0) }} {{ $cur }}</td><td class="num">${{ number_format($quote['grand_total_usd'], 2) }}</td></tr>
  </table>

  <h2>Budget Summary - how much do you need?</h2>
  <table>
    <tr><th>Budget line</th><th class="num">{{ $cur }}</th><th class="num">USD</th></tr>
    <tr><td>One-time setup (hardware, onboarding &amp; installation)</td><td class="num">{{ number_format($quote['one_time_ugx'], 0) }}</td><td class="num">${{ number_format($quote['one_time_usd'], 2) }}</td></tr>
    <tr><td>Every year after (subscription + maintenance)</td><td class="num">{{ number_format($quote['annual_recurring_ugx'], 0) }}</td><td class="num">${{ number_format($quote['annual_recurring_usd'], 2) }}</td></tr>
  </table>

  <p class="muted">{{ $quote['usd_rate_note'] }}@if(!empty($quote['converted']['available']) && ($quote['currency'] ?? 'UGX') !== 'UGX') {{ $quote['converted']['note'] }}@endif Prices exclude delivery and installation travel outside Kampala unless stated. Hardware prices are market estimates - confirmed at order time.</p>

  <h2>Operational Requirements (minimum, per site)</h2>
  <table>
    <tr><th>Area</th><th>Requirement</th><th>Provided by</th></tr>
    @foreach($quote['operational_requirements'] as $req)
      <tr><td><strong>{{ $req['area'] }}</strong></td><td>{{ $req['requirement'] }}</td><td>{{ $req['provided_by'] }}</td></tr>
    @endforeach
  </table>
  <p class="muted">Systems running below these requirements may be slow or unreliable - this is an environment issue, not a software defect. Re-checklist before sign-off.</p>
  <p style="text-align:center; font-size:9px; color:#6b7280; margin-top:14px; border-top:1px solid #e5e7eb; padding-top:8px;">{{ $brand['footer'] ?? '' }}</p>
</body>
</html>
