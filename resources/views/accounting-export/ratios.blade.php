@extends('reports.layouts.base')

@section('content')
  @foreach (['liquidity' => 'Liquidity Ratios', 'profitability' => 'Profitability Ratios', 'solvency' => 'Solvency Ratios', 'efficiency' => 'Efficiency Ratios'] as $cat => $title)
    <div class="section-title">{{ $title }}</div>
    <table class="data">
      <colgroup><col style="width:40%"><col style="width:30%"><col style="width:30%"></colgroup>
      <thead><tr><th class="text-left">Ratio</th><th class="text-left">Value</th><th class="text-left">Status</th></tr></thead>
      <tbody>
        @foreach ($ratios[$cat] as $key => $val)
          <tr>
            <td class="text-left">{{ ucwords(str_replace('_', ' ', $key)) }}</td>
            <td class="col-money">{{ $val !== null ? number_format($val, 2) : 'N/A' }}</td>
            <td class="text-left">{{ $val !== null ? ($val > 0 ? 'Positive' : 'Negative') : 'No data' }}</td>
          </tr>
        @endforeach
      </tbody>
    </table>
  @endforeach
@endsection
