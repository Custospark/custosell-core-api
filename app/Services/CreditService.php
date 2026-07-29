<?php

namespace App\Services;

use App\Models\BillingCredit;
use App\Models\Business;
use App\Models\CreditApplication;
use App\Models\Referral;
use App\Models\Subscription;
use App\Models\User;
use App\Repositories\Contracts\PaymentRepositoryInterface;
use App\Services\Contracts\PaymentServiceInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CreditService
{

    public function createFromReferral(Referral $referral, float $amountUsd): ?BillingCredit
    {
        $referralCode = $referral->referralCode;

        $ownerType = 'user';
        $ownerId = $referralCode->owner_user_id;

        if ($referralCode->owner_user_id) {
            $user = User::find($referralCode->owner_user_id);
            if ($user && $user->business_id) {
                $ownerType = 'business';
                $ownerId = $user->business_id;
            }
        } elseif ($referralCode->owner_business_id) {
            $ownerType = 'business';
            $ownerId = $referralCode->owner_business_id;
        }

        if (!$ownerId) {
            return null;
        }

        return BillingCredit::create([
            'owner_type' => $ownerType,
            'owner_id' => $ownerId,
            'referral_id' => $referral->id,
            'amount' => $amountUsd,
            'amount_used' => 0,
            'status' => 'available',
        ]);
    }

    public function completeRenewalWithCredit(
        Subscription $subscription,
        string $gatewayName,
        array $data,
        PaymentServiceInterface $paymentService,
        PaymentRepositoryInterface $paymentRepo,
        callable $onPaymentCompleted,
    ): array {
        $ourRef = 'CREDIT-' . now()->format('YmdHis') . '-' . $subscription->id;

        $payment = $paymentService->createPending([
            'subscription_id' => $subscription->id,
            'business_id' => $subscription->business_id,
            'amount' => 0,
            'currency' => 'USD',
            'method' => 'credit',
            'payment_type' => 'renewal',
            'gateway_name' => $gatewayName,
            'paid_at' => now(),
            'transaction_reference' => $ourRef,
            'metadata' => array_merge($data['metadata'] ?? [], [
                'credit_full_payment' => true,
                'original_amount' => $data['metadata']['original_amount'] ?? 0,
            ]),
        ]);

        $paymentRepo->update($payment, [
            'status' => 'completed',
            'approved_at' => now(),
            'gateway_response' => ['type' => 'credit', 'message' => 'Paid entirely by referral credit.'],
        ]);

        $payment->refresh();

        if (!empty($data['metadata']['credit_application_ids'])) {
            CreditApplication::whereIn('id', $data['metadata']['credit_application_ids'])
                ->update(['billing_payment_id' => $payment->id]);
        }

        $onPaymentCompleted($payment);

        Log::info('[CreditService] Renewal completed via credit (no gateway)', [
            'subscription_id' => $subscription->id,
            'payment_id' => $payment->id,
        ]);

        return [
            'success' => true,
            'payment_id' => $payment->id,
            'gateway' => 'credit',
            'type' => 'credit',
            'redirect_url' => null,
            'reference' => $ourRef,
            'message' => 'Renewal paid entirely by referral credit.',
        ];
    }

    public function getBusinessCredit(int $businessId): float
    {
        return (float) BillingCredit::where('owner_type', 'business')
            ->where('owner_id', $businessId)
            ->where('status', 'available')
            ->get()
            ->sum(fn ($c) => $c->amount_remaining);
    }

    public function getUserCredit(int $userId): float
    {
        return (float) BillingCredit::where('owner_type', 'user')
            ->where('owner_id', $userId)
            ->where('status', 'available')
            ->get()
            ->sum(fn ($c) => $c->amount_remaining);
    }

    public function applyToRenewal(Subscription $subscription, float $amountDue): array
    {
        if ($amountDue <= 0) {
            return ['credit_used' => 0, 'remaining' => $amountDue, 'applications' => []];
        }

        $businessId = $subscription->business_id;
        $credits = BillingCredit::where('owner_type', 'business')
            ->where('owner_id', $businessId)
            ->whereIn('status', ['available', 'partially_used'])
            ->get()
            ->filter(fn ($c) => $c->amount_remaining > 0)
            ->sortBy('created_at');

        $remaining = $amountDue;
        $applications = [];

        foreach ($credits as $credit) {
            if ($remaining <= 0) {
                break;
            }

            $available = $credit->amount_remaining;
            $toApply = min($available, $remaining);

            $application = CreditApplication::create([
                'credit_id' => $credit->id,
                'subscription_id' => $subscription->id,
                'billing_payment_id' => null,
                'amount_applied' => $toApply,
                'applied_at' => Carbon::now(),
            ]);

            $newUsed = (float) $credit->amount_used + $toApply;
            $newStatus = $newUsed >= (float) $credit->amount ? 'fully_used' : 'partially_used';
            $credit->update([
                'amount_used' => $newUsed,
                'status' => $newStatus,
            ]);

            $applications[] = $application;
            $remaining = round($remaining - $toApply, 2);
        }

        return [
            'credit_used' => round($amountDue - $remaining, 2),
            'remaining' => $remaining,
            'applications' => $applications,
        ];
    }

    public function reverseApplications(array $applications): void
    {
        foreach ($applications as $app) {
            $credit = $app->credit;
            $app->delete();

            $newUsed = (float) CreditApplication::where('credit_id', $credit->id)->sum('amount_applied');
            $newStatus = 'available';
            if ($newUsed > 0) {
                $newStatus = $newUsed >= (float) $credit->amount ? 'fully_used' : 'partially_used';
            }
            $credit->update(['amount_used' => $newUsed, 'status' => $newStatus]);
        }
    }

    public function getHistoryForOwner(string $ownerType, int $ownerId): Collection
    {
        return BillingCredit::where('owner_type', $ownerType)
            ->where('owner_id', $ownerId)
            ->with(['referral.referredBusiness', 'applications'])
            ->orderBy('created_at', 'desc')
            ->get();
    }

    public function getPendingPayouts(): Collection
    {
        return BillingCredit::where('owner_type', 'user')
            ->where('status', 'available')
            ->whereColumn('amount', '>', 'amount_used')
            ->with('referral.referralCode')
            ->get();
    }

    public function getAllCredits(): Collection
    {
        return BillingCredit::with(['referral.referredBusiness', 'applications'])
            ->orderBy('created_at', 'desc')
            ->get();
    }
}
