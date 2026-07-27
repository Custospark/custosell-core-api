<?php

namespace App\Services\Contracts;

use App\Models\Payout;
use App\Models\SalesRep;
use Illuminate\Database\Eloquent\Collection;

interface SalesRepServiceInterface
{
    public function getAll(): Collection;
    public function getById(int $id): ?SalesRep;
    public function getByUser(int $userId): ?SalesRep;
    public function create(array $data): SalesRep;
    public function update(int $id, array $data): SalesRep;
    public function delete(int $id): bool;
    public function getActive(): Collection;
    public function getWithEarnings(): Collection;
    public function getEarnings(int $id): array;
    public function getPayouts(int $id): Collection;
    public function recordPayout(int $id, array $data, int $paidByUserId): Payout;
    public function import(array $rows): array;
    public function generateTemplate(): string;
}
