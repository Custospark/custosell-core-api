<?php

namespace App\Services;

use App\Enums\Billing\DiscountType;
use App\Enums\Billing\ReferralCodeOwnerType;
use App\Enums\Billing\ReferralStatus;
use App\Models\Referral;
use App\Models\SalesRep;
use App\Models\User;
use App\Repositories\Contracts\SalesRepRepositoryInterface;
use App\Services\Contracts\ReferralCodeServiceInterface;
use App\Services\Contracts\SalesRepServiceInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SalesRepService implements SalesRepServiceInterface
{
    public function __construct(
        protected SalesRepRepositoryInterface $salesRepRepository,
        protected ReferralCodeServiceInterface $referralCodeService,
    ) {}

    public function getAll(): Collection
    {
        return $this->salesRepRepository->all();
    }

    public function getById(int $id): ?SalesRep
    {
        return $this->salesRepRepository->find($id);
    }

    public function getByUser(int $userId): ?SalesRep
    {
        return $this->salesRepRepository->findByUser($userId);
    }

    public function create(array $data): SalesRep
    {
        return DB::transaction(function () use ($data) {
            // Find or create user by email
            $user = User::where('email', $data['email'])->first();

            if (!$user) {
                $user = User::create([
                    'name' => $data['name'] ?? explode('@', $data['email'])[0],
                    'email' => $data['email'],
                    'password' => bcrypt(Str::random(32)),
                    'is_active' => true,
                ]);
            }

            // Prevent duplicate sales rep for the same user
            $existing = $this->salesRepRepository->findByUser($user->id);
            if ($existing) {
                throw new \RuntimeException('This user is already a sales representative');
            }

            // Create referral code (auto-generates 6-char code)
            $referralCode = $this->referralCodeService->create([
                'owner_type' => ReferralCodeOwnerType::SALES_REP,
                'discount_type' => DiscountType::PERCENTAGE,
                'discount_value' => $data['commission_rate'] ?? 0,
                'is_active' => true,
            ]);

            $data['user_id'] = $user->id;
            $data['referral_code_id'] = $referralCode->id;
            unset($data['email'], $data['name']);

            return $this->salesRepRepository->create($data);
        });
    }

    public function update(int $id, array $data): SalesRep
    {
        $salesRep = $this->salesRepRepository->find($id);
        if (!$salesRep) {
            throw new \RuntimeException('SalesRep not found');
        }
        return $this->salesRepRepository->update($salesRep, $data);
    }

    public function delete(int $id): bool
    {
        $salesRep = $this->salesRepRepository->find($id);
        if (!$salesRep) {
            throw new \RuntimeException('SalesRep not found');
        }
        return $this->salesRepRepository->delete($salesRep);
    }

    public function getActive(): Collection
    {
        return $this->salesRepRepository->getActive();
    }

    public function getWithEarnings(): Collection
    {
        $salesReps = $this->salesRepRepository->all();
        $salesReps->load('user', 'referralCode');

        foreach ($salesReps as $rep) {
            $referrals = Referral::where('referral_code_id', $rep->referral_code_id)->get();
            $rep->total_commission = (float) $referrals->sum('commission_earned');
            $rep->pending_commission = (float) $referrals->where('commission_paid', false)->sum('commission_earned');
            $rep->paid_commission = (float) $referrals->where('commission_paid', true)->sum('commission_earned');
            $rep->total_referrals = $referrals->count();
            $rep->active_referrals = $referrals->where('status', ReferralStatus::ACTIVE)->count();
        }

        return $salesReps;
    }

    public function getEarnings(int $id): array
    {
        $salesRep = $this->salesRepRepository->find($id);
        if (!$salesRep) {
            throw new \RuntimeException('SalesRep not found');
        }

        $referrals = Referral::where('referral_code_id', $salesRep->referral_code_id)
            ->with('referredBusiness', 'subscription')
            ->orderBy('created_at', 'desc')
            ->get();

        return [
            'sales_rep' => $salesRep->load('user', 'referralCode'),
            'total_commission' => (float) $referrals->sum('commission_earned'),
            'pending_commission' => (float) $referrals->where('commission_paid', false)->sum('commission_earned'),
            'paid_commission' => (float) $referrals->where('commission_paid', true)->sum('commission_earned'),
            'total_referrals' => $referrals->count(),
            'referrals' => $referrals->toArray(),
        ];
    }
}
