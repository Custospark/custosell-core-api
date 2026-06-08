@if(!empty($trend) && count($trend) > 0)
  @php
    $ccy = $business->currency;
    $accentColor = $accent ?? '#16a34a';
    $days = array_values($trend);
    $maxValue = 1.0;
    foreach ($days as $day) {
      $maxValue = max($maxValue, (float) ($day['gross_sales'] ?? 0), (float) ($day['net_sales'] ?? 0));
    }
  @endphp
  <div class="trend-chart-wrap">
    <div class="section-title">Daily net sales at a glance ({{ $ccy }})</div>
    <p class="chart-legend">
      <span class="legend-item"><span class="legend-swatch legend-gross">&nbsp;</span> Gross sales</span>
      <span class="legend-item"><span class="legend-swatch legend-net" style="background-color: {{ $accentColor }};">&nbsp;</span> Net sales</span>
    </p>
    <table class="chart-trend">
      <thead>
        <tr>
          <th class="text-left" style="width:14%">Date</th>
          <th class="col-money" style="width:16%">Gross</th>
          <th class="col-money" style="width:16%">Net sales</th>
          <th class="text-left" style="width:54%">Relative scale (vs best day)</th>
        </tr>
      </thead>
      <tbody>
        @foreach($days as $day)
          @php
            $gross = (float) ($day['gross_sales'] ?? 0);
            $net = (float) ($day['net_sales'] ?? 0);
            $grossPct = max(1, (int) round(($gross / $maxValue) * 100));
            $netPct = max(1, (int) round(($net / $maxValue) * 100));
            $dateLabel = \Carbon\Carbon::parse($day['date'])->format('D, M d');
          @endphp
          <tr>
            <td class="text-left">{{ $dateLabel }}</td>
            <td class="col-money">{{ $formatter->formatTableNumber($gross) }}</td>
            <td class="col-money amount-emphasis">{{ $formatter->formatTableNumber($net) }}</td>
            <td class="chart-bar-cell">
              <table class="bar-track" width="100%" cellpadding="0" cellspacing="0">
                <tr>
                  <td class="bar-gross" width="{{ $grossPct }}%" bgcolor="#e2e8f0" height="7">&nbsp;</td>
                  <td width="{{ 100 - $grossPct }}%">&nbsp;</td>
                </tr>
                <tr>
                  <td class="bar-net" width="{{ $netPct }}%" bgcolor="{{ $accentColor }}" height="7">&nbsp;</td>
                  <td width="{{ 100 - $netPct }}%">&nbsp;</td>
                </tr>
              </table>
            </td>
          </tr>
        @endforeach
      </tbody>
    </table>
    <p class="chart-caption">Each row compares one day. Net sales = gross - refunds - expenses for that day.</p>
  </div>
@endif
