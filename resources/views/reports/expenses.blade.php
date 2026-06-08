@extends('reports.layouts.base')

@section('content')
  @php $ccy = $business->currency; @endphp
  <div class="insights">
    <h4>Period Context</h4>
    <ul>
      <li>Expenses this period: {{ $formatter->formatMoney($total, $ccy) }} ({{ $period['expense_ratio_pct'] }}% of gross sales)</li>
      <li>Net sales after expenses: {{ $formatter->formatMoney($period['net_sales'], $ccy) }}</li>
    </ul>
  </div>

  @if(!empty($categorySummary))
    <div class="section-title">By Category</div>
    <table class="data">
      <colgroup>
        <col style="width:54%">
        <col style="width:14%">
        <col style="width:32%">
      </colgroup>
      <thead>
        <tr>
          <th class="text-left">Category</th>
          <th class="col-num">Count</th>
          <th class="col-money">Total ({{ $ccy }})</th>
        </tr>
      </thead>
      <tbody>
        @foreach($categorySummary as $category)
          <tr>
            <td class="text-left">{{ $category['category_name'] }}</td>
            <td class="col-num">{{ $category['count'] }}</td>
            <td class="col-money">{{ $formatter->formatTableNumber($category['total']) }}</td>
          </tr>
        @endforeach
      </tbody>
    </table>
  @endif

  <div class="section-title">Expense Details</div>
  <table class="data">
    <colgroup>
      <col style="width:13%">
      <col style="width:17%">
      <col style="width:42%">
      <col style="width:28%">
    </colgroup>
    <thead>
      <tr>
        <th class="text-left">Date</th>
        <th class="text-left">Category</th>
        <th class="text-left">Description</th>
        <th class="col-money">Amount ({{ $ccy }})</th>
      </tr>
    </thead>
    <tbody>
      @foreach($expenses as $expense)
        <tr>
          <td class="text-left">{{ $expense->expense_date instanceof \Carbon\Carbon ? $expense->expense_date->format('M d, Y') : $expense->expense_date }}</td>
          <td class="text-left">{{ $expense->expenseCategory?->name ?? 'N/A' }}</td>
          <td class="text-left">{{ $expense->description }}</td>
          <td class="col-money">{{ $formatter->formatTableNumber((float)$expense->amount) }}</td>
        </tr>
      @endforeach
      <tr class="total-row">
        <td colspan="3" class="text-left">Total Expenses</td>
        <td class="col-money amount-emphasis">{{ $formatter->formatTableNumber($total) }}</td>
      </tr>
    </tbody>
  </table>
@endsection
