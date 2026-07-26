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
        $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $code = '';
        for ($i = 0; $i < 6; $i++) {
            $code .= $chars[random_int(0, strlen($chars) - 1)];
        }
        if (!empty($prefix)) {
            return strtoupper($prefix) . '-' . $code;
        }
        return $code;
    }

    public function generateCodeForUser(string $name): string
    {
        $parts = preg_split('/\s+/', trim($name));
        $firstName = $parts[0] ?? '';
        $lastName = $parts[1] ?? '';

        $initials = substr($firstName, 0, 2);
        if (!empty($lastName)) {
            $initials .= substr($lastName, 0, 2);
        } else {
            $initials = substr($firstName . 'XXXX', 0, 4);
        }
        $initials = strtoupper(preg_replace('/[^A-Za-z0-9]/', 'X', $initials));

        if (strlen($initials) < 2) {
            $initials = str_pad($initials, 2, 'X');
        }

        $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $suffix = '';
        for ($i = 0; $i < 4; $i++) {
            $suffix .= $chars[random_int(0, strlen($chars) - 1)];
        }

        $code = substr($initials, 0, 4) . '-' . $suffix;

        if ($this->getByCode($code)) {
            return $this->generateCodeForUser($name);
        }

        return $code;
    }

}
