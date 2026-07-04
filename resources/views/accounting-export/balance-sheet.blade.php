@extends('reports.layouts.base')

@section('content')
  @php $sec = $sheet['sections'] ?? []; @endphp

  <div class="section-title">Assets</div>
  <table class="data">
    <colgroup><col style="width:15%"><col style="width:45%"><col style="width:40%"></colgroup>
    <thead><tr><th class="text-left">Code</th><th class="text-left">Account</th><th class="col-money">Amount</th></tr></thead>
    <tbody>
      @foreach ($sec['assets'] ?? [] as $a)
        <tr><td class="text-left">{{ $a['account_code'] }}</td><td class="text-left">{{ $a['account_name'] }}</td><td class="col-money">{{ $formatter->formatTableNumber($a['balance']) }}</td></tr>
      @endforeach
      <tr class="total-row"><td></td><td class="text-left">Total Assets</td><td class="col-money">{{ $formatter->formatTableNumber($sheet['total_assets']) }}</td></tr>
    </tbody>
  </table>

  <div class="section-title">Liabilities</div>
  <table class="data">
    <colgroup><col style="width:15%"><col style="width:45%"><col style="width:40%"></colgroup>
    <thead><tr><th class="text-left">Code</th><th class="text-left">Account</th><th class="col-money">Amount</th></tr></thead>
    <tbody>
      @foreach ($sec['liabilities'] ?? [] as $l)
        <tr><td class="text-left">{{ $l['account_code'] }}</td><td class="text-left">{{ $l['account_name'] }}</td><td class="col-money">{{ $formatter->formatTableNumber($l['balance']) }}</td></tr>
      @endforeach
      <tr class="total-row"><td></td><td class="text-left">Total Liabilities</td><td class="col-money">{{ $formatter->formatTableNumber($sheet['total_liabilities']) }}</td></tr>
    </tbody>
  </table>

  <div class="section-title">Equity</div>
  <table class="data">
    <colgroup><col style="width:15%"><col style="width:45%"><col style="width:40%"></colgroup>
    <thead><tr><th class="text-left">Code</th><th class="text-left">Account</th><th class="col-money">Amount</th></tr></thead>
    <tbody>
      @foreach ($sec['equity'] ?? [] as $e)
        <tr><td class="text-left">{{ $e['account_code'] }}</td><td class="text-left">{{ $e['account_name'] }}</td><td class="col-money">{{ $formatter->formatTableNumber($e['balance']) }}</td></tr>
      @endforeach
      <tr class="total-row"><td></td><td class="text-left">Total Equity</td><td class="col-money">{{ $formatter->formatTableNumber($sheet['total_equity']) }}</td></tr>
    </tbody>
  </table>

  <table class="data">
    <tr class="total-row"><td style="width:60%"></td><td class="text-left" style="width:20%">A = L + E</td><td class="col-money" style="width:20%">{{ $formatter->formatTableNumber($sheet['total_assets']) }} = {{ $formatter->formatTableNumber($sheet['total_liabilities'] + $sheet['total_equity']) }}</td></tr>
  </table>
@endsection
