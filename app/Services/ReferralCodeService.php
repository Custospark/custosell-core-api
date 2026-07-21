<?php

namespace App\Services;

use App\Models\ReferralCode;
use App\Repositories\Contracts\ReferralCodeRepositoryInterface;
use App\Services\Contracts\ReferralCodeServiceInterface;
use Illuminate\Database\Eloquent\Collection;

class ReferralCodeService implements ReferralCodeServiceInterface
{
    public function __construct(
        protected ReferralCodeRepositoryInterface $referralCodeRepository,
    ) {}

    public function getAll(): Collection
    {
        return $this->referralCodeRepository->all();
    }

    public function getById(int $id): ?ReferralCode
    {
        return $this->referralCodeRepository->find($id);
    }

    public function getByCode(string $code): ?ReferralCode
    {
        return $this->referralCodeRepository->findByCode($code);
    }

    public function create(array $data): ReferralCode
    {
        if (!isset($data['code']) || empty($data['code'])) {
            $data['code'] = $this->generateCode();
        }
        $data['code'] = strtoupper($data['code']);
        return $this->referralCodeRepository->create($data);
    }

    public function update(int $id, array $data): ReferralCode
    {
        $referralCode = $this->referralCodeRepository->find($id);
        if (!$referralCode) {
            throw new \RuntimeException('ReferralCode not found');
        }
        return $this->referralCodeRepository->update($referralCode, $data);
    }

    public function delete(int $id): bool
    {
        $referralCode = $this->referralCodeRepository->find($id);
        if (!$referralCode) {
            throw new \RuntimeException('ReferralCode not found');
        }
        return $this->referralCodeRepository->delete($referralCode);
    }

    public function getActive(): Collection
    {
        return $this->referralCodeRepository->getActive();
    }

    public function generateCode(string $prefix = ''): string
    {
        $code = strtolower(substr(md5(uniqid()), 0, 4));
        if (!empty($prefix)) {
            return $prefix . '-' . $code;
        }
        return $code;
    }
}
