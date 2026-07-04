<?php

namespace App\Repositories\Contracts;

use App\Models\AccountType;
use Illuminate\Database\Eloquent\Collection;

interface AccountTypeRepositoryInterface
{
    public function all(): Collection;

    public function find(int $id): ?AccountType;

    public function findByName(string $name): ?AccountType;
}
