@extends('reports.layouts.base')

@section('content')
  <table class="data">
    <colgroup>
      <col style="width:15%">
      <col style="width:35%">
      <col style="width:25%">
      <col style="width:25%">
    </colgroup>
    <thead>
      <tr>
        <th class="text-left">Code</th>
        <th class="text-left">Account</th>
        <th class="col-money">Debit</th>
        <th class="col-money">Credit</th>
      </tr>
    </thead>
    <tbody>
      @foreach ($trialBalance['rows'] as $row)
        <tr>
          <td class="text-left">{{ $row['account_code'] }}</td>
          <td class="text-left">{{ $row['account_name'] }}</td>
          <td class="col-money">{{ $formatter->formatTableNumber($row['debit']) }}</td>
          <td class="col-money">{{ $formatter->formatTableNumber($row['credit']) }}</td>
        </tr>
      @endforeach
      <tr class="total-row">
        <td></td>
        <td class="text-left">Total</td>
        <td class="col-money">{{ $formatter->formatTableNumber($trialBalance['total_debits']) }}</td>
        <td class="col-money">{{ $formatter->formatTableNumber($trialBalance['total_credits']) }}</td>
      </tr>
    </tbody>
  </table>
@endsection
