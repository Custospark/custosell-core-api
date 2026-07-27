<?php

namespace App\Console\Commands;

use App\Enums\Billing\DiscountType;
use App\Enums\Billing\ReferralCodeOwnerType;
use App\Enums\Billing\RewardType;
use App\Models\BillingCredit;
use App\Models\Business;
use App\Models\CreditApplication;
use App\Models\Plan;
use App\Models\ReferralCode;
use App\Models\Subscription;
use App\Models\User;
use App\Services\CreditService;
use App\Services\Payment\GatewayService;
use App\Services\ReferralService;
use App\Services\SubscriptionService;
use Illuminate\Console\Command;

class SimulateCreditDeduction extends Command
{
    protected $signature = 'simulate:credit-deduction';

    protected $description = 'Simulate the referral → credit → payment deduction flow end-to-end';

    public function handle(
        SubscriptionService $subscriptionService,
        ReferralService $referralService,
        CreditService $creditService,
        GatewayService $gatewayService,
    ): int {
        $this->components->info('Starting credit deduction simulation...');

        // ── Step 1: Seed data ───────────────────────────────────────────
        $plan = Plan::where('slug', 'essential')->first();
        if (!$plan) {
            $this->components->error('Essential plan not found. Run migrations and seeders first.');
            return Command::FAILURE;
        }

        $this->components->twoColumnDetail('Plan', "{$plan->name} — \${$plan->price_monthly_usd}/mo, \$".$plan->onboarding_fee_usd.' onboarding');

        // ── Step 2: Create Alice (referrer) ─────────────────────────────
        $alice = User::factory()->create([
            'name' => 'Alice Referrer',
            'email' => 'alice@example.com',
            'is_active' => true,
        ]);
        $aliceBusiness = Business::factory()->create([
            'owner_id' => $alice->id,
            'name' => "Alice's Shop",
            'currency' => 'USD',
        ]);
        $this->components->twoColumnDetail('Alice (referrer)', "#{$alice->id} — {$alice->name} / {$aliceBusiness->name}");

        // Alice subscribes
        $aliceSubscription = $subscriptionService->subscribe(
            $aliceBusiness->id,
            $plan->id,
            'monthly',
        );
        $this->components->twoColumnDetail('Alice subscription', "#{$aliceSubscription->id} — {$aliceSubscription->status->value}");

        // ── Step 3: Create Alice's referral code ────────────────────────
        $referralCode = ReferralCode::create([
            'owner_type' => ReferralCodeOwnerType::BUSINESS,
            'owner_business_id' => $aliceBusiness->id,
            'code' => 'ALICE20',
            'discount_type' => DiscountType::PERCENTAGE,
            'discount_value' => 10,
            'reward_type' => RewardType::PERCENTAGE,
            'reward_value' => 20,
            'is_active' => true,
        ]);
        $this->components->twoColumnDetail('Referral code', "{$referralCode->code} — 20% reward of \${$plan->price_monthly_usd} = \$4.00");

        // ── Step 4: Create Bob (referred) ───────────────────────────────
        $bob = User::factory()->create([
            'name' => 'Bob Referred',
            'email' => 'bob@example.com',
            'is_active' => true,
        ]);
        $bobBusiness = Business::factory()->create([
            'owner_id' => $bob->id,
            'name' => "Bob's Store",
            'currency' => 'USD',
        ]);
        $this->components->twoColumnDetail('Bob (referred)', "#{$bob->id} — {$bob->name} / {$bobBusiness->name}");

        // Bob subscribes
        $bobSubscription = $subscriptionService->subscribe(
            $bobBusiness->id,
            $plan->id,
            'monthly',
        );
        $this->components->twoColumnDetail('Bob subscription', "#{$bobSubscription->id} — {$bobSubscription->status->value}");

        // ── Step 5: Apply Alice's referral code to Bob's subscription ───
        $this->components->task('Applying referral code ALICE20 to Bob', function () use ($referralService, $referralCode, $bobSubscription, $bobBusiness) {
            $referralService->processReferral(
                $referralCode->code,
                $bobSubscription->id,
                $bobBusiness->id,
            );
        });

        // Check referral is PENDING
        $referral = $referralCode->referrals()->first();
        $this->components->twoColumnDetail('Referral status', $referral->status->value);

        // ── Step 6: Activate Bob's subscription → triggers referral → credit ───
        $this->components->task('Activating Bob subscription (triggers referral markActive)', function () use ($subscriptionService, $bobSubscription) {
            $subscriptionService->activateSubscription($bobSubscription);
        });

        $bobSubscription->refresh();
        $referral->refresh();
        $this->components->twoColumnDetail('Bob subscription status', $bobSubscription->status->value);
        $this->components->twoColumnDetail('Referral status', $referral->status->value);
        $this->components->twoColumnDetail('Reward amount', '$' . number_format((float) $referral->reward_amount, 2));

        // ── Step 7: Verify credit was created for Alice's business ───
        $aliceCredit = BillingCredit::where('owner_type', 'business')
            ->where('owner_id', $aliceBusiness->id)
            ->first();

        if (!$aliceCredit) {
            $this->components->error('No billing credit found for Alice!');
            return Command::FAILURE;
        }

        $this->components->twoColumnDetail('Billing credit', "#{$aliceCredit->id} — \$" . number_format($aliceCredit->amount_remaining, 2) . ' available');

        $creditBalance = $creditService->getBusinessCredit($aliceBusiness->id);
        $this->components->twoColumnDetail('Credit balance', '$' . number_format($creditBalance, 2));
        $this->assert($creditBalance === 4.0, 'Credit balance should be $4.00');

        // ── Step 8: Alice initiates onboarding payment ──────────────────
        $onboardingFee = (float) $plan->onboarding_fee_usd;
        $this->components->twoColumnDetail('Onboarding fee', '$' . number_format($onboardingFee, 2));
        $this->components->twoColumnDetail('Expected after credit', '$' . number_format($onboardingFee - 4.0, 2));

        // We call gatewayService->initiatePayment but it will try to reach PesaPal.
        // Instead, we bypass by calling the internals directly so we can verify credit deduction.
        // We'll use CreditService::applyToRenewal directly to show the deduction works.

        $this->components->task('Verifying CreditService::applyToRenewal', function () use ($creditService, $aliceSubscription, $onboardingFee) {
            $result = $creditService->applyToRenewal($aliceSubscription, $onboardingFee);

            $this->assert($result['credit_used'] === 4.0, 'Should consume $4.00 credit');
            $this->assert($result['remaining'] === 36.0, 'Should have $36.00 remaining');
            $this->assert(count($result['applications']) === 1, 'Should create 1 CreditApplication');

            $app = $result['applications'][0];
            $this->assert((float) $app->amount_applied === 4.0, 'Application amount should be $4.00');
        });

        // ── Step 9: Reverse the credit (clean up for re-testing) ────────
        $aliceCredit->refresh();
        $applications = CreditApplication::where('credit_id', $aliceCredit->id)->get();
        $this->components->task('Reversing credit applications (cleanup)', function () use ($creditService, $applications) {
            $creditService->reverseApplications($applications->all());
        });

        $aliceCredit->refresh();
        $this->components->twoColumnDetail('After reversal — credit status', $aliceCredit->status);
        $this->components->twoColumnDetail('After reversal — credit amount_used', '$' . number_format((float) $aliceCredit->amount_used, 2));
        $this->assert($aliceCredit->status === 'available', 'Credit should be available again');
        $this->assert((float) $aliceCredit->amount_used === 0.0, 'Credit amount_used should be 0');

        // ── Summary ─────────────────────────────────────────────────────
        $this->newLine();
        $this->components->info('=== Simulation Complete ===');
        $this->components->bulletList([
            "Alice created referral code {$referralCode->code} (20% reward)",
            'Bob subscribed and applied Alice\'s code → referral PENDING',
            'Bob paid onboarding → subscription activated → referral ACTIVE',
            "\$" . number_format((float) $referral->reward_amount, 2) . " credit created for Alice's business",
            "CreditService::applyToRenewal reduces \${$onboardingFee} → \$" . number_format($onboardingFee - 4.0, 2) . ' after credit',
            'reverseApplications restores credit to available state',
        ]);

        return Command::SUCCESS;
    }

    private function assert(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new \RuntimeException("Assertion failed: {$message}");
        }
        $this->components->twoColumnDetail('  ✓ Assert', $message);
    }
}
