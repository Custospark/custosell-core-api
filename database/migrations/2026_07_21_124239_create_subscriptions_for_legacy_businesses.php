<?php

use App\Enums\Billing\SubscriptionStatus;
use App\Models\Plan;
use App\Models\Subscription;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;

return new class extends Migration
{
    public function up(): void
    {
        $plan = Plan::where('slug', 'essential')->first()
            ?? Plan::orderBy('sort_order')->first();

        if (! $plan) {
            return;
        }

        $businessIds = DB::table('businesses')
            ->whereNull('deleted_at')
            ->whereNotIn('id', function ($q) {
                $q->select('business_id')->from('subscriptions');
            })
            ->pluck('id');

        $now = Carbon::now();
        $trialEnd = $now->copy()->addDays(30);
        $nextBilling = $now->copy()->addMonth();
        $status = SubscriptionStatus::TRIAL->value;
        $rows = [];

        foreach ($businessIds as $businessId) {
            $rows[] = [
                'business_id' => $businessId,
                'plan_id' => $plan->id,
                'status' => $status,
                'billing_cycle' => 'monthly',
                'starts_at' => $now,
                'trial_ends_at' => $trialEnd,
                'next_billing_date' => $nextBilling,
                'trial_used' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        if (! empty($rows)) {
            DB::table('subscriptions')->insert($rows);
        }
    }

    public function down(): void
    {
        $plan = Plan::where('slug', 'essential')->first()
            ?? Plan::orderBy('sort_order')->first();

        if (! $plan) {
            return;
        }

        $businessIds = DB::table('businesses')
            ->whereNull('deleted_at')
            ->pluck('id');

        DB::table('subscriptions')
            ->where('plan_id', $plan->id)
            ->whereIn('business_id', $businessIds)
            ->where('trial_used', true)
            ->delete();
    }
};
