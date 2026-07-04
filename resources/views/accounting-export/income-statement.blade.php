@extends('reports.layouts.base')

@section('content')
  @php $sec = $statement['sections'] ?? []; @endphp

  <div class="section-title">Revenue</div>
  <table class="data">
    <colgroup><col style="width:15%"><col style="width:45%"><col style="width:40%"></colgroup>
    <thead><tr><th class="text-left">Code</th><th class="text-left">Account</th><th class="col-money">Amount</th></tr></thead>
    <tbody>
      @foreach ($sec['revenue'] ?? [] as $r)
        <tr><td class="text-left">{{ $r['account_code'] }}</td><td class="text-left">{{ $r['account_name'] }}</td><td class="col-money">{{ $formatter->formatTableNumber($r['balance']) }}</td></tr>
      @endforeach
      <tr class="total-row"><td></td><td class="text-left">Total Revenue</td><td class="col-money">{{ $formatter->formatTableNumber($statement['total_revenue']) }}</td></tr>
    </tbody>
  </table>

  <div class="section-title">Cost of Goods Sold</div>
  <table class="data">
    <colgroup><col style="width:15%"><col style="width:45%"><col style="width:40%"></colgroup>
    <thead><tr><th class="text-left">Code</th><th class="text-left">Account</th><th class="col-money">Amount</th></tr></thead>
    <tbody>
      @foreach ($sec['cost_of_goods_sold'] ?? [] as $c)
        <tr><td class="text-left">{{ $c['account_code'] }}</td><td class="text-left">{{ $c['account_name'] }}</td><td class="col-money">{{ $formatter->formatTableNumber($c['balance']) }}</td></tr>
      @endforeach
      <tr class="total-row"><td></td><td class="text-left">Total COGS</td><td class="col-money">{{ $formatter->formatTableNumber($statement['total_cost_of_goods_sold']) }}</td></tr>
    </tbody>
  </table>

  <table class="data"><tr class="total-row"><td style="width:60%"></td><td class="text-left" style="width:20%">Gross Profit</td><td class="col-money" style="width:20%">{{ $formatter->formatTableNumber($statement['gross_profit']) }}</td></tr></table>

  <div class="section-title">Operating Expenses</div>
  <table class="data">
    <colgroup><col style="width:15%"><col style="width:45%"><col style="width:40%"></colgroup>
    <thead><tr><th class="text-left">Code</th><th class="text-left">Account</th><th class="col-money">Amount</th></tr></thead>
    <tbody>
      @foreach ($sec['operating_expenses'] ?? [] as $e)
        <tr><td class="text-left">{{ $e['account_code'] }}</td><td class="text-left">{{ $e['account_name'] }}</td><td class="col-money">{{ $formatter->formatTableNumber($e['balance']) }}</td></tr>
      @endforeach
      <tr class="total-row"><td></td><td class="text-left">Total Operating Expenses</td><td class="col-money">{{ $formatter->formatTableNumber($statement['total_operating_expenses']) }}</td></tr>
    </tbody>
  </table>

  <table class="data">
    <tr class="total-row"><td style="width:60%"></td><td class="text-left" style="width:20%">Operating Income (EBIT)</td><td class="col-money" style="width:20%">{{ $formatter->formatTableNumber($statement['operating_income']) }}</td></tr>
    <tr class="total-row"><td style="width:60%"></td><td class="text-left" style="width:20%">Net Income</td><td class="col-money" style="width:20%">{{ $formatter->formatTableNumber($statement['net_income']) }}</td></tr>
  </table>
@endsection
