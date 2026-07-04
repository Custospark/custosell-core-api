<?php

namespace App\Services;

use App\Repositories\Contracts\ChartOfAccountRepositoryInterface;
use App\Repositories\Contracts\FixedAssetRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class FixedAssetService
{
    public function __construct(
        protected FixedAssetRepositoryInterface $fixedAssetRepository,
        protected ChartOfAccountRepositoryInterface $chartOfAccountRepository,
    ) {}

    public function getAll(int $businessId, array $filters = []): LengthAwarePaginator
    {
        return $this->fixedAssetRepository->all($businessId, $filters);
    }

    public function getById(int $id)
    {
        $asset = $this->fixedAssetRepository->find($id);
        if (!$asset) {
            throw new \RuntimeException('Fixed asset not found');
        }
        return $asset;
    }

    public function create(int $businessId, array $data)
    {
        $data['business_id'] = $businessId;

        $account = $this->chartOfAccountRepository->find($data['account_id']);
        if (!$account || $account->business_id !== $businessId) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'account_id' => 'Invalid account.',
            ]);
        }

        if (!isset($data['book_value'])) {
            $data['book_value'] = $data['cost'];
        }

        return $this->fixedAssetRepository->create($data);
    }

    public function update(int $id, array $data)
    {
        $asset = $this->getById($id);

        if (!empty($data['cost'])) {
            $data['book_value'] = $data['cost'] - ($asset->cost - $asset->book_value);
        }

        return $this->fixedAssetRepository->update($asset, $data);
    }

    public function delete(int $id): void
    {
        $asset = $this->getById($id);
        $this->fixedAssetRepository->delete($asset);
    }
}
