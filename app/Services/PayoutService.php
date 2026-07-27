<?php

namespace App\Services;

use App\Models\Payout;
use App\Models\SalesRep;
use App\Models\User;
use App\Services\Contracts\ReferralServiceInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class PayoutService
{
    public function __construct(
        protected ReferralServiceInterface $referralService,
    ) {}

    public function getPayables(): array
    {
        $payables = [];

        $salesReps = SalesRep::with(['user', 'referralCode.referrals'])->get();

        foreach ($salesReps as $rep) {
            $totalCommission = (float) ($rep->referralCode?->referrals->sum('commission_earned') ?? 0);
            $totalPaid = (float) $rep->payouts()->where('status', 'paid')->sum('amount');
            $lastPayout = $rep->payouts()->where('status', 'paid')->latest('paid_at')->first();

            $payables[] = [
                'type' => 'sales_rep',
                'id' => $rep->id,
                'user_id' => $rep->user_id,
                'name' => $rep->user?->name ?? 'Unknown',
                'email' => $rep->user?->email,
                'total_earned' => $totalCommission,
                'total_paid' => round($totalPaid, 2),
                'pending' => round(max(0, $totalCommission - $totalPaid), 2),
                'payout_frequency' => $rep->payout_frequency,
                'next_payout_at' => $rep->next_payout_at?->toIso8601String(),
                'last_payout_at' => $lastPayout?->paid_at?->toIso8601String(),
                'payment_method' => $rep->payment_method,
                'mobile_money_provider' => $rep->mobile_money_provider,
                'mobile_money_number' => $rep->mobile_money_number,
                'bank_name' => $rep->bank_name,
                'bank_account_name' => $rep->bank_account_name,
            ];
        }

        $userIdsWithReferrals = DB::table('referral_codes')
            ->join('referrals', 'referral_codes.id', '=', 'referrals.referral_code_id')
            ->leftJoin('businesses', 'referral_codes.owner_business_id', '=', 'businesses.id')
            ->where(function ($q) {
                $q->whereNotNull('referral_codes.owner_user_id')
                  ->orWhereNotNull('businesses.owner_id');
            })
            ->distinct()
            ->select(DB::raw('COALESCE(referral_codes.owner_user_id, businesses.owner_id) as user_id'))
            ->get()
            ->pluck('user_id');

        $salesRepUserIds = SalesRep::pluck('user_id');

        $users = User::whereIn('id', $userIdsWithReferrals)
            ->whereNotIn('id', $salesRepUserIds)
            ->get();

        foreach ($users as $user) {
            $earnings = $this->referralService->getEarningsByUser($user->id);
            $pending = (float) ($earnings['pending_rewards'] ?? 0);
            $rewarded = (float) ($earnings['rewarded_amount'] ?? 0);
            $totalPaid = (float) $user->payouts()->where('status', 'paid')->sum('amount');
            $lastPayout = $user->payouts()->where('status', 'paid')->latest('paid_at')->first();

            if ($pending <= 0 && $rewarded <= 0 && $totalPaid <= 0) {
                continue;
            }

            $payables[] = [
                'type' => 'user',
                'id' => $user->id,
                'user_id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'total_earned' => round($pending + $rewarded + $totalPaid, 2),
                'total_paid' => round($totalPaid, 2),
                'pending' => round(max(0, $pending + $rewarded - $totalPaid), 2),
                'payout_frequency' => $user->payout_frequency,
                'next_payout_at' => $user->next_payout_at?->toIso8601String(),
                'last_payout_at' => $lastPayout?->paid_at?->toIso8601String(),
                'payment_method' => null,
                'mobile_money_provider' => null,
                'mobile_money_number' => null,
                'bank_name' => null,
                'bank_account_name' => null,
            ];
        }

        usort($payables, fn($a, $b) => $b['pending'] <=> $a['pending']);

        return $payables;
    }

    public function recordPayout(
        string $payableType,
        int $payableId,
        float $amount,
        ?string $paymentMethod = null,
        ?string $notes = null,
        ?array $attachments = null,
        ?string $scheduledAt = null,
        int $paidBy = null,
    ): Payout {
        $payable = $this->resolvePayable($payableType, $payableId);

        return DB::transaction(function () use ($payable, $amount, $paymentMethod, $notes, $attachments, $scheduledAt, $paidBy) {
            $now = now();
            $isImmediate = empty($scheduledAt);

            $payout = $payable->payouts()->create([
                'amount' => $amount,
                'currency' => 'USD',
                'status' => $isImmediate ? 'paid' : 'scheduled',
                'payment_method' => $paymentMethod,
                'notes' => $notes,
                'attachments' => $attachments,
                'scheduled_at' => $isImmediate ? null : $scheduledAt,
                'paid_at' => $isImmediate ? $now : null,
                'paid_by' => $isImmediate ? $paidBy : null,
            ]);

            if ($isImmediate && $payable instanceof User) {
                $this->markUserReferralsRewarded($payable);
            }

            if ($isImmediate && $payable->next_payout_at && $payable->payout_frequency) {
                $payable->next_payout_at = $this->advanceDate($payable->next_payout_at, $payable->payout_frequency);
                $payable->save();
            }

            return $payout->fresh();
        });
    }

    public function cancelScheduled(int $id): void
    {
        $payout = Payout::where('status', 'scheduled')->findOrFail($id);
        $payout->update(['status' => 'cancelled']);
    }

    public function getPayouts(string $payableType, int $payableId): array
    {
        $payable = $this->resolvePayable($payableType, $payableId);

        return $payable->payouts()
            ->with('paidByUser')
            ->orderBy('created_at', 'desc')
            ->get()
            ->toArray();
    }

    public function updateSchedule(
        string $payableType,
        int $payableId,
        ?string $frequency,
        ?string $nextPayoutAt,
    ): void {
        $payable = $this->resolvePayable($payableType, $payableId);
        $payable->update([
            'payout_frequency' => $frequency,
            'next_payout_at' => $nextPayoutAt ?: null,
        ]);
    }

    protected function resolvePayable(string $type, int $id): Model
    {
        $model = match ($type) {
            'sales_rep' => SalesRep::class,
            'user' => User::class,
            default => throw new RuntimeException("Invalid payable type: {$type}"),
        };

        $instance = $model::find($id);
        if (!$instance) {
            throw new RuntimeException("{$type} not found");
        }

        return $instance;
    }

    protected function markUserReferralsRewarded(User $user): void
    {
        $code = $user->referralCode;
        if (!$code) return;

        $referrals = $code->referrals()
            ->where('status', 'active')
            ->where('reward_paid', false)
            ->where('reward_amount', '>', 0)
            ->get();

        foreach ($referrals as $referral) {
            $this->referralService->markRewarded($referral->id);
        }
    }

    protected function advanceDate(\DateTimeInterface $date, string $frequency): \DateTimeInterface
    {
        return match ($frequency) {
            'weekly' => $date->modify('+1 week'),
            'biweekly' => $date->modify('+2 weeks'),
            'monthly' => $date->modify('+1 month'),
            'quarterly' => $date->modify('+3 months'),
            default => $date,
        };
    }
}
