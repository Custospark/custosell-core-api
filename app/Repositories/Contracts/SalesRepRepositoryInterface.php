<?php

namespace App\Repositories\Contracts;

use App\Models\SalesRep;
use Illuminate\Database\Eloquent\Collection;

interface SalesRepRepositoryInterface
{
    public function all(): Collection;

    public function find(int $id): ?SalesRep;

    public function findByUser(int $userId): ?SalesRep;

    public function findByReferralCode(int $codeId): ?SalesRep;

    public function create(array $data): SalesRep;

    public function update(SalesRep $salesRep, array $data): SalesRep;

    public function delete(SalesRep $salesRep): bool;

    public function getActive(): Collection;
}
