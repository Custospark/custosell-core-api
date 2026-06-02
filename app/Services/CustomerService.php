<?php

namespace App\Services;

use App\Models\Customer;
use App\Repositories\Contracts\CustomerRepositoryInterface;
use App\Services\Contracts\CustomerServiceInterface;
use Illuminate\Database\Eloquent\Collection;

class CustomerService implements CustomerServiceInterface
{
    public function __construct(
        protected CustomerRepositoryInterface $customerRepository,
    ) {}

    public function getAll(int $businessId): Collection
    {
        return $this->customerRepository->all($businessId);
    }

    public function getById(int $id): ?Customer
    {
        return $this->customerRepository->find($id);
    }

    public function create(int $businessId, array $data): Customer
    {
        $data['business_id'] = $businessId;
        return $this->customerRepository->create($data);
    }

    public function update(int $id, array $data): Customer
    {
        $customer = $this->customerRepository->find($id);
        if (!$customer) {
            throw new \RuntimeException('Customer not found');
        }
        return $this->customerRepository->update($customer, $data);
    }

    public function delete(int $id): bool
    {
        $customer = $this->customerRepository->find($id);
        if (!$customer) {
            throw new \RuntimeException('Customer not found');
        }
        return $this->customerRepository->delete($customer);
    }
}
