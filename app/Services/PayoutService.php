<?php

namespace App\Services;

use App\Models\BillingCredit;
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
        protected CreditService $creditService,
    ) {}

    public function getPayables(): array
    {
        $payables = [];

        $salesReps = SalesRep::with(['user', 'referralCode.referrals'])->get();

        foreach ($salesReps as $rep) {
            $totalCommission = (float) ($rep->referralCode?->referrals->sum('commission_earned') ?? 0);
            $totalPaid = (float) $rep->payouts()->where('status', 'paid')->sum('amount');
            $lastPayout = $rep->payouts()->where('status', 'paid')->latest('paid_at')->first();

            $paymentMethod = $rep->payment_method ?? $rep->user?->payment_method;
            $mobileProvider = $rep->mobile_money_provider ?? $rep->user?->mobile_money_provider;
            $mobileNumber = $rep->mobile_money_number ?? $rep->user?->mobile_money_number;
            $bankName = $rep->bank_name ?? $rep->user?->bank_name;
            $bankAccountName = $rep->bank_account_name ?? $rep->user?->bank_account_name;

            $payables[] = [
                'type' => 'sales_rep',
                'id' => $rep->id,
                'user_id' => $rep->user_id,
                'name' => $rep->user?->name ?? 'Unknown',
                'code' => $rep->referralCode?->code,
                'email' => $rep->user?->email,
                'phone' => $rep->phone ?? $rep->user?->phone,
                'total_earned' => $totalCommission,
                'total_paid' => round($totalPaid, 2),
                'pending' => round(max(0, $totalCommission - $totalPaid), 2),
                'payout_frequency' => $rep->payout_frequency,
                'next_payout_at' => $rep->next_payout_at?->toIso8601String(),
                'last_payout_at' => $lastPayout?->paid_at?->toIso8601String(),
                'payment_method' => $paymentMethod,
                'mobile_money_provider' => $mobileProvider,
                'mobile_money_number' => $mobileNumber,
                'mobile_money_name' => $rep->mobile_money_name,
                'bank_name' => $bankName,
                'bank_account_name' => $bankAccountName,
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
            $totalEarned = $pending + $rewarded;
            $totalPaid = (float) $user->payouts()->where('status', 'paid')->sum('amount');
            $lastPayout = $user->payouts()->where('status', 'paid')->latest('paid_at')->first();

            if ($totalEarned <= 0 && $totalPaid <= 0) {
                continue;
            }

            $payables[] = [
                'type' => 'user',
                'id' => $user->id,
                'user_id' => $user->id,
                'name' => $user->name,
                'code' => $earnings['referral_code'],
                'email' => $user->email,
                'phone' => $user->phone,
                'total_earned' => round($totalEarned, 2),
                'total_paid' => round($totalPaid, 2),
                'pending' => round(max(0, $totalEarned - $totalPaid), 2),
                'payout_frequency' => $user->payout_frequency,
                'next_payout_at' => $user->next_payout_at?->toIso8601String(),
                'last_payout_at' => $lastPayout?->paid_at?->toIso8601String(),
                'payment_method' => $user->payment_method,
                'mobile_money_provider' => $user->mobile_money_provider,
                'mobile_money_number' => $user->mobile_money_number,
                'mobile_money_name' => null,
                'bank_name' => $user->bank_name,
                'bank_account_name' => $user->bank_account_name,
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

        $pending = $this->getPendingAmount($payable);
        if ($amount > $pending) {
            throw new \RuntimeException("Payout amount ({$amount}) exceeds pending balance ({$pending}).");
        }

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
                $referrals = $this->getUnpaidActiveReferrals($payable);
                $this->markUserReferralsRewarded($payable);
                if ($amount > 0) {
                    $this->consumeReferralCredits($referrals, $amount);
                }
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

        $payouts = $payable->payouts()
            ->with('paidByUser')
            ->orderBy('created_at', 'desc')
            ->get()
            ->toArray();

        return array_map(function ($payout) {
            $payout['attachments'] = $this->normalizeAttachments($payout['attachments'] ?? null);
            return $payout;
        }, $payouts);
    }

    private function normalizeAttachments(mixed $attachments): ?array
    {
        if (empty($attachments)) return null;
        if (is_string($attachments)) {
            $attachments = json_decode($attachments, true) ?? [];
        }
        if (!is_array($attachments)) return null;

        return array_map(function ($att) {
            if (is_array($att)) {
                $att['file_url'] = !empty($att['path']) ? url('storage/' . ltrim($att['path'], '/')) : null;
            }
            return $att;
        }, $attachments);
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

    protected function getPendingAmount(Model $payable): float
    {
        if ($payable instanceof SalesRep) {
            $totalCommission = (float) ($payable->referralCode?->referrals->sum('commission_earned') ?? 0);
            $totalPaid = (float) $payable->payouts()->where('status', 'paid')->sum('amount');
            return round(max(0, $totalCommission - $totalPaid), 2);
        }

        if ($payable instanceof User) {
            $earnings = $this->referralService->getEarningsByUser($payable->id);
            $pending = (float) ($earnings['pending_rewards'] ?? 0);
            $rewardedPaid = (float) $payable->payouts()->where('status', 'paid')->sum('amount');
            return round(max(0, $pending + (float) ($earnings['rewarded_amount'] ?? 0) - $rewardedPaid), 2);
        }

        return 0;
    }

    protected function getUnpaidActiveReferrals(User $user): \Illuminate\Support\Collection
    {
        $code = $user->referralCode;
        if (!$code) return collect();

        return $code->referrals()
            ->where('status', 'active')
            ->where('reward_paid', false)
            ->where('reward_amount', '>', 0)
            ->get();
    }

    protected function consumeReferralCredits(\Illuminate\Support\Collection $referrals, float $amount): void
    {
        $remaining = $amount;
        foreach ($referrals as $referral) {
            if ($remaining <= 0) break;

            $credits = BillingCredit::where('referral_id', $referral->id)
                ->whereIn('status', ['available', 'partially_used'])
                ->get();

            foreach ($credits as $credit) {
                if ($remaining <= 0) break;

                $available = $credit->amount_remaining;
                if ($available <= 0) continue;

                $toConsume = min($available, $remaining);
                $newUsed = (float) $credit->amount_used + $toConsume;
                $credit->update([
                    'amount_used' => $newUsed,
                    'status' => $newUsed >= (float) $credit->amount ? 'fully_used' : 'partially_used',
                ]);
                $remaining = round($remaining - $toConsume, 2);
            }
        }
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
