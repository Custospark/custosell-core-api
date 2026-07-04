@extends('reports.layouts.base')

@section('content')
  <div class="section-title">Assets</div>
  <table class="data">
    <colgroup>
      <col style="width:15%">
      <col style="width:55%">
      <col style="width:30%">
    </colgroup>
    <thead>
      <tr>
        <th class="text-left">Code</th>
        <th class="text-left">Account</th>
        <th class="col-money">Amount</th>
      </tr>
    </thead>
    <tbody>
      @foreach ($sheet['assets'] as $a)
        <tr>
          <td class="text-left">{{ $a['account_code'] }}</td>
          <td class="text-left">{{ $a['account_name'] }}</td>
          <td class="col-money">{{ $formatter->formatTableNumber($a['balance']) }}</td>
        </tr>
      @endforeach
      <tr class="total-row">
        <td></td>
        <td class="text-left">Total Assets</td>
        <td class="col-money">{{ $formatter->formatTableNumber($sheet['total_assets']) }}</td>
      </tr>
    </tbody>
  </table>

  <div class="section-title">Liabilities</div>
  <table class="data">
    <colgroup>
      <col style="width:15%">
      <col style="width:55%">
      <col style="width:30%">
    </colgroup>
    <thead>
      <tr>
        <th class="text-left">Code</th>
        <th class="text-left">Account</th>
        <th class="col-money">Amount</th>
      </tr>
    </thead>
    <tbody>
      @foreach ($sheet['liabilities'] as $l)
        <tr>
          <td class="text-left">{{ $l['account_code'] }}</td>
          <td class="text-left">{{ $l['account_name'] }}</td>
          <td class="col-money">{{ $formatter->formatTableNumber($l['balance']) }}</td>
        </tr>
      @endforeach
      <tr class="total-row">
        <td></td>
        <td class="text-left">Total Liabilities</td>
        <td class="col-money">{{ $formatter->formatTableNumber($sheet['total_liabilities']) }}</td>
      </tr>
    </tbody>
  </table>

  <div class="section-title">Equity</div>
  <table class="data">
    <colgroup>
      <col style="width:15%">
      <col style="width:55%">
      <col style="width:30%">
    </colgroup>
    <thead>
      <tr>
        <th class="text-left">Code</th>
        <th class="text-left">Account</th>
        <th class="col-money">Amount</th>
      </tr>
    </thead>
    <tbody>
      @foreach ($sheet['equities'] as $e)
        <tr>
          <td class="text-left">{{ $e['account_code'] }}</td>
          <td class="text-left">{{ $e['account_name'] }}</td>
          <td class="col-money">{{ $formatter->formatTableNumber($e['balance']) }}</td>
        </tr>
      @endforeach
      <tr class="total-row">
        <td></td>
        <td class="text-left">Total Equity</td>
        <td class="col-money">{{ $formatter->formatTableNumber($sheet['total_equity']) }}</td>
      </tr>
    </tbody>
  </table>

  <table class="data">
    <tr class="total-row">
      <td style="width:70%"></td>
      <td class="text-left" style="width:15%">Liabilities + Equity</td>
      <td class="col-money" style="width:15%">{{ $formatter->formatTableNumber($sheet['total_liabilities_and_equity']) }}</td>
    </tr>
  </table>
@endsection
