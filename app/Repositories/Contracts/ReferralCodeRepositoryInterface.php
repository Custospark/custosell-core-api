<?php

namespace App\Repositories\Contracts;

use App\Models\ReferralCode;
use Illuminate\Database\Eloquent\Collection;

interface ReferralCodeRepositoryInterface
{
    public function all(): Collection;

    public function find(int $id): ?ReferralCode;

    public function findByCode(string $code): ?ReferralCode;

    public function create(array $data): ReferralCode;

    public function update(ReferralCode $referralCode, array $data): ReferralCode;

    public function delete(ReferralCode $referralCode): bool;

    public function getActive(): Collection;

    public function findValidByCode(string $code): ?ReferralCode;
}
