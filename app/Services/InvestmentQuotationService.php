<?php

namespace App\Services;

use App\Models\InvestmentItem;
use App\Models\Plan;
use App\Services\Currency\Contracts\CurrencyExchangeServiceInterface;

/**
 * Customer investment quotations: hardware + software (first-year plan and
 * onboarding) + flat annual maintenance, totalled in UGX with a USD
 * approximation. Bundle lines scale on business drivers (tills, staff,
 * branches) so costs multiply realistically; explicit item rows override.
 */
class InvestmentQuotationService
{
    /** Fallback UGX-per-USD when the live rate is unreachable (labelled approx). */
    private const FALLBACK_UGX_PER_USD = 3700.0;

    /**
     * Recommended kit per tier: item code => [base qty, driver].
     * Drivers: null (flat), 'till', 'staff', 'branch'.
     *
     * @var array<string, array<string, array{qty: int, per: string|null}>>
     */
    public const TIER_BUNDLES = [
        'essential' => [
            'desktop-i5' => ['qty' => 1, 'per' => null],
            'thermal-printer-80' => ['qty' => 1, 'per' => 'tills'],
            'scanner-wired' => ['qty' => 1, 'per' => 'tills'],
            'cash-drawer' => ['qty' => 1, 'per' => 'tills'],
            'ups-650' => ['qty' => 1, 'per' => 'branches'],
            'router-dualband' => ['qty' => 1, 'per' => 'branches'],
            'receipt-rolls-box' => ['qty' => 1, 'per' => null],
            'setup-service' => ['qty' => 1, 'per' => 'branches'],
        ],
        'professional' => [
            'desktop-i5' => ['qty' => 1, 'per' => null],
            'laptop-i5' => ['qty' => 1, 'per' => null],
            'thermal-printer-80' => ['qty' => 1, 'per' => 'tills'],
            'scanner-wireless' => ['qty' => 1, 'per' => 'tills'],
            'label-printer' => ['qty' => 1, 'per' => null],
            'cash-drawer' => ['qty' => 1, 'per' => 'tills'],
            'ups-1500' => ['qty' => 1, 'per' => 'branches'],
            'router-pro' => ['qty' => 1, 'per' => 'branches'],
            'switch-8port' => ['qty' => 1, 'per' => 'branches'],
            'setup-service' => ['qty' => 1, 'per' => 'branches'],
        ],
        'enterprise' => [
            'pos-terminal-15' => ['qty' => 1, 'per' => 'tills'],
            'laptop-i5' => ['qty' => 1, 'per' => 'staff'],
            'thermal-printer-80' => ['qty' => 1, 'per' => 'tills'],
            'scanner-wireless' => ['qty' => 1, 'per' => 'tills'],
            'label-printer' => ['qty' => 1, 'per' => null],
            'cash-drawer' => ['qty' => 1, 'per' => 'tills'],
            'ups-1500' => ['qty' => 1, 'per' => 'branches'],
            'router-pro' => ['qty' => 1, 'per' => 'branches'],
            'switch-8port' => ['qty' => 1, 'per' => 'branches'],
            'setup-service' => ['qty' => 1, 'per' => 'branches'],
        ],
    ];

