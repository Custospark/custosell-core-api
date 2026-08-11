<?php

namespace App\Services;

use App\Services\Concerns\ComputesDashboardAndVat;
use App\Services\Concerns\ComputesProductPerformance;
use App\Services\Concerns\ComputesSaleMetrics;
use App\Services\Concerns\ComputesShiftMetrics;

/**
 * Canonical accounting:
 * - net_sales (period/day) = gross − refunds − expenses
 * - net_after_refunds = gross − refunds (per sale, shift sales headline)
 *
 * Facade over trait-based metric groups so every report controller keeps
 * a single injected service while each concern stays under 500 lines.
 */
class ReportMetricsService
{
    use ComputesSaleMetrics;
    use ComputesProductPerformance;
    use ComputesShiftMetrics;
    use ComputesDashboardAndVat;

    public const BRAND_TAGLINE = 'Your Business Operating System';

    public const BRAND_FOOTER = 'Powered by Custosell · A product of Custospark Company Ltd';

    public const BRAND_CUSTOSELL_URL = 'https://www.custosell.com';

    public const BRAND_CUSTOSPARK_URL = 'https://www.custospark.com';
}
