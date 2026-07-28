<?php

namespace Database\Seeders;

use App\Enums\Billing\SubscriptionStatus;
use App\Models\Business;
use App\Models\Plan;
use App\Models\Role;
use App\Models\Subscription;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class TestBusinessSeeder extends Seeder
{
    public function run(): void
    {
        $email = config('app.test_business_email');
        $password = config('app.test_business_password');

        $plan = Plan::where('slug', 'enterprise')->first();
        if (!$plan) {
            $this->command?->error('Enterprise plan not found. Run PlanSeeder first.');
            return;
        }

        $role = Role::where('slug', 'owner')->whereNull('business_id')->first();

        $owner = User::updateOrCreate(
            ['email' => $email],
            [
                'name' => 'Test Business Owner',
                'password' => Hash::make($password),
                'is_active' => true,
                'role_id' => $role?->id,
            ]
        );

        if (!$owner->business_id) {
            $business = Business::create([
                'owner_id' => $owner->id,
                'name' => 'Test Business',
                'slug' => 'test-business-' . substr(md5($email), 0, 8),
                'email' => $email,
                'currency' => 'UGX',
                'status' => 'active',
            ]);
            $owner->update(['business_id' => $business->id]);
        }

        $business = $owner->business;

        $now = Carbon::now();

        Subscription::updateOrCreate(
            ['business_id' => $business->id],
            [
                'plan_id' => $plan->id,
                'price_yearly' => $plan->price_yearly,
                'price_yearly_usd' => $plan->price_yearly_usd,
                'price_monthly' => $plan->price_monthly,
                'price_monthly_usd' => $plan->price_monthly_usd,
                'onboarding_fee_ugx' => $plan->onboarding_fee_ugx,
                'onboarding_fee_usd' => $plan->onboarding_fee_usd,
                'billing_cycle' => 'yearly',
                'status' => SubscriptionStatus::ACTIVE,
                'starts_at' => $now,
                'trial_ends_at' => $now->copy()->addDays($plan->trial_days),
                'next_billing_date' => $now->copy()->addYear(),
                'trial_used' => true,
                'onboarding_fee_paid' => true,
            ]
        );

        $this->command?->info("Test business ready: email='{$email}' / password='{$password}' (Enterprise, yearly)");
    }
}
