<?php

namespace App\Services;

use App\Repositories\Contracts\ChartOfAccountRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

class ChartOfAccountService
{
    public function __construct(
        protected ChartOfAccountRepositoryInterface $chartOfAccountRepository,
    ) {}

    public function getAll(int $businessId, array $filters = []): LengthAwarePaginator
    {
        return $this->chartOfAccountRepository->all($businessId, $filters);
    }

    public function getTree(int $businessId): Collection
    {
        return $this->chartOfAccountRepository->getTree($businessId);
    }

    public function getById(int $id)
    {
        $account = $this->chartOfAccountRepository->find($id);
        if (!$account) {
            throw new \RuntimeException('Chart of account not found');
        }
        return $account;
    }

    public function create(int $businessId, array $data)
    {
        $data['business_id'] = $businessId;

        $existing = $this->chartOfAccountRepository->findByCode($businessId, $data['code']);
        if ($existing) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'code' => 'Account code already exists for this business.',
            ]);
        }

        if (!empty($data['parent_id'])) {
            $parent = $this->chartOfAccountRepository->find($data['parent_id']);
            if (!$parent || $parent->business_id !== $businessId) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'parent_id' => 'Invalid parent account.',
                ]);
            }
            $data['normal_balance'] = $parent->normal_balance;
            $data['type_id'] = $parent->type_id;
        }

        return $this->chartOfAccountRepository->create($data);
    }

    public function update(int $id, array $data)
    {
        $account = $this->getById($id);

        if (!empty($data['code']) && $data['code'] !== $account->code) {
            $existing = $this->chartOfAccountRepository->findByCode($account->business_id, $data['code']);
            if ($existing) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'code' => 'Account code already exists for this business.',
                ]);
            }
        }

        return $this->chartOfAccountRepository->update($account, $data);
    }

    public function deactivate(int $id): void
    {
        $account = $this->getById($id);

        if ($account->journalEntryLines()->exists()) {
            throw new \RuntimeException('Cannot deactivate account with existing journal entries.');
        }

        $this->chartOfAccountRepository->update($account, ['is_active' => false]);
    }

    public function destroy(int $id): void
    {
        $account = $this->getById($id);

        if ($account->is_system) {
            throw new \RuntimeException('Cannot delete a system account.');
        }

        if ($account->journalEntryLines()->exists()) {
            // Has transactions — soft deactivate instead
            $this->chartOfAccountRepository->update($account, ['is_active' => false]);
            return;
        }

        // No transactions — hard delete
        $this->chartOfAccountRepository->delete($account);
    }

    public function seedDefaultTemplate(int $businessId): void
    {
        $seeder = new \Database\Seeders\DefaultAccountingTemplateSeeder();
        $seeder->run();
    }
}
