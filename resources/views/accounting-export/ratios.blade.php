@extends('reports.layouts.base')

@section('content')
  @php
    $catLabels = ['liquidity' => 'Liquidity Ratios', 'profitability' => 'Profitability Ratios', 'solvency' => 'Solvency Ratios', 'efficiency' => 'Efficiency Ratios'];
  @endphp

  @if(!empty($periodName))
    <p style="text-align:center;font-size:10px;color:#4b5563;margin-bottom:12px;">
      Period: {{ $periodName }}@if($periodStart) ({{ $periodStart }} to {{ $periodEnd }})@endif
    </p>
  @endif

  @foreach ($catLabels as $cat => $title)
    <div class="section-title">{{ $title }}</div>
    <table class="data">
      <colgroup><col style="width:40%"><col style="width:30%"><col style="width:30%"></colgroup>
      <thead><tr><th class="text-left">Ratio</th><th class="col-money">Value</th><th class="text-left">Health</th></tr></thead>
      <tbody>
        @foreach ($ratios[$cat] as $key => $val)
          <tr>
            <td class="text-left">{{ ucwords(str_replace('_', ' ', $key)) }}</td>
            <td class="col-money">{{ $val !== null ? number_format($val, 2) : 'N/A' }}</td>
            <td class="text-left">
              @if($val === null)
                No data
              @elseif(in_array($key, ['debt_to_equity','debt_ratio']) && $val <= 1)
                Healthy
              @elseif(in_array($key, ['current_ratio','quick_ratio','cash_ratio']) && $val >= 1)
                Healthy
              @elseif(in_array($key, ['gross_profit_margin','net_profit_margin','return_on_assets','return_on_equity','interest_coverage_ratio']) && $val >= 10)
                Healthy
              @elseif($val > 0)
                Monitor
              @else
                Critical
              @endif
            </td>
          </tr>
        @endforeach
      </tbody>
    </table>
  @endforeach

  @if(!empty($ratios['recommendations']))
    <div class="section-title">Recommendations</div>
    <table class="data">
      <colgroup><col style="width:15%"><col style="width:20%"><col style="width:65%"></colgroup>
      <thead><tr><th class="text-left">Priority</th><th class="text-left">Ratio</th><th class="text-left">Recommendation</th></tr></thead>
      <tbody>
        @foreach (array_slice($ratios['recommendations'], 0, 5) as $rec)
          <tr>
            <td class="text-left">
              <span class="badge {{ $rec['priority'] === 'high' ? 'badge-refunded' : ($rec['priority'] === 'medium' ? 'badge-partial' : 'badge-paid') }}">
                {{ ucfirst($rec['priority']) }}
              </span>
            </td>
            <td class="text-left">{{ $rec['label'] }}</td>
            <td class="text-left">{{ $rec['message'] }}</td>
          </tr>
        @endforeach
      </tbody>
    </table>
  @endif
@endsection
