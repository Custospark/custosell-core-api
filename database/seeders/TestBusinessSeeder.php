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
    /** @var list<array{name: string, email: string}> */
    private const ACCOUNTS = [
        ['name' => 'business1', 'email' => 'tester1@custosell.com'],
        ['name' => 'business2', 'email' => 'tester2@custosell.com'],
        ['name' => 'business3', 'email' => 'tester3@custosell.com'],
        ['name' => 'business4', 'email' => 'tester4@custosell.com'],
    ];

    public function run(): void
    {
        if (app()->environment() !== 'staging') {
            $this->command?->warn('TestBusinessSeeder skipped — it only runs in the staging environment.');

            return;
        }

        $password = config('app.test_business_password', 'password');

        $plan = Plan::where('slug', 'enterprise')->first();
        if (!$plan) {
            $this->command?->error('Enterprise plan not found. Run PlanSeeder first.');
            return;
        }

        $role = Role::where('slug', 'owner')->whereNull('business_id')->first();

        $this->removeLegacyTestAccount();

        foreach (self::ACCOUNTS as $account) {
            $this->seedBusiness($account['name'], $account['email'], $password, $plan, $role);
        }

        $this->command?->info(
            'Test businesses ready: '
            .implode(', ', array_column(self::ACCOUNTS, 'email'))
            ." / password='{$password}' (all Enterprise, active yearly, platform admin)"
        );
    }

    private function removeLegacyTestAccount(): void
    {
        $legacyEmail = config('app.test_business_email');

        $user = User::query()->where('email', $legacyEmail)->first();
        if (!$user) {
            return;
        }

        $user->ownedBusiness()->delete();
        $user->delete();
    }

    private function seedBusiness(
        string $name,
        string $email,
        string $password,
        Plan $plan,
        ?Role $role,
    ): void {
        $owner = User::updateOrCreate(
            ['email' => $email],
            [
                'name' => ucfirst($name).' Owner',
                'password' => Hash::make($password),
                'is_active' => true,
                'account_type' => 'business',
                'role_id' => $role?->id,
            ]
        );

        if (!$owner->business_id) {
            $business = Business::create([
                'owner_id' => $owner->id,
                'name' => $name,
                'slug' => $name,
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
                'price_monthly_usd' => $plan->price_monthly_usd,
                'price_yearly_usd' => $plan->price_yearly_usd,
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

        if (!$owner->hasRole('platform-admin')) {
            $owner->assignRole('platform-admin');
        }
    }
}
