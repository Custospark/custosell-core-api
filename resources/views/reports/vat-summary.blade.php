@extends('reports.layouts.base')

@section('content')
  @php $ccy = $business->currency; @endphp
  <div class="insights">
    <h4>VAT Return Estimate</h4>
    <ul>
      <li>Net output VAT: {{ $formatter->formatMoney($summary['net_output_vat'], $ccy) }}</li>
      <li>Input VAT (claimable): {{ $formatter->formatMoney($summary['input_vat'], $ccy) }}</li>
      <li>Estimated VAT payable: {{ $formatter->formatMoney($summary['vat_payable'], $ccy) }}</li>
      <li>Jurisdiction: {{ $jurisdictionLabel ?? '—' }}</li>
      <li>{{ $filingHint ?? 'Submit through your tax authority\'s online portal.' }} This report is a workbook, not a filing submission.</li>
    </ul>
  </div>

  <div class="section-title">Sales VAT Breakdown</div>
  <table class="data">
    <thead>
      <tr>
        <th class="text-left">Metric</th>
        <th class="col-money">Amount ({{ $ccy }})</th>
      </tr>
    </thead>
    <tbody>
      <tr><td class="text-left">Taxable sales (net, excl. VAT)</td><td class="col-money">{{ $formatter->formatTableNumber($summary['taxable_sales_net']) }}</td></tr>
      <tr><td class="text-left">Exempt sales (net)</td><td class="col-money">{{ $formatter->formatTableNumber($summary['exempt_sales_net']) }}</td></tr>
      <tr><td class="text-left">Zero-rated sales (net)</td><td class="col-money">{{ $formatter->formatTableNumber($summary['zero_rated_sales_net']) }}</td></tr>
      <tr><td class="text-left">Output VAT collected</td><td class="col-money">{{ $formatter->formatTableNumber($summary['output_vat']) }}</td></tr>
      <tr><td class="text-left">Output VAT on refunds</td><td class="col-money">{{ $formatter->formatTableNumber($summary['output_vat_refunded']) }}</td></tr>
      <tr class="total-row"><td class="text-left">Net output VAT</td><td class="col-money amount-emphasis">{{ $formatter->formatTableNumber($summary['net_output_vat']) }}</td></tr>
    </tbody>
  </table>

  @if(!empty($inputRows))
    <div class="section-title">Claimable Input VAT (Purchases)</div>
    <table class="data">
      <thead>
        <tr>
          <th class="text-left">Date</th>
          <th class="text-left">Category</th>
          <th class="text-left">Description</th>
          <th class="text-left">Supplier TIN</th>
          <th class="text-left">Invoice</th>
          <th class="col-money">Amount</th>
          <th class="col-money">Input VAT</th>
        </tr>
      </thead>
      <tbody>
        @foreach($inputRows as $row)
          <tr>
            <td class="text-left">{{ $row['date'] }}</td>
            <td class="text-left">{{ $row['category'] }}</td>
            <td class="text-left">{{ $row['description'] }}</td>
            <td class="text-left">{{ $row['supplier_tin'] ?? '—' }}</td>
            <td class="text-left">{{ $row['supplier_invoice_no'] ?? '—' }}</td>
            <td class="col-money">{{ $formatter->formatTableNumber($row['amount']) }}</td>
            <td class="col-money">{{ $formatter->formatTableNumber($row['vat_amount']) }}</td>
          </tr>
        @endforeach
        <tr class="total-row">
          <td colspan="6" class="text-left">Total input VAT</td>
          <td class="col-money amount-emphasis">{{ $formatter->formatTableNumber($summary['input_vat']) }}</td>
        </tr>
      </tbody>
    </table>
  @endif

  <div class="section-title">Summary</div>
  <table class="data">
    <tbody>
      <tr class="total-row">
        <td class="text-left">Estimated VAT payable (Net output − Input)</td>
        <td class="col-money amount-emphasis">{{ $formatter->formatMoney($summary['vat_payable'], $ccy) }}</td>
      </tr>
    </tbody>
  </table>
@endsection
