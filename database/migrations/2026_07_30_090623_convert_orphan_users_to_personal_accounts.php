<?php

use App\Models\Business;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Find users who registered without a business (no business_id).
        // These are orphan accounts created before the personal account
        // system existed — the default was 'business' but they never
        // completed business registration via POST /api/v1/businesses/register.
        $orphans = User::query()
            ->whereNull('business_id')
            ->get();

        if ($orphans->isEmpty()) {
            return;
        }

        $plan = Plan::where('slug', 'personal')->first();

        DB::transaction(function () use ($orphans, $plan) {
            foreach ($orphans as $user) {
                // Create a minimal personal business record
                $business = Business::create([
                    'owner_id' => $user->id,
                    'name' => ($user->name ?? 'Personal') . "'s Workspace",
                    'slug' => 'personal-workspace-' . $user->id,
                    'currency' => 'USD',
                    'status' => 'active',
                    'business_type' => 'personal',
                ]);

                // Assign the Personal plan with trial
                if ($plan) {
                    Subscription::create([
                        'business_id' => $business->id,
                        'plan_id' => $plan->id,
                        'price_monthly_usd' => $plan->price_monthly_usd ?? 10.00,
                        'price_yearly_usd' => $plan->price_yearly_usd ?? 100.00,
                        'onboarding_fee_usd' => $plan->onboarding_fee_usd ?? 0,
                        'billing_cycle' => 'monthly',
                        'status' => 'trial',
                        'starts_at' => now(),
                        'trial_ends_at' => now()->addDays($plan->trial_days ?? 30),
                        'trial_used' => false,
                        'onboarding_fee_paid' => true,
                        'next_billing_date' => now()->addMonth(),
                    ]);
                }

                // Update the user — link to business and mark as personal
                $user->business_id = $business->id;
                $user->account_type = 'personal';
                $user->modules = [];
                $user->role_id = null;
                $user->save();
            }
        });
    }

    public function down(): void
    {
        $personalBusinesses = Business::query()
            ->where('business_type', 'personal')
            ->whereIn('owner_id', function ($q) {
                $q->select('id')
                    ->from('users')
                    ->where('account_type', 'personal');
            })
            ->get();

        foreach ($personalBusinesses as $business) {
            $user = User::find($business->owner_id);
            if ($user) {
                $user->business_id = null;
                $user->account_type = 'business';
                $user->save();
            }

            Subscription::where('business_id', $business->id)->delete();
            $business->delete();
        }
    }
};
