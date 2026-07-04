@extends('reports.layouts.base')

@section('content')
  <div class="section-title">Equity Components</div>
  <table class="data">
    <colgroup><col style="width:15%"><col style="width:45%"><col style="width:40%"></colgroup>
    <thead><tr><th class="text-left">Code</th><th class="text-left">Component</th><th class="col-money">Amount</th></tr></thead>
    <tbody>
      @foreach ($statement['equity_components'] ?? [] as $e)
        <tr><td class="text-left">{{ $e['account_code'] }}</td><td class="text-left">{{ $e['account_name'] }}</td><td class="col-money">{{ $formatter->formatTableNumber($e['balance']) }}</td></tr>
      @endforeach
    </tbody>
  </table>

  <div class="section-title">Retained Earnings Summary</div>
  <table class="data">
    <colgroup><col style="width:60%"><col style="width:40%"></colgroup>
    <thead><tr><th class="text-left">Item</th><th class="col-money">Amount</th></tr></thead>
    <tbody>
      <tr><td class="text-left">Opening Retained Earnings</td><td class="col-money">{{ $formatter->formatTableNumber($statement['opening_retained_earnings']) }}</td></tr>
      <tr><td class="text-left">Net Income</td><td class="col-money">{{ $formatter->formatTableNumber($statement['net_income']) }}</td></tr>
      <tr><td class="text-left">Dividends</td><td class="col-money">{{ $formatter->formatTableNumber(-$statement['dividends']) }}</td></tr>
      <tr class="total-row"><td class="text-left">Closing Retained Earnings</td><td class="col-money">{{ $formatter->formatTableNumber($statement['closing_retained_earnings']) }}</td></tr>
    </tbody>
  </table>

  <table class="data">
    <tr class="total-row"><td style="width:60%"></td><td class="text-left" style="width:20%">Total Equity</td><td class="col-money" style="width:20%">{{ $formatter->formatTableNumber($statement['total_equity']) }}</td></tr>
  </table>
@endsection
