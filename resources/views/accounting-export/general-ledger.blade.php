@extends('reports.layouts.base')

@section('content')
  <table class="data">
    <colgroup>
      <col style="width:10%">
      <col style="width:12%">
      <col style="width:25%">
      <col style="width:10%">
      <col style="width:18%">
      <col style="width:12%">
      <col style="width:13%">
    </colgroup>
    <thead>
      <tr>
        <th class="text-left">Date</th>
        <th class="text-left">Entry #</th>
        <th class="text-left">Description</th>
        <th class="text-left">Code</th>
        <th class="text-left">Account</th>
        <th class="col-money">Debit</th>
        <th class="col-money">Credit</th>
      </tr>
    </thead>
    <tbody>
      @forelse ($ledgerRows as $row)
        <tr>
          <td class="text-left">{{ $row['date'] }}</td>
          <td class="text-left">{{ $row['entry_number'] }}</td>
          <td class="text-left">{{ $row['description'] }}</td>
          <td class="text-left">{{ $row['account_code'] }}</td>
          <td class="text-left">{{ $row['account_name'] }}</td>
          <td class="col-money">{{ $formatter->formatTableNumber($row['debit']) }}</td>
          <td class="col-money">{{ $formatter->formatTableNumber($row['credit']) }}</td>
        </tr>
      @empty
        <tr>
          <td colspan="7" class="text-center">No ledger entries found for this period.</td>
        </tr>
      @endforelse
    </tbody>
  </table>
@endsection