    /**
     * Minimum operational requirements every site must meet. Quoted alongside
     * every estimate so slow/failing systems trace to unmet requirements,
     * never to the software alone. `provided_by`: who bills meeting it -
     * Custosell (catalog hardware/services), Third party, or Included.
     *
     * @var list<array{area: string, requirement: string, provided_by: string}>
     */
    public const OPERATIONAL_REQUIREMENTS = [
        ['area' => 'Till computer', 'requirement' => 'Windows 11 Pro, Intel Core i3 or better, 8GB RAM, 128GB SSD minimum. One machine per active till.', 'provided_by' => 'Custosell'],
        ['area' => 'Back office', 'requirement' => 'Windows 11, Intel Core i5 or better, 8GB RAM, 256GB SSD with at least 10GB free headroom for data growth.', 'provided_by' => 'Custosell'],
        ['area' => 'Internet', 'requirement' => 'Minimum 5 Mbps dedicated per branch for cloud sync, updates and support. Selling keeps working offline (offline-first POS); sync, backups and the AI assistant need connectivity.', 'provided_by' => 'Third party'],
        ['area' => 'Network', 'requirement' => 'Wired LAN preferred for tills and printers; dual-band WiFi for phones and customers. One router per branch, switch when more than 3 wired devices.', 'provided_by' => 'Custosell'],
        ['area' => 'Power', 'requirement' => 'UPS on every till plus the router (30+ minutes runtime), surge protection on all outlets. Off-grid sites need solar or generator backup.', 'provided_by' => 'Custosell'],
        ['area' => 'Off-grid power', 'requirement' => 'Solar or generator backup where grid power is unreliable.', 'provided_by' => 'Third party'],
        ['area' => 'Printers', 'requirement' => 'USB or LAN thermal printers with auto cutter; barcode scanners USB plug-and-play (no special drivers).', 'provided_by' => 'Custosell'],
        ['area' => 'Data & backups', 'requirement' => 'Automatic cloud backup whenever online; keep a weekly local backup copy. Transaction data grows with sales - review disk space quarterly.', 'provided_by' => 'Included'],
        ['area' => 'Staff readiness', 'requirement' => 'One trained cashier per till plus a supervisor with admin rights. Half-day onboarding training included per branch.', 'provided_by' => 'Included'],
        ['area' => 'Handover & sign-off', 'requirement' => 'The Custosell team commissions every till, pairs printers and scanners, verifies inter-branch sync and staff collaboration, and only signs off when the client team works smoothly on its own.', 'provided_by' => 'Custosell'],
    ];

    public function __construct(
        private CurrencyExchangeServiceInterface $exchange,
    ) {}

    /** @return list<array<string, mixed>> tiers with pricing, maintenance and recommended kit */
    public function packages(): array
    {
        return Plan::query()
            ->where('type', 'business')
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get()
            ->map(fn (Plan $plan) => [
                'slug' => $plan->slug,
                'name' => $plan->name,
                'trial_days' => (int) ($plan->trial_days ?? 0),
                'price_monthly_usd' => (float) ($plan->price_monthly_usd ?? 0),
                'price_yearly_usd' => $plan->price_yearly_usd !== null ? (float) $plan->price_yearly_usd : null,
                'onboarding_fee_usd' => (float) ($plan->onboarding_fee_usd ?? 0),
                'maintenance_fee_ugx' => (float) ($plan->maintenance_fee_ugx ?? 0),
                'bundle' => $this->bundleLines($plan->slug, ['tills' => 1, 'staff' => 1, 'branches' => 1]),
            ])
            ->all();
    }

