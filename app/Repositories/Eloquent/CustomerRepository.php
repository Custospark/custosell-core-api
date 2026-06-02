<?php

namespace App\Repositories\Eloquent;

use App\Models\Customer;
use App\Repositories\Contracts\CustomerRepositoryInterface;
use Illuminate\Database\Eloquent\Collection;

class CustomerRepository implements CustomerRepositoryInterface
{
    public function all(int $businessId): Collection
    {
        return Customer::where('business_id', $businessId)
            ->orderBy('name')
            ->get();
    }

    public function find(int $id): ?Customer
    {
        return Customer::find($id);
    }

    public function findByPhone(int $businessId, string $phone): ?Customer
    {
        return Customer::where('business_id', $businessId)
            ->where('phone', $phone)
            ->first();
    }

    public function create(array $data): Customer
    {
        return Customer::create($data);
    }

    public function update(Customer $customer, array $data): Customer
    {
        $customer->update($data);
        return $customer->fresh();
    }

    public function delete(Customer $customer): bool
    {
        return $customer->delete();
    }
}
