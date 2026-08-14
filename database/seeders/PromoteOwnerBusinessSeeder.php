<?php

namespace Database\Seeders;

use App\Enums\Billing\SubscriptionStatus;
use App\Models\Business;
use App\Models\Plan;
use App\Models\Role;
use App\Models\Subscription;
use App\Models\User;
use App\Services\ModuleAccessService;
use App\Services\Platform\PlatformAdminService;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Ensures the Custospark owner business exists under oscar@custospark.com and is
 * on the Enterprise plan with billing through December 2030.
 *
 * Replaces the legacy info@custospark.com owner account (mirrors how we seed
 * test businesses): if the target email already exists it is updated in place
 * (update-or-create), if only the legacy email exists it is renamed, and if
 * neither exists the account + business are created. Idempotent - safe to re-run.
 *
 * Platform admin access is granted through PlatformAdminService::assignIfEligible()
 * only when the owner email is listed in config('platform.admin_emails')
 * (PLATFORM_ADMIN_EMAILS), so the frontend surfaces the admin platform module.
 *
 * Only runs in production and local development (not staging/other envs).
 */
class PromoteOwnerBusinessSeeder extends Seeder
{
    private const LEGACY_EMAIL = 'info@custospark.com';

    private const OWNER_EMAIL = 'oscar@custospark.com';

    private const OWNER_NAME = 'Oscar Opiyo';

    private const OWNER_PASSWORD = 'Password123';

    private const NEXT_BILLING_DATE = '2030-12-31';

    public function run(): void
    {
        if (!in_array(app()->environment(), ['production', 'local'], true)) {
            $this->command?->warn('PromoteOwnerBusinessSeeder skipped - it only runs in production or local development.');

            return;
        }

        $plan = Plan::where('slug', 'enterprise')->first();
        if (!$plan) {
            $this->command?->error('Enterprise plan not found. Run PlanSeeder first.');

            return;
        }

        $owner = $this->resolveOwner();

        $business = $this->resolveBusiness($owner);

        $this->ensureOwnerLinked($owner, $business);

        $this->ensureSubscription($business, $plan);

        $this->command?->info(
            "Owner business ready: {$owner->email} (Enterprise, next billing ".self::NEXT_BILLING_DATE.")"
        );
    }

    /** Find the target account (update-or-create); rename the legacy one if needed. */
    private function resolveOwner(): User
    {
        $role = Role::where('slug', 'owner')->whereNull('business_id')->first();
        $modules = [
            ...ModuleAccessService::BUSINESS_MODULES,
            ModuleAccessService::ESTIMATES_FULL_SLUG,
            ModuleAccessService::HR_FULL_SLUG,
        ];

        $owner = User::where('email', self::OWNER_EMAIL)->first();

        if ($owner) {
            $owner->update([
                'name' => self::OWNER_NAME,
                'is_active' => true,
                'account_type' => 'business',
                'role_id' => $role?->id,
                'modules' => $modules,
            ]);

            return $owner;
        }

        $legacy = User::where('email', self::LEGACY_EMAIL)->first();
        if ($legacy) {
            $legacy->email = self::OWNER_EMAIL;
            $legacy->update([
                'name' => self::OWNER_NAME,
                'is_active' => true,
                'account_type' => 'business',
                'role_id' => $role?->id,
                'modules' => $modules,
            ]);

            return $legacy;
        }

        return User::create([
            'name' => self::OWNER_NAME,
            'email' => self::OWNER_EMAIL,
            'password' => Hash::make(self::OWNER_PASSWORD),
            'is_active' => true,
            'account_type' => 'business',
            'role_id' => $role?->id,
            'modules' => $modules,
        ]);
    }

    /** Use the owner's business, else adopt the legacy-email business, else create one. */
    private function resolveBusiness(User $owner): Business
    {
        $business = $owner->business;
        if ($business) {
            return $business;
        }

        $business = $owner->ownedBusiness()->first();
        if ($business) {
            return $business;
        }

        $business = Business::where('email', self::LEGACY_EMAIL)->first();
        if ($business) {
            return $business;
        }

        $business = Business::create([
            'owner_id' => $owner->id,
            'name' => 'Custospark',
            'slug' => 'custospark',
            'email' => self::OWNER_EMAIL,
            'currency' => 'UGX',
            'status' => 'active',
        ]);

        app(\App\Services\Documents\DocumentCabinetService::class)->seedDefaultCabinets(
            (int) $business->id,
            (int) $owner->id,
            $business->business_type
        );

        return $business;
    }

    private function ensureOwnerLinked(User $owner, Business $business): void
    {
        if ($business->email !== self::OWNER_EMAIL) {
            $business->email = self::OWNER_EMAIL;
            $business->save();
        }

        if ((int) $owner->business_id !== (int) $business->id) {
            $owner->business_id = $business->id;
            $owner->account_type = 'business';
            $owner->save();
        }

        // Grant admin access only when the owner email is configured as a platform admin.
        app(PlatformAdminService::class)->assignIfEligible($owner);
    }

    private function ensureSubscription(Business $business, Plan $plan): void
    {
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
                'next_billing_date' => Carbon::parse(self::NEXT_BILLING_DATE),
                'trial_used' => true,
                'onboarding_fee_paid' => true,
                'grace_used' => false,
            ]
        );
    }
}
