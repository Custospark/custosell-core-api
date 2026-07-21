<?php

namespace App\Services;

use App\Enums\Billing\DiscountType;
use App\Enums\Billing\ReferralCodeOwnerType;
use App\Models\SalesRep;
use App\Repositories\Contracts\ReferralCodeRepositoryInterface;
use App\Repositories\Contracts\SalesRepRepositoryInterface;
use App\Services\Contracts\SalesRepServiceInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class SalesRepService implements SalesRepServiceInterface
{
    public function __construct(
        protected SalesRepRepositoryInterface $salesRepRepository,
        protected ReferralCodeRepositoryInterface $referralCodeRepository,
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
            if (!isset($data['referral_code_id'])) {
                $referralCode = $this->referralCodeRepository->create([
                    'owner_type' => ReferralCodeOwnerType::SALES_REP,
                    'discount_type' => DiscountType::PERCENTAGE,
                    'discount_value' => $data['commission_rate'] ?? 0,
                    'is_active' => true,
                ]);
                $data['referral_code_id'] = $referralCode->id;
            }
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
}
