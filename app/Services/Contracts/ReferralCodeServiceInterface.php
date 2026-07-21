<?php

namespace App\Services\Contracts;

use App\Models\ReferralCode;
use Illuminate\Database\Eloquent\Collection;

interface ReferralCodeServiceInterface
{
    public function getAll(): Collection;
    public function getById(int $id): ?ReferralCode;
    public function getByCode(string $code): ?ReferralCode;
    public function create(array $data): ReferralCode;
    public function update(int $id, array $data): ReferralCode;
    public function delete(int $id): bool;
    public function getActive(): Collection;
    public function generateCode(string $prefix = ''): string;
}
