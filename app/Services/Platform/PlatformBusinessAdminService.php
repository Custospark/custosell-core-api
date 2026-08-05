<?php

namespace App\Services\Platform;

use App\Models\Business;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Contracts\SubscriptionServiceInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Lifecycle actions for platform businesses: status, deletion, data resets, notifications, subscriptions.
 */
class PlatformBusinessAdminService
{
    public function __construct(
        protected PlatformNotificationService $notifications,
        protected PlatformAuditService $audit,
        protected PlatformNotificationDispatchService $dispatches,
        protected SubscriptionServiceInterface $subscriptionService,
    ) {}

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
            $counts = $this->purgeBusinessData($business->id);

            $salesDeleted = $counts['sales'] ?? 0;

            $this->audit->log($actor, 'business.deleted', 'business', $business->id, $reason, [
                'name' => $business->name,
                'status' => $business->status,
                'sales_deleted' => $salesDeleted,
                'purge_counts' => $counts,
            ]);

            $business->forceDelete();
        });
    }

    public function resetBusinessData(User $actor, Business $business): array
    {
        $businessId = $business->id;
        $counts = [];

        DB::transaction(function () use ($businessId, $actor, &$counts): void {
            $counts = $this->purgeBusinessData($businessId);

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

    /**
     * Delete all rows owned by a business across every dependent table, in FK
     * order, so a hard `forceDelete` of the business cannot trip a foreign-key
     * constraint (e.g. accounting_periods, journal_entries).
     *
     * @return array<string, int>
     */
    private function purgeBusinessData(int $businessId): array
    {
        $counts = [];

        DB::transaction(function () use ($businessId, &$counts): void {
            // ── 0. Sales you then journal entries reference ──
            // (sales deleted here so accounting/journal entries below are safe)
            $counts['sales'] = DB::table('sales')->where('business_id', $businessId)->delete();

            // ── 1. Invoices (before payments since payments reference invoices) ─
            $counts['invoice_items'] = DB::table('invoice_items')
                ->whereIn('invoice_id', fn($q) => $q->select('id')->from('invoices')->where('business_id', $businessId))
                ->delete();
            $counts['invoices'] = DB::table('invoices')
                ->where('business_id', $businessId)->delete();

            // ── 2. Payments (polymorphic — clear by business_id) ──
            $counts['payments'] = DB::table('payments')
                ->where('business_id', $businessId)->delete();

            // ── 3. Orders ─────────────────────────────────────────
            // order_items cascade from orders
            $counts['orders'] = DB::table('orders')
                ->where('business_id', $businessId)->delete();

            // ── 4. Sales (soft-deleted rows too) ──────────────────
            // sale_items cascade from sales
            $counts['shifts'] = DB::table('shifts')
                ->where('business_id', $businessId)->delete();

            // ── 5. Purchase orders (buyer + seller) ───────────────
            $counts['purchase_order_items'] = DB::table('purchase_order_items')
                ->whereIn('purchase_order_id', fn($q) => $q->select('id')->from('purchase_orders')
                    ->where('buyer_business_id', $businessId)
                    ->orWhere('seller_business_id', $businessId))
                ->delete();
            $counts['purchase_orders'] = DB::table('purchase_orders')
                ->where('buyer_business_id', $businessId)
                ->orWhere('seller_business_id', $businessId)
                ->delete();

            // ── 6. Stock movements ──────────────────────────────
            $counts['stock_movements'] = DB::table('stock_movements')
                ->where('business_id', $businessId)->delete();

            // ── 7. Products (cascades to wishlists, ratings) ────
            $counts['products'] = DB::table('products')
                ->where('business_id', $businessId)->delete();

            // ── 8. Categories ────────────────────────────────────
            $counts['categories'] = DB::table('categories')
                ->where('business_id', $businessId)->delete();

            // ── 9. Customers ────────────────────────────────────
            $counts['customers'] = DB::table('customers')
                ->where('business_id', $businessId)->delete();

            // ── 10. Expenses ────────────────────────────────────
            $counts['expenses'] = DB::table('expenses')
                ->where('business_id', $businessId)->delete();
            $counts['expense_categories'] = DB::table('expense_categories')
                ->where('business_id', $businessId)->delete();

            // ── 11. Accounting ────────────────────────────────────
            // depreciation_entries use RESTRICT, delete child rows first
            $counts['depreciation_entries'] = DB::table('depreciation_entries')
                ->whereIn('asset_id', fn($q) => $q->select('id')->from('fixed_assets')->where('business_id', $businessId))
                ->delete();
            $counts['fixed_asset_assignments'] = DB::table('fixed_asset_assignments')
                ->whereIn('asset_id', fn($q) => $q->select('id')->from('fixed_assets')->where('business_id', $businessId))
                ->delete();

            $counts['journal_entry_lines'] = DB::table('journal_entry_lines')
                ->whereIn('entry_id', fn($q) => $q->select('id')->from('journal_entries')->where('business_id', $businessId))
                ->delete();

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

    /**
     * Activate a subscription for a business exactly like normal onboarding:
     * subscribe (trial if the plan has one, otherwise past_due), then run the
     * onboarding activation so the subscription becomes effective and paid.
     */
    public function activateSubscription(User $actor, Business $business, int $planId, string $billingCycle = 'monthly'): Subscription
    {
        if ($business->subscription) {
            throw ValidationException::withMessages([
                'subscription' => 'Business already has a subscription.',
            ]);
        }

        $subscription = $this->subscriptionService->subscribe($business->id, $planId, $billingCycle);
        $activated = $this->subscriptionService->activateAfterOnboarding($subscription);

        $activated->update(['approved_by_user_id' => $actor->id]);

        $this->audit->log($actor, 'business.subscription.activated', 'business', $business->id, null, [
            'subscription_id' => $activated->id,
            'plan_id' => $planId,
            'billing_cycle' => $billingCycle,
            'status' => $activated->status?->value ?? $activated->status,
        ]);

        return $activated->fresh(['plan']);
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
}
