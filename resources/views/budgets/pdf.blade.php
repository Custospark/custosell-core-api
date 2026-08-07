@extends('reports.layouts.base')

@section('content')
@php
  $currency = $currency ?? ($business->currency ?? 'UGX');
  $lines = $lines ?? collect();
  $expenses = $expenses ?? collect();
  $income = $income ?? collect();
  $summary = $summary ?? [];
@endphp

@if($budget->description)
  <div style="background:#f9fafb;border:1px solid #e5e7eb;border-left:3px solid {{ $accent }};border-radius:6px;padding:10px 12px;margin-bottom:14px;">
    <p style="margin:0;font-size:9.5px;color:#374151;line-height:1.5;">{{ $budget->description }}</p>
  </div>
@endif

@if($lines->count() > 0)
  <div class="section-title">Plan</div>
  <table class="data">
    <colgroup>
      <col style="width:45%">
      <col style="width:10%">
      <col style="width:16%">
      <col style="width:14%">
      <col style="width:15%">
    </colgroup>
    <thead>
      <tr>
        <th class="text-left">Item</th>
        <th class="col-money">Qty</th>
        <th class="col-money">Unit Price</th>
        <th class="col-money">Line Total</th>
        <th class="col-money">Bought</th>
      </tr>
    </thead>
    <tbody>
      @forelse ($lines as $line)
        <tr>
          <td class="text-left">{{ $line->item_name }}</td>
          <td class="col-money">{{ $formatter->formatTableNumber((float) $line->quantity) }}</td>
          <td class="col-money">{{ $formatter->formatMoney((float) $line->unit_price, $currency) }}</td>
          <td class="col-money amount-emphasis">{{ $formatter->formatMoney((float) $line->line_total, $currency) }}</td>
          <td class="col-money @if($line->purchased) text-red @else text-muted @endif">
            {{ $line->purchased ? 'Yes' : 'No' }}
          </td>
        </tr>
      @empty
        <tr><td colspan="5" class="text-center text-muted">No plan lines</td></tr>
      @endforelse
    </tbody>
    <tfoot>
      <tr class="total-row">
        <td class="text-left">{{ $lines->count() }} item{{ $lines->count() === 1 ? '' : 's' }}</td>
        <td class="col-money">{{ $formatter->formatTableNumber((float) $lines->sum('quantity')) }}</td>
        <td></td>
        <td class="col-money">{{ $formatter->formatMoney((float) $lines->sum('line_total'), $currency) }}</td>
        <td></td>
      </tr>
    </tfoot>
  </table>
@endif

@if($income->count() > 0)
  <div class="section-title">Income in ({{ $income->count() }})</div>
  <table class="data">
    <colgroup><col style="width:48%"><col style="width:28%"><col style="width:24%"></colgroup>
    <thead>
      <tr><th class="text-left">Source</th><th class="text-left">Date</th><th class="col-money">Amount</th></tr>
    </thead>
    <tbody>
      @foreach ($income as $inc)
        <tr>
          <td class="text-left">{{ $inc->source_name }}</td>
          <td class="text-left">{{ $inc->income_date?->format('M d, Y') ?? $inc->income_date }}</td>
          <td class="col-money">{{ $formatter->formatMoney((float) $inc->amount, $currency) }}</td>
        </tr>
      @endforeach
    </tbody>
    <tfoot>
      <tr class="total-row">
        <td class="text-left">Total income</td>
        <td></td>
        <td class="col-money">{{ $formatter->formatMoney((float) $income->sum('amount'), $currency) }}</td>
      </tr>
    </tfoot>
  </table>
@endif

@if($expenses->count() > 0)
  <div class="section-title">Spend ({{ $expenses->count() }})</div>
  <table class="data">
    <colgroup><col style="width:48%"><col style="width:28%"><col style="width:24%"></colgroup>
    <thead>
      <tr><th class="text-left">Expense</th><th class="text-left">Date</th><th class="col-money">Amount</th></tr>
    </thead>
    <tbody>
      @foreach ($expenses as $exp)
        <tr>
          <td class="text-left">{{ $exp->description }}</td>
          <td class="text-left">{{ $exp->expense_date?->format('M Y') ?? $exp->expense_date }}</td>
          <td class="col-money">{{ $formatter->formatMoney((float) $exp->amount, $currency) }}</td>
        </tr>
      @endforeach
    </tbody>
    <tfoot>
      <tr class="total-row">
        <td class="text-left">Total spend</td>
        <td></td>
        <td class="col-money">{{ $formatter->formatMoney((float) $expenses->sum('amount'), $currency) }}</td>
      </tr>
    </tfoot>
  </table>
@endif

@if($summary)
  <table class="data" style="margin-top:12px;width:52%;margin-left:auto;">
    <tbody>
      <tr><td class="text-left">Planned</td><td class="col-money">{{ $formatter->formatMoney((float) $summary['planned'], $currency) }}</td></tr>
      <tr><td class="text-left">Spent</td><td class="col-money">{{ $formatter->formatMoney((float) $summary['actual_spend'], $currency) }}</td></tr>
      <tr><td class="text-left">Income</td><td class="col-money">{{ $formatter->formatMoney((float) $summary['actual_income'], $currency) }}</td></tr>
      <tr class="total-row">
        <td class="text-left">Remaining</td>
        <td class="col-money @if((float) $summary['remaining'] < 0) text-red @endif">{{ $formatter->formatMoney((float) $summary['remaining'], $currency) }}</td>
      </tr>
    </tbody>
  </table>
@endif

<p style="margin-top:12px;font-size:8.5px;color:#9ca3af;">
  {{ floor((float) ($summary['percentage'] ?? 0)) }}% of this budget has been spent.
</p>
@endsection