    /**
     * @param array{tills?: int, staff?: int, branches?: int} $drivers
     * @param list<array{code: string, qty: int}> $extraItems absolute-qty rows added on top
     * @return array<string, mixed> full quotation with grand totals
     */
    public function estimate(string $planSlug, array $drivers = [], array $extraItems = [], string $billing = 'monthly'): array
    {
        $plan = Plan::query()->where('slug', $planSlug)->where('is_active', true)->firstOrFail();
        $drivers = [
            'tills' => max(1, (int) ($drivers['tills'] ?? 1)),
            'staff' => max(1, (int) ($drivers['staff'] ?? 1)),
            'branches' => max(1, (int) ($drivers['branches'] ?? 1)),
        ];

        $lines = $this->bundleLines($plan->slug, $drivers);
        foreach ($extraItems as $extra) {
            $code = (string) ($extra['code'] ?? '');
            $qty = max(0, (int) ($extra['qty'] ?? 0));
            if ($code === '' || $qty < 1) {
                continue;
            }
            $found = false;
            foreach ($lines as &$line) {
                if ($line['code'] === $code) {
                    $line['qty'] += $qty;
                    $line['line_total_ugx'] = $line['qty'] * $line['unit_ugx'];
                    $found = true;
                    break;
                }
            }
            unset($line);
            if (! $found) {
                $item = InvestmentItem::query()->where('code', $code)->where('is_active', true)->first();
                if ($item) {
                    $lines[] = $this->lineFor($item, $qty, null);
                }
            }
        }

        $hardwareUgx = array_sum(array_column($lines, 'line_total_ugx'));

        $billing = $billing === 'yearly' ? 'yearly' : 'monthly';
        $subscriptionUsd = $billing === 'yearly' && $plan->price_yearly_usd !== null
            ? (float) $plan->price_yearly_usd
            : (float) ($plan->price_monthly_usd ?? 0) * 12;
        $onboardingUsd = (float) ($plan->onboarding_fee_usd ?? 0);
        $maintenanceUgx = (float) ($plan->maintenance_fee_ugx ?? 0);

        $rate = $this->ugxPerUsd();
        $subscriptionUgx = $this->toUgx($subscriptionUsd, $rate);
        $onboardingUgx = $this->toUgx($onboardingUsd, $rate);

        $grandUgx = $hardwareUgx + $subscriptionUgx + $onboardingUgx + $maintenanceUgx;

        // Enterprise budgeting split: one-time setup vs every-year costs.
        $oneTimeUgx = $hardwareUgx + $onboardingUgx;
        $annualUgx = $subscriptionUgx + $maintenanceUgx;

        return [
            'plan' => [
                'slug' => $plan->slug,
                'name' => $plan->name,
                'billing' => $billing,
                'trial_days' => (int) ($plan->trial_days ?? 0),
            ],
            'drivers' => $drivers,
            'hardware_lines' => $lines,
            'hardware_total_ugx' => round($hardwareUgx, 2),
            'subscription_first_year_ugx' => round($subscriptionUgx, 2),
            'subscription_first_year_usd' => round($subscriptionUsd, 2),
            'onboarding_ugx' => round($onboardingUgx, 2),
            'onboarding_usd' => round($onboardingUsd, 2),
            'maintenance_annual_ugx' => round($maintenanceUgx, 2),
            'grand_total_ugx' => round($grandUgx, 2),
            'grand_total_usd' => $this->toUsd($grandUgx, $rate),
            'one_time_ugx' => round($oneTimeUgx, 2),
            'one_time_usd' => $this->toUsd($oneTimeUgx, $rate),
            'annual_recurring_ugx' => round($annualUgx, 2),
            'annual_recurring_usd' => $this->toUsd($annualUgx, $rate),
            'usd_rate_note' => $rate['approx']
                ? 'USD figures use an approximate rate of '.number_format($rate['rate'], 0).' UGX per USD.'
                : 'USD figures use the live rate of '.number_format($rate['rate'], 2).' UGX per USD.',
            'operational_requirements' => self::OPERATIONAL_REQUIREMENTS,
            'generated_at' => now()->toDateTimeString(),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function bundleLines(string $planSlug, array $drivers): array
    {
        $lines = [];
        foreach (self::TIER_BUNDLES[$planSlug] ?? [] as $code => $spec) {
            $item = InvestmentItem::query()->where('code', $code)->where('is_active', true)->first();
            if (! $item) {
                continue;
            }
            $qty = $spec['qty'] * ($spec['per'] === null ? 1 : ($drivers[$spec['per']] ?? 1));
            if ($qty < 1) {
                continue;
            }
            $lines[] = $this->lineFor($item, $qty, $spec['per']);
        }

        return $lines;
    }

    /** @return array<string, mixed> */
    private function lineFor(InvestmentItem $item, int $qty, ?string $per): array
    {
        $unit = (float) $item->price_ugx;
        $perLabel = ['tills' => 'till', 'branches' => 'branch', 'staff' => 'staff'][$per ?? ''] ?? $per;

        return [
            'code' => $item->code,
            'category' => $item->category,
            'name' => $item->name,
            'specs' => $item->specs,
            'qty' => $qty,
            'per' => $perLabel,
            'unit_ugx' => round($unit, 2),
            'line_total_ugx' => round($unit * $qty, 2),
        ];
    }

    /** @return array{rate: float, approx: bool} */
    private function ugxPerUsd(): array
    {
        $live = $this->exchange->getExchangeRate('USD', 'UGX');
        if ($live) {
            return ['rate' => (float) $live, 'approx' => false];
        }

        return ['rate' => self::FALLBACK_UGX_PER_USD, 'approx' => true];
    }

    private function toUgx(float $usd, array $rate): float
    {
        return round($usd * $rate['rate'], 2);
    }

    private function toUsd(float $ugx, array $rate): float
    {
        return $rate['rate'] > 0 ? round($ugx / $rate['rate'], 2) : 0.0;
    }
}
