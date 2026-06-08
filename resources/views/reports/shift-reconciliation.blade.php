@extends('reports.layouts.base')

@section('content')
  @php $ccy = $business->currency; @endphp
  <table class="data">
    <colgroup>
      <col style="width:14%">
      <col style="width:11%">
      <col style="width:6%">
      <col style="width:13%">
      <col style="width:12%">
      <col style="width:14%">
      <col style="width:12%">
      <col style="width:18%">
    </colgroup>
    <thead>
      <tr>
        <th class="text-left">Shift Started</th>
        <th class="text-left">Cashier</th>
        <th class="col-num">Txns</th>
        <th class="col-money">Gross ({{ $ccy }})</th>
        <th class="col-money">Refunds ({{ $ccy }})</th>
        <th class="col-money">Net ({{ $ccy }})</th>
        <th class="col-money">Expenses ({{ $ccy }})</th>
        <th class="col-money">Handover ({{ $ccy }})</th>
      </tr>
    </thead>
    <tbody>
      @forelse($shifts as $row)
        <tr>
          <td class="text-left">{{ $row['shift']->clock_in->format('M d, H:i') }}</td>
          <td class="text-left">{{ $row['cashier'] }}</td>
          <td class="col-num">{{ $row['transaction_count'] }}</td>
          <td class="col-money">{{ $formatter->formatTableNumber($row['gross_sales']) }}</td>
          <td class="col-money text-red">-{{ $formatter->formatTableNumber($row['refunds']) }}</td>
          <td class="col-money">{{ $formatter->formatTableNumber($row['net_after_refunds']) }}</td>
          <td class="col-money text-red">-{{ $formatter->formatTableNumber($row['shift_expenses']) }}</td>
          <td class="col-money amount-emphasis">{{ $formatter->formatTableNumber($row['cash_handover']) }}</td>
        </tr>
        <tr class="line-items-row">
          <td colspan="8" class="line-items text-left">
            <span>Cash: {{ $formatter->formatTableNumber($row['cash']) }}</span>
            <span>Mobile: {{ $formatter->formatTableNumber($row['mobile_money']) }}</span>
            <span>Card/Other: {{ $formatter->formatTableNumber($row['card_other']) }}</span>
          </td>
        </tr>
      @empty
        <tr><td colspan="8" class="text-center text-muted">No shifts found for this period.</td></tr>
      @endforelse
    </tbody>
  </table>
@endsection
