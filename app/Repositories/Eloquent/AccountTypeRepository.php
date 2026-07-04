<?php

namespace App\Repositories\Eloquent;

use App\Models\AccountType;
use App\Repositories\Contracts\AccountTypeRepositoryInterface;
use Illuminate\Database\Eloquent\Collection;

class AccountTypeRepository implements AccountTypeRepositoryInterface
{
    public function all(): Collection
    {
        return AccountType::all();
    }

    public function find(int $id): ?AccountType
    {
        return AccountType::find($id);
    }

    public function findByName(string $name): ?AccountType
    {
        return AccountType::where('name', $name)->first();
    }
}
