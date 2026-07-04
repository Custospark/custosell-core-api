@extends('reports.layouts.base')

@section('content')
  <div class="section-title">Operating Activities</div>
  <table class="data">
    <colgroup><col style="width:15%"><col style="width:45%"><col style="width:40%"></colgroup>
    <thead><tr><th class="text-left">Section</th><th class="text-left">Item</th><th class="col-money">Amount</th></tr></thead>
    <tbody>
      @foreach ($statement['operating']['items'] ?? [] as $item)
        <tr><td class="text-left">Operating</td><td class="text-left">{{ $item['label'] }}</td><td class="col-money">{{ $formatter->formatTableNumber($item['amount']) }}</td></tr>
      @endforeach
      <tr class="total-row"><td></td><td class="text-left">Net Cash from Operating Activities</td><td class="col-money">{{ $formatter->formatTableNumber($statement['operating']['total']) }}</td></tr>
    </tbody>
  </table>

  <div class="section-title">Investing Activities</div>
  <table class="data">
    <colgroup><col style="width:15%"><col style="width:45%"><col style="width:40%"></colgroup>
    <thead><tr><th class="text-left">Section</th><th class="text-left">Item</th><th class="col-money">Amount</th></tr></thead>
    <tbody>
      @foreach ($statement['investing']['items'] ?? [] as $item)
        <tr><td class="text-left">Investing</td><td class="text-left">{{ $item['label'] }}</td><td class="col-money">{{ $formatter->formatTableNumber($item['amount']) }}</td></tr>
      @endforeach
      <tr class="total-row"><td></td><td class="text-left">Net Cash from Investing Activities</td><td class="col-money">{{ $formatter->formatTableNumber($statement['investing']['total']) }}</td></tr>
    </tbody>
  </table>

  <div class="section-title">Financing Activities</div>
  <table class="data">
    <colgroup><col style="width:15%"><col style="width:45%"><col style="width:40%"></colgroup>
    <thead><tr><th class="text-left">Section</th><th class="text-left">Item</th><th class="col-money">Amount</th></tr></thead>
    <tbody>
      @foreach ($statement['financing']['items'] ?? [] as $item)
        <tr><td class="text-left">Financing</td><td class="text-left">{{ $item['label'] }}</td><td class="col-money">{{ $formatter->formatTableNumber($item['amount']) }}</td></tr>
      @endforeach
      <tr class="total-row"><td></td><td class="text-left">Net Cash from Financing Activities</td><td class="col-money">{{ $formatter->formatTableNumber($statement['financing']['total']) }}</td></tr>
    </tbody>
  </table>

  <table class="data">
    <tr class="total-row"><td style="width:60%"></td><td class="text-left" style="width:20%">Net Change in Cash</td><td class="col-money" style="width:20%">{{ $formatter->formatTableNumber($statement['net_change']) }}</td></tr>
  </table>
@endsection
