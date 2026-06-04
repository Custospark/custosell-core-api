<!DOCTYPE html>
<html>
<head><meta charset="utf-8"><title>{{ $business->name }} — Daily Sales Report</title>
<style>
  body { font-family: 'Helvetica', 'Arial', sans-serif; font-size: 10px; color: #333; margin: 0; padding: 20px; }
  .header { text-align: center; margin-bottom: 20px; border-bottom: 2px solid #1e40af; padding-bottom: 15px; }
  .header h1 { font-size: 20px; color: #1e40af; margin: 0 0 4px 0; }
  .header p { margin: 1px 0; color: #555; font-size: 9px; }
  .report-title { text-align: center; margin-bottom: 4px; }
  .report-title h2 { font-size: 14px; color: #333; margin: 0; }
  .report-title p { font-size: 9px; color: #666; margin: 2px 0 12px 0; }
  table { width: 100%; border-collapse: collapse; margin-top: 8px; }
  th { background: #1e40af; color: #fff; text-align: left; padding: 7px 8px; font-size: 9px; text-transform: uppercase; letter-spacing: 0.5px; }
  td { padding: 5px 8px; border-bottom: 1px solid #e5e7eb; font-size: 10px; }
  .text-right { text-align: right; }
  .text-center { text-align: center; }
  .total-row td { font-weight: bold; border-top: 2px solid #1e40af; background: #f0f4ff; }
  .footer { text-align: center; color: #999; font-size: 8px; margin-top: 20px; border-top: 1px solid #e5e7eb; padding-top: 10px; }
</style>
</head>
<body>
  <div class="header">
    <h1>{{ $business->name }}</h1>
    @if($business->address)<p>{{ $business->address }}</p>@endif
    @if($business->city || $business->state)<p>{{ $business->city }}{{ $business->city && $business->state ? ', ' : '' }}{{ $business->state }}</p>@endif
    @if($business->phone)<p>Phone: {{ $business->phone }}</p>@endif
    @if($business->email)<p>Email: {{ $business->email }}</p>@endif
  </div>

  <div class="report-title">
    <h2>Daily Sales Report</h2>
    <p>{{ $dateFrom }} — {{ $dateTo }}</p>
  </div>

  <table>
    <thead>
      <tr><th>Receipt</th><th>Date</th><th class="text-center">Items</th><th>Payment</th><th class="text-right">Total ({{ $business->currency }})</th></tr>
    </thead>
    <tbody>
      @foreach($sales as $s)
      <tr>
        <td>{{ $s->receipt_number }}</td>
        <td>{{ $s->sale_date instanceof \Carbon\Carbon ? $s->sale_date->format('M d, Y') : $s->sale_date }}</td>
        <td class="text-center">{{ $s->saleItems->count() }}</td>
        <td>{{ ucfirst(str_replace('_', ' ', $s->payment_method)) }}</td>
        <td class="text-right">{{ number_format((float)$s->total_amount, 2) }}</td>
      </tr>
      @endforeach
      <tr class="total-row">
        <td colspan="4">Total</td>
        <td class="text-right">{{ number_format($sales->sum('total_amount'), 2) }}</td>
      </tr>
    </tbody>
  </table>

  <div class="footer">Generated on {{ now()->format('M d, Y H:i:s') }} — {{ $business->name }}</div>
</body>
</html>
