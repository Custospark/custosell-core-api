@extends('reports.layouts.base')

@section('content')
  <div class="section-title">Revenue</div>
  <table class="data">
    <colgroup>
      <col style="width:15%">
      <col style="width:45%">
      <col style="width:40%">
    </colgroup>
    <thead>
      <tr>
        <th class="text-left">Code</th>
        <th class="text-left">Account</th>
        <th class="col-money">Amount</th>
      </tr>
    </thead>
    <tbody>
      @foreach ($statement['revenues'] as $rev)
        <tr>
          <td class="text-left">{{ $rev['account_code'] }}</td>
          <td class="text-left">{{ $rev['account_name'] }}</td>
          <td class="col-money">{{ $formatter->formatTableNumber($rev['balance']) }}</td>
        </tr>
      @endforeach
      <tr class="total-row">
        <td></td>
        <td class="text-left">Total Revenue</td>
        <td class="col-money">{{ $formatter->formatTableNumber($statement['total_revenue']) }}</td>
      </tr>
    </tbody>
  </table>

  <div class="section-title">Expenses</div>
  <table class="data">
    <colgroup>
      <col style="width:15%">
      <col style="width:45%">
      <col style="width:40%">
    </colgroup>
    <thead>
      <tr>
        <th class="text-left">Code</th>
        <th class="text-left">Account</th>
        <th class="col-money">Amount</th>
      </tr>
    </thead>
    <tbody>
      @foreach ($statement['expenses'] as $exp)
        <tr>
          <td class="text-left">{{ $exp['account_code'] }}</td>
          <td class="text-left">{{ $exp['account_name'] }}</td>
          <td class="col-money">{{ $formatter->formatTableNumber($exp['balance']) }}</td>
        </tr>
      @endforeach
      <tr class="total-row">
        <td></td>
        <td class="text-left">Total Expenses</td>
        <td class="col-money">{{ $formatter->formatTableNumber($statement['total_expenses']) }}</td>
      </tr>
    </tbody>
  </table>

  <table class="data">
    <tr class="total-row">
      <td style="width:60%"></td>
      <td class="text-left" style="width:20%">Net Income</td>
      <td class="col-money" style="width:20%">{{ $formatter->formatTableNumber($statement['net_income']) }}</td>
    </tr>
  </table>
@endsection
