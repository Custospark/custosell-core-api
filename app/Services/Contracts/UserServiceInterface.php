<?php

namespace App\Services\Contracts;

use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

interface UserServiceInterface
{
    public function getAll(int $businessId): Collection;

    public function getById(int $id): ?User;

    public function register(array $data): User;

    public function login(string $email, string $password): ?User;

    public function createStaff(int $businessId, array $data): User;

    public function update(int $id, array $data): User;

    public function delete(int $id): bool;

    public function countByBusiness(int $businessId): int;
}
