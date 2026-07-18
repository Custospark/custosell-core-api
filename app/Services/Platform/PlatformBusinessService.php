<?php

namespace App\Services\Platform;

use App\Models\Business;
use App\Models\Sale;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class PlatformBusinessService
{
    public function __construct(
        protected PlatformNotificationService $notifications,
        protected PlatformAuditService $audit,
        protected PlatformNotificationDispatchService $dispatches,
    ) {}

    public function activityWindowDays(): int
    {
        return (int) config('platform.activity_window_days', 30);
    }

    public function activityDormantDays(): int
    {
        return max($this->activityWindowDays(), (int) config('platform.activity_dormant_days', 90));
    }

    /** @return list<string> */
    public function allowedStatuses(): array
    {
        return config('platform.business_statuses', ['active', 'warning', 'restricted', 'suspended']);
    }

    /** @return list<string> */
    public function blockedStatuses(): array
    {
        return config('platform.blocked_business_statuses', ['restricted', 'suspended']);
    }

    /** @return list<string> */
    public function notificationIntentions(): array
    {
        return config('platform.notification_intentions', ['announcement', 'custom']);
    }

    public function paginate(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        $windowStart = now()->subDays($this->activityWindowDays());

        $query = $this->businessMetricsQuery($windowStart);

        if (! empty($filters['search'])) {
            $search = '%'.$filters['search'].'%';
            $query->where(function ($q) use ($search) {
                $q->where('businesses.name', 'like', $search)
                    ->orWhere('businesses.email', 'like', $search)
                    ->orWhereHas('owner', fn ($oq) => $oq->where('email', 'like', $search));
            });
        }

        if (! empty($filters['status'])) {
            $query->where('businesses.status', $filters['status']);
        }

        if (! empty($filters['currency'])) {
            $query->where('businesses.currency', $filters['currency']);
        }

        $sort = $filters['sort'] ?? 'gross_sales_30d';
        $direction = ($filters['direction'] ?? 'desc') === 'asc' ? 'asc' : 'desc';

        if ($sort === 'name') {
            $query->orderBy('businesses.name', $direction);
        } elseif ($sort === 'created_at') {
            $query->orderBy('businesses.created_at', $direction);
        } else {
            $query->orderBy('gross_sales_30d', $direction);
        }

        $paginator = $query->paginate($perPage);
        $this->hydrateOwners($paginator->getCollection());
        $paginator->getCollection()->transform(fn (Business $business) => $this->transformBusiness($business, $windowStart));

        return $paginator;
    }

    /**
     * Gross sales (SUM of sale totals) per business for the activity window.
     *
     * @return Collection<int, array{business: Business, gross_sales_30d: float}>
     */
    public function businessesWithGrossSales30d(?Carbon $windowStart = null): Collection
    {
        $windowStart ??= now()->subDays($this->activityWindowDays());

        $businesses = $this->businessMetricsQuery($windowStart)->get();
        $this->hydrateOwners($businesses);

        return $businesses->map(function (Business $business) use ($windowStart) {
            $gross30d = (float) ($business->getAttributes()['gross_sales_30d'] ?? 0);
            $business->gross_sales_30d = $gross30d;

            return [
                'business' => $business,
                'gross_sales_30d' => $gross30d,
                'row' => $this->transformBusiness($business, $windowStart),
            ];
        });
    }

    public function countBusinessesWithAttributedSalesOnDate(string $date): int
    {
        return (int) Business::query()
            ->whereExists(function ($query) use ($date): void {
                $query->selectRaw('1')
                    ->from('sales')
                    ->whereNull('sales.deleted_at')
                    ->whereDate('sales.sale_date', $date);
                $this->applyAttributedSalesConstraint($query);
            })
            ->count();
    }

    /**
     * Five equal-width gross-sales bands per currency (lowest → highest).
     *
     * @return list<array{currency: string, tiers: list<array<string, mixed>>, decision_note: string}>
     */
    public function grossIncomeDistribution(?Carbon $windowStart = null, int $tierCount = 5): array
    {
        $windowStart ??= now()->subDays($this->activityWindowDays());
        $rows = $this->businessesWithGrossSales30d($windowStart);

        return $rows
            ->groupBy(fn (array $entry) => $entry['business']->currency ?? 'UGX')
            ->map(function (Collection $currencyRows, string $currency) use ($tierCount) {
                $amounts = $currencyRows->pluck('gross_sales_30d')->map(fn ($v) => (float) $v);
                $min = (float) $amounts->min();
                $max = (float) $amounts->max();
                $tiers = $this->buildGrossTiers($currencyRows, $min, $max, $tierCount, $currency);

                $withSales = $currencyRows->filter(fn (array $e) => $e['gross_sales_30d'] > 0)->count();
                $totalBusinesses = $currencyRows->count();
                $topTier = collect($tiers)->last();
                $bottomTier = collect($tiers)->first();

                $decisionNote = $withSales === 0
                    ? "No {$currency} businesses recorded gross sales in the last {$this->activityWindowDays()} days."
                    : sprintf(
                        '%d of %d %s businesses had gross sales (30d). %d%% sit in the lowest tier — consider an entry plan. %d%% are in the top tier — candidates for premium pricing.',
                        $withSales,
                        $totalBusinesses,
                        $currency,
                        $totalBusinesses > 0 ? (int) round(($bottomTier['business_count'] / $totalBusinesses) * 100) : 0,
                        $totalBusinesses > 0 ? (int) round(($topTier['business_count'] / $totalBusinesses) * 100) : 0,
                    );

                return [
                    'currency' => $currency,
                    'tiers' => $tiers,
                    'decision_note' => $decisionNote,
                ];
            })
            ->values()
            ->all();
    }

    /** @return array<string, mixed> */
    public function transformBusiness(Business $business, ?Carbon $windowStart = null): array
    {
        $windowStart ??= now()->subDays($this->activityWindowDays());

        $activity = $this->resolveActivityProfile($business, $windowStart);

        $owner = $this->resolveOwner($business);

        $gross30d = (float) ($business->getAttributes()['gross_sales_30d'] ?? $business->gross_sales_30d ?? 0);

        return [
            'id' => $business->id,
            'name' => $business->name,
            'slug' => $business->slug,
            'email' => $business->email,
            'currency' => $business->currency,
            'status' => $business->status,
            'status_changed_at' => $business->status_changed_at?->toIso8601String(),
            'activity_status' => $activity['activity_status'],
            'last_sale_at' => $activity['last_sale_at'],
            'last_login_at' => $activity['last_login_at'],
            'last_activity_at' => $activity['last_activity_at'],
            'days_since_activity' => $activity['days_since_activity'],
            'activity_active_days' => $activity['activity_active_days'],
            'activity_dormant_days' => $activity['activity_dormant_days'],
            'owner_name' => $owner?->name,
            'owner_email' => $owner?->email ?? $business->email,
            'owner_phone' => $owner?->phone ?? $business->phone,
            'plan_name' => $business->subscription?->plan?->name,
            'subscription_status' => $business->subscription?->status,
            'trial_ends_at' => $business->trial_ends_at?->toIso8601String(),
            'staff_count' => $this->resolveStaffCount($business),
            'gross_sales_today' => $this->formatGross((float) ($business->getAttributes()['gross_sales_today'] ?? 0)),
            'gross_sales_7d' => $this->formatGross((float) ($business->getAttributes()['gross_sales_7d'] ?? 0)),
            'gross_sales_30d' => $this->formatGross($gross30d),
            'gross_sales_all_time' => $this->formatGross((float) ($business->getAttributes()['gross_sales_all_time'] ?? 0)),
            'transactions_30d' => (int) ($business->transactions_30d ?? 0),
            'total_stock' => (int) ($business->total_stock ?? 0),
            'created_at' => $business->created_at?->toIso8601String(),
        ];
    }

    /**
     * Activity is based on recency of the latest sale OR staff login — not lifetime sale volume.
     *
     * @return array{
     *     activity_status: string,
     *     last_sale_at: string|null,
     *     last_login_at: string|null,
     *     last_activity_at: string|null,
     *     days_since_activity: int|null,
     *     activity_active_days: int,
     *     activity_dormant_days: int,
     * }
     */
    private function resolveActivityProfile(Business $business, ?Carbon $windowStart = null): array
    {
        $activeDays = $this->activityWindowDays();
        $dormantDays = $this->activityDormantDays();
        $windowStart ??= now()->subDays($activeDays);

        $lastSaleAt = $business->last_sale_at ? Carbon::parse($business->last_sale_at) : null;
        $lastLoginAt = $business->last_user_login_at ? Carbon::parse($business->last_user_login_at) : null;
        $lastActivityAt = collect([$lastSaleAt, $lastLoginAt])->filter()->max();

        $daysSinceActivity = $lastActivityAt
            ? (int) $lastActivityAt->diffInDays(now())
            : null;

        $activityStatus = 'never_used';
        if (in_array($business->status, $this->blockedStatuses(), true)) {
            $activityStatus = 'suspended';
        } elseif ($lastActivityAt === null) {
            $activityStatus = 'never_used';
        } elseif ($daysSinceActivity <= $activeDays) {
            $activityStatus = 'active';
        } elseif ($daysSinceActivity <= $dormantDays) {
            $activityStatus = 'dormant';
        } else {
            $activityStatus = 'churned';
        }

        return [
            'activity_status' => $activityStatus,
            'last_sale_at' => $lastSaleAt?->toIso8601String(),
            'last_login_at' => $lastLoginAt?->toIso8601String(),
            'last_activity_at' => $lastActivityAt?->toIso8601String(),
            'days_since_activity' => $daysSinceActivity,
            'activity_active_days' => $activeDays,
            'activity_dormant_days' => $dormantDays,
        ];
    }

    /**
     * Onboarding and growth stats for the platform businesses dashboard.
     *
     * @return array<string, mixed>
     */
    public function onboardingDashboard(?Carbon $rangeFrom = null, ?Carbon $rangeTo = null): array
    {
        $rangeFrom ??= now()->subDays(29)->startOfDay();
        $rangeTo ??= now()->endOfDay();

        if ($rangeFrom->gt($rangeTo)) {
            [$rangeFrom, $rangeTo] = [$rangeTo->copy()->startOfDay(), $rangeFrom->copy()->endOfDay()];
        }

        $todayStart = now()->startOfDay();
        $weekStart = now()->startOfWeek();
        $monthStart = now()->startOfMonth();
        $windowStart = now()->subDays($this->activityWindowDays());

        $joinedToday = Business::where('created_at', '>=', $todayStart)->count();
        $joinedThisWeek = Business::where('created_at', '>=', $weekStart)->count();
        $joinedThisMonth = Business::where('created_at', '>=', $monthStart)->count();
        $joinedInRange = Business::whereBetween('created_at', [$rangeFrom, $rangeTo])->count();

        $totalBusinesses = Business::count();
        $suspendedCount = Business::where('status', 'suspended')->count();
        $warningCount = Business::where('status', 'warning')->count();
        $notifiedCount = Business::where('status', 'notified')->count();
        $restrictedCount = Business::where('status', 'restricted')->count();
        $activeStatusCount = Business::where('status', 'active')->count();

        $withGrossSales30d = $this->businessesWithGrossSales30d($windowStart)
            ->filter(fn (array $entry) => $entry['gross_sales_30d'] > 0)
            ->count();

        $platformTransactions30d = (int) Sale::where('sale_date', '>=', $windowStart)->count();
        $platformGrossSales30d = (float) Sale::where('sale_date', '>=', $windowStart)->sum('total_amount');

        $cumulativeBeforeRange = Business::where('created_at', '<', $rangeFrom)->count();
        $growth = [];
        $cursor = $rangeFrom->copy()->startOfDay();
        $end = $rangeTo->copy()->startOfDay();
        $runningTotal = $cumulativeBeforeRange;

        while ($cursor->lte($end)) {
            $date = $cursor->toDateString();
            $signups = Business::whereDate('created_at', $date)->count();
            $runningTotal += $signups;
            $growth[] = [
                'date' => $date,
                'signups' => $signups,
                'cumulative' => $runningTotal,
            ];
            $cursor->addDay();
        }

        $growthRateWeek = $joinedThisWeek > 0 && $totalBusinesses > 0
            ? round(($joinedThisWeek / max(1, $totalBusinesses - $joinedThisWeek)) * 100, 1)
            : 0.0;

        return [
            'onboarding' => [
                'today' => $joinedToday,
                'this_week' => $joinedThisWeek,
                'this_month' => $joinedThisMonth,
                'in_range' => $joinedInRange,
                'range_from' => $rangeFrom->toDateString(),
                'range_to' => $rangeTo->toDateString(),
            ],
            'totals' => [
                'total' => $totalBusinesses,
                'active_status' => $activeStatusCount,
                'warning' => $warningCount,
                'notified' => $notifiedCount,
                'restricted' => $restrictedCount,
                'suspended' => $suspendedCount,
                'with_gross_sales_30d' => $withGrossSales30d,
                'transactions_30d' => $platformTransactions30d,
                'gross_sales_30d' => number_format($platformGrossSales30d, 2, '.', ''),
            ],
            'growth' => $growth,
            'decisions' => [
                $joinedToday > 0
                    ? "{$joinedToday} new business(es) joined today — prioritize welcome onboarding."
                    : 'No new signups today — focus on re-activating dormant accounts.',
                $joinedThisMonth > 0
                    ? "{$joinedThisMonth} joined this month ({$growthRateWeek}% weekly growth vs existing base)."
                    : 'No new businesses this month — review acquisition channels.',
                $withGrossSales30d > 0
                    ? "{$withGrossSales30d} of {$totalBusinesses} businesses recorded sales in the last {$this->activityWindowDays()} days."
                    : "No businesses recorded sales in the last {$this->activityWindowDays()} days.",
                $platformTransactions30d > 0
                    ? number_format($platformTransactions30d).' sale transactions platform-wide in the last '.$this->activityWindowDays().' days.'
                    : 'No sale transactions in the activity window.',
            ],
        ];
    }

    public function updateStatus(
        User $actor,
        Business $business,
        string $status,
        string $reason,
        string $channel = 'both',
    ): Business
    {
        $previous = $business->status;

        $business->update([
            'status' => $status,
            'status_changed_at' => now(),
        ]);

        $this->audit->log(
            $actor,
            $this->auditActionForStatus($status),
            'business',
            $business->id,
            $reason,
            ['from' => $previous, 'to' => $status],
        );

        $this->notifications->notifyBusinessStatusChange(
            $business,
            $status,
            $reason,
            $channel,
        );

        $inAppRecipients = $this->notifications->businessRecipientUsers($business)->count();

        $this->dispatches->recordStatusChange(
            $actor,
            'business',
            $reason,
            $channel,
            $previous,
            $status,
            [$this->dispatches->recipientFromBusiness($business->loadMissing('owner'), $inAppRecipients)],
            $status === 'warning' ? 'warning_notice' : null,
        );

        return $business->fresh(['owner', 'subscription.plan']);
    }

    public function bulkUpdateStatus(
        User $actor,
        array $ids,
        string $status,
        string $reason,
        string $channel = 'both',
    ): int {
        $count = 0;
        $businesses = Business::with('owner')->whereIn('id', $ids)->get();

        foreach ($businesses as $business) {
            if ($business->status === $status) {
                continue;
            }
            $this->updateStatus($actor, $business, $status, $reason, $channel);
            $count++;
        }

        return $count;
    }

    public function delete(User $actor, Business $business, string $reason): void
    {
        DB::transaction(function () use ($actor, $business, $reason): void {
            $salesDeleted = $this->purgeSalesData($business);

            $this->audit->log($actor, 'business.deleted', 'business', $business->id, $reason, [
                'name' => $business->name,
                'status' => $business->status,
                'sales_deleted' => $salesDeleted,
            ]);

            $business->delete();
        });
    }

    private function purgeSalesData(Business $business): int
    {
        $deleted = 0;

        Sale::withTrashed()
            ->where('business_id', $business->id)
            ->orderBy('id')
            ->chunkById(200, function ($sales) use (&$deleted): void {
                foreach ($sales as $sale) {
                    $sale->forceDelete();
                    $deleted++;
                }
            });

        return $deleted;
    }

    public function resetBusinessData(User $actor, Business $business): array
    {
        $businessId = $business->id;
        $counts = [];

        DB::transaction(function () use ($businessId, $actor, $business, &$counts): void {
            // ── 1. Pipeline / CRM ──────────────────────────────────
            $counts['pipeline_sources'] = DB::table('pipeline_sources')
                ->where('business_id', $businessId)->delete();

            // Deleting boards cascades to stages, leads, activities,
            // meetings, links, members, polls, checklists, messages, etc.
            $counts['pipeline_boards'] = DB::table('pipeline_boards')
                ->where('business_id', $businessId)->delete();

            // Remaining pipeline tables that only FK to business_id
            foreach (['pipeline_board_templates', 'pipeline_labels', 'pipeline_reminders'] as $t) {
                $counts[$t] = DB::table($t)->where('business_id', $businessId)->delete();
            }

            // ── 2. Estimates ──────────────────────────────────────
            // estimate_line_items cascade from estimates
            $counts['estimate_versions'] = DB::table('estimate_versions')
                ->whereIn('estimate_id', fn($q) => $q->select('id')->from('estimates')->where('business_id', $businessId))
                ->delete();
            $counts['estimates'] = DB::table('estimates')
                ->where('business_id', $businessId)->delete();
            $counts['estimate_templates'] = DB::table('estimate_templates')
                ->where('business_id', $businessId)->delete();

            // ── 3. Projects ───────────────────────────────────────
            // project_tasks, project_members, project_cost_allocations cascade from projects
            $counts['projects'] = DB::table('projects')
                ->where('business_id', $businessId)->delete();

            // ── 4. Documents ──────────────────────────────────────
            // document_cabinets -> folders -> documents cascade
            $counts['document_cabinets'] = DB::table('document_cabinets')
                ->where('business_id', $businessId)->delete();

            // ── 5. Invoices (before payments since payments reference invoices) ─
            $counts['invoice_items'] = DB::table('invoice_items')
                ->whereIn('invoice_id', fn($q) => $q->select('id')->from('invoices')->where('business_id', $businessId))
                ->delete();
            $counts['invoices'] = DB::table('invoices')
                ->where('business_id', $businessId)->delete();

            // ── 6. Payments (polymorphic — clear by business_id) ──
            $counts['payments'] = DB::table('payments')
                ->where('business_id', $businessId)->delete();

            // ── 7. Orders ─────────────────────────────────────────
            // order_items cascade from orders
            $counts['orders'] = DB::table('orders')
                ->where('business_id', $businessId)->delete();

            // ── 8. Sales ──────────────────────────────────────────
            // sale_items cascade from sales
            $counts['shifts'] = DB::table('shifts')
                ->where('business_id', $businessId)->delete();
            $counts['sales'] = DB::table('sales')
                ->where('business_id', $businessId)->delete();

            // ── 9. Purchase orders (buyer + seller) ───────────────
            $counts['purchase_order_items'] = DB::table('purchase_order_items')
                ->whereIn('purchase_order_id', fn($q) => $q->select('id')->from('purchase_orders')
                    ->where('buyer_business_id', $businessId)
                    ->orWhere('seller_business_id', $businessId))
                ->delete();
            $counts['purchase_orders'] = DB::table('purchase_orders')
                ->where('buyer_business_id', $businessId)
                ->orWhere('seller_business_id', $businessId)
                ->delete();

            // ── 10. Stock movements ──────────────────────────────
            $counts['stock_movements'] = DB::table('stock_movements')
                ->where('business_id', $businessId)->delete();

            // ── 11. Products (cascades to wishlists, ratings) ────
            $counts['products'] = DB::table('products')
                ->where('business_id', $businessId)->delete();

            // ── 12. Categories ────────────────────────────────────
            $counts['categories'] = DB::table('categories')
                ->where('business_id', $businessId)->delete();

            // ── 13. Customers ────────────────────────────────────
            $counts['customers'] = DB::table('customers')
                ->where('business_id', $businessId)->delete();

            // ── 14. Expenses ─────────────────────────────────────
            $counts['expenses'] = DB::table('expenses')
                ->where('business_id', $businessId)->delete();
            $counts['expense_categories'] = DB::table('expense_categories')
                ->where('business_id', $businessId)->delete();

            // ── 15. Accounting ────────────────────────────────────
            // journal_entry_lines cascade from journal_entries
            // depreciation_entries cascade from fixed_assets
            // fixed_asset_assignments cascade from fixed_assets
            $counts['fixed_assets'] = DB::table('fixed_assets')
                ->where('business_id', $businessId)->delete();
            $counts['journal_entries'] = DB::table('journal_entries')
                ->where('business_id', $businessId)->delete();
            $counts['general_ledger'] = DB::table('general_ledger')
                ->where('business_id', $businessId)->delete();
            $counts['accounting_periods'] = DB::table('accounting_periods')
                ->where('business_id', $businessId)->delete();
            $counts['chart_of_accounts'] = DB::table('chart_of_accounts')
                ->where('business_id', $businessId)->delete();

            // ── 16. Supplier / storefront ratings ────────────────
            DB::table('business_supplier_list_entries')
                ->where('business_id', $businessId)->delete();
            DB::table('business_storefront_ratings')
                ->where('business_id', $businessId)->delete();

            // ── 17. Bookings ──────────────────────────────────────
            DB::table('board_booking_settings')
                ->whereIn('board_id', fn($q) => $q->select('id')->from('pipeline_boards')
                    ->where('business_id', $businessId))
                ->delete();
            // board_wall_posts cascade from pipeline_boards

            // ── 18. Notifications ─────────────────────────────────
            DB::table('notifications')
                ->where('business_id', $businessId)->delete();

            $this->audit->log(
                $actor,
                'business.data_reset',
                'business',
                $businessId,
                'Business data reset for fresh start',
                ['reset_counts' => $counts],
            );
        });

        return $counts;
    }

    public function bulkDelete(User $actor, array $ids, string $reason): int
    {
        $count = 0;
        $businesses = Business::whereIn('id', $ids)->get();

        foreach ($businesses as $business) {
            $this->delete($actor, $business, $reason);
            $count++;
        }

        return $count;
    }

    public function notify(
        User $actor,
        array $businessIds,
        string $intention,
        string $message,
        ?string $subject = null,
        bool $markAsNotified = false,
        string $channel = 'both',
    ): int {
        $businesses = Business::with('owner')->whereIn('id', $businessIds)->get();
        $sent = 0;

        foreach ($businesses as $business) {
            $this->notifications->notifyBusinessMessage($business, $intention, $message, $subject, $channel);
            $this->audit->log($actor, 'business.notified', 'business', $business->id, null, [
                'intention' => $intention,
                'subject' => $subject,
                'channel' => $channel,
                'mark_as_notified' => $markAsNotified,
            ]);

            if ($markAsNotified) {
                $previous = $business->status;
                $business->update([
                    'status' => 'notified',
                    'status_changed_at' => now(),
                ]);
                $this->audit->log($actor, 'business.marked_notified', 'business', $business->id, null, [
                    'from' => $previous,
                    'intention' => $intention,
                ]);
            }

            $sent++;
        }

        if ($businesses->isNotEmpty()) {
            $this->dispatches->recordMessage(
                $actor,
                'business',
                $intention,
                $message,
                $channel,
                $businesses->map(function (Business $business) {
                    $inAppRecipients = $this->notifications->businessRecipientUsers($business)->count();

                    return $this->dispatches->recipientFromBusiness($business, $inAppRecipients);
                })->all(),
                $subject,
                $markAsNotified,
            );
        }

        return $sent;
    }

    private function auditActionForStatus(string $status): string
    {
        return match ($status) {
            'suspended' => 'business.suspended',
            'restricted' => 'business.restricted',
            'warning' => 'business.warned',
            'notified' => 'business.marked_notified',
            'active' => 'business.reactivated',
            default => 'business.status_changed',
        };
    }

    private function staffCountSubquery(): \Closure
    {
        return function ($sub): void {
            $sub->from('users')
                ->selectRaw('COUNT(DISTINCT users.id)')
                ->whereNull('users.deleted_at')
                ->where(function ($q): void {
                    $q->whereColumn('users.business_id', 'businesses.id')
                        ->orWhereColumn('users.id', 'businesses.owner_id')
                        ->orWhere(function ($q2): void {
                            $q2->whereNotNull('businesses.email')
                                ->whereColumn('users.email', 'businesses.email');
                        });
                });
        };
    }

    private function resolveStaffCount(Business $business): int
    {
        $fromQuery = $business->getAttributes()['staff_count'] ?? null;
        if ($fromQuery !== null) {
            return (int) $fromQuery;
        }

        return (int) User::query()
            ->whereNull('deleted_at')
            ->where(function ($q) use ($business): void {
                $q->where('business_id', $business->id);
                if ($business->owner_id) {
                    $q->orWhere('id', $business->owner_id);
                }
                if ($business->email) {
                    $q->orWhere('email', $business->email);
                }
            })
            ->distinct()
            ->count('id');
    }

    private function hydrateOwners(Collection $businesses): void
    {
        if ($businesses->isEmpty()) {
            return;
        }

        $ownerIds = $businesses->pluck('owner_id')->filter()->unique()->values();
        $emails = $businesses->pluck('email')->filter()->unique()->values();
        $businessIds = $businesses->pluck('id');

        $users = User::query()
            ->whereNull('deleted_at')
            ->where(function ($q) use ($ownerIds, $emails, $businessIds): void {
                if ($ownerIds->isNotEmpty()) {
                    $q->orWhereIn('id', $ownerIds);
                }
                if ($emails->isNotEmpty()) {
                    $q->orWhereIn('email', $emails);
                }
                $q->orWhereIn('business_id', $businessIds);
            })
            ->get(['id', 'name', 'email', 'phone', 'business_id']);

        foreach ($businesses as $business) {
            $owner = $this->pickOwnerUser($business, $users);
            if ($owner) {
                $business->setRelation('owner', $owner);
            }
        }
    }

    private function resolveOwner(Business $business): ?User
    {
        if ($business->relationLoaded('owner') && $business->owner) {
            return $business->owner;
        }

        return $this->pickOwnerUser($business, $this->candidateOwnerUsers($business));
    }

    /** @return Collection<int, User> */
    private function candidateOwnerUsers(Business $business): Collection
    {
        return User::query()
            ->whereNull('deleted_at')
            ->where(function ($q) use ($business): void {
                if ($business->owner_id) {
                    $q->orWhere('id', $business->owner_id);
                }
                if ($business->email) {
                    $q->orWhere('email', $business->email);
                }
                $q->orWhere('business_id', $business->id);
            })
            ->get(['id', 'name', 'email', 'phone', 'business_id']);
    }

    /** @param Collection<int, User> $users */
    private function pickOwnerUser(Business $business, Collection $users): ?User
    {
        if ($business->owner_id) {
            $byId = $users->firstWhere('id', $business->owner_id);
            if ($byId) {
                return $byId;
            }
        }

        if ($business->email) {
            $byEmail = $users->first(
                fn (User $user): bool => strcasecmp((string) $user->email, (string) $business->email) === 0,
            );
            if ($byEmail) {
                return $byEmail;
            }
        }

        return $users->where('business_id', $business->id)->sortBy('id')->first();
    }

    private function linkedUsersSubquery(): \Closure
    {
        return function ($userQuery): void {
            $userQuery->from('users')
                ->select('users.id')
                ->whereNull('users.deleted_at')
                ->where(function ($q): void {
                    $q->whereColumn('users.business_id', 'businesses.id')
                        ->orWhereColumn('users.id', 'businesses.owner_id')
                        ->orWhere(function ($q2): void {
                            $q2->whereNotNull('businesses.email')
                                ->whereColumn('users.email', 'businesses.email');
                        });
                });
        };
    }

    private function businessMetricsQuery(?Carbon $windowStart = null)
    {
        $windowStart ??= now()->subDays($this->activityWindowDays());
        $today = now()->toDateString();
        $sevenDaysAgo = now()->subDays(6)->startOfDay();

        return Business::query()
            ->with(['owner:id,name,email,phone', 'subscription.plan:id,name'])
            ->select('businesses.*')
            ->selectSub($this->staffCountSubquery(), 'staff_count')
            ->selectSub($this->grossSalesSubquery($today, true), 'gross_sales_today')
            ->selectSub($this->grossSalesSubquery($sevenDaysAgo), 'gross_sales_7d')
            ->selectSub($this->grossSalesSubquery($windowStart), 'gross_sales_30d')
            ->selectSub($this->grossSalesSubquery(), 'gross_sales_all_time')
            ->selectSub($this->attributedSalesCountSubquery($windowStart), 'transactions_30d')
            ->selectSub($this->attributedLastSaleSubquery(), 'last_sale_at')
            ->selectSub($this->linkedUsersLastLoginSubquery(), 'last_user_login_at')
            ->selectSub($this->totalStockSubquery(), 'total_stock');
    }

    private function applyAttributedSalesConstraint($query): void
    {
        $query->whereNull('sales.deleted_at')
            ->where(function ($q): void {
                $q->whereColumn('sales.business_id', 'businesses.id')
                    ->orWhereIn('sales.user_id', $this->linkedUsersSubquery());
            });
    }

    private function grossSalesSubquery(Carbon|string|null $since = null, bool $todayOnly = false): \Closure
    {
        return function ($sub) use ($since, $todayOnly): void {
            $sub->from('sales')
                ->selectRaw('COALESCE(SUM(sales.total_amount), 0)');
            $this->applyAttributedSalesConstraint($sub);

            if ($todayOnly && is_string($since)) {
                $sub->whereDate('sales.sale_date', $since);
            } elseif ($since instanceof Carbon) {
                $sub->where('sales.sale_date', '>=', $since);
            }
        };
    }

    private function attributedSalesCountSubquery(Carbon $since): \Closure
    {
        return function ($sub) use ($since): void {
            $sub->from('sales')
                ->selectRaw('COUNT(*)');
            $this->applyAttributedSalesConstraint($sub);
            $sub->where('sales.sale_date', '>=', $since);
        };
    }

    private function totalStockSubquery(): \Closure
    {
        return function ($sub): void {
            $sub->from('products')
                ->selectRaw('COUNT(*)')
                ->whereColumn('products.business_id', 'businesses.id')
                ->whereNull('products.deleted_at');
        };
    }

    private function attributedLastSaleSubquery(): \Closure
    {
        return function ($sub): void {
            $sub->from('sales')
                ->selectRaw('MAX(sales.sale_date)');
            $this->applyAttributedSalesConstraint($sub);
        };
    }

    private function linkedUsersLastLoginSubquery(): \Closure
    {
        return function ($sub): void {
            $sub->from('users')
                ->selectRaw('MAX(users.last_login_at)')
                ->whereNull('users.deleted_at')
                ->where(function ($q): void {
                    $q->whereColumn('users.business_id', 'businesses.id')
                        ->orWhereColumn('users.id', 'businesses.owner_id')
                        ->orWhere(function ($q2): void {
                            $q2->whereNotNull('businesses.email')
                                ->whereColumn('users.email', 'businesses.email');
                        });
                });
        };
    }

    private function businessHasAttributedSales(Business $business): bool
    {
        return Sale::query()
            ->whereNull('deleted_at')
            ->where(function ($q) use ($business): void {
                $q->where('business_id', $business->id)
                    ->orWhereIn('user_id', $this->linkedUserIdsForBusiness($business));
            })
            ->exists();
    }

    /** @return list<int> */
    private function linkedUserIdsForBusiness(Business $business): array
    {
        return User::query()
            ->whereNull('deleted_at')
            ->where(function ($q) use ($business): void {
                $q->where('business_id', $business->id);
                if ($business->owner_id) {
                    $q->orWhere('id', $business->owner_id);
                }
                if ($business->email) {
                    $q->orWhere('email', $business->email);
                }
            })
            ->pluck('id')
            ->all();
    }

    /** @return list<array<string, mixed>> */
    private function buildGrossTiers(Collection $currencyRows, float $min, float $max, int $tierCount, string $currency): array
    {
        $tierLabels = [
            1 => 'Tier 1 · Lowest earners',
            2 => 'Tier 2 · Low',
            3 => 'Tier 3 · Mid',
            4 => 'Tier 4 · High',
            5 => 'Tier 5 · Top earners',
        ];

        $step = $max > $min ? ($max - $min) / $tierCount : 0;
        $buckets = [];

        for ($i = 1; $i <= $tierCount; $i++) {
            $tierMin = $min + ($step * ($i - 1));
            $tierMax = $i === $tierCount ? $max : $min + ($step * $i);
            $buckets[$i] = [
                'tier' => $i,
                'label' => $tierLabels[$i] ?? "Tier {$i}",
                'min_gross' => $this->formatGross($tierMin),
                'max_gross' => $this->formatGross($tierMax),
                'business_count' => 0,
                'total_gross_sales_30d' => '0.00',
            ];
        }

        foreach ($currencyRows as $entry) {
            $amount = (float) $entry['gross_sales_30d'];
            $tier = $this->tierForAmount($amount, $min, $max, $tierCount);
            $buckets[$tier]['business_count']++;
            $buckets[$tier]['total_gross_sales_30d'] = $this->formatGross(
                (float) $buckets[$tier]['total_gross_sales_30d'] + $amount
            );
        }

        return array_values($buckets);
    }

    private function tierForAmount(float $amount, float $min, float $max, int $tierCount): int
    {
        if ($max <= $min) {
            return $amount > 0 ? $tierCount : 1;
        }

        $step = ($max - $min) / $tierCount;
        $tier = (int) floor(($amount - $min) / $step) + 1;

        return min($tierCount, max(1, $tier));
    }

    private function formatGross(float $amount): string
    {
        return number_format(max(0, $amount), 2, '.', '');
    }
}
