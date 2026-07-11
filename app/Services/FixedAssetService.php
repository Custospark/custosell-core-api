<?php

namespace App\Services;

use App\Models\Expense;
use App\Models\FixedAsset;
use App\Models\FixedAssetAssignment;
use App\Models\Hr\HrEmployee;
use App\Repositories\Contracts\ChartOfAccountRepositoryInterface;
use App\Repositories\Contracts\FixedAssetRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class FixedAssetService
{
    private const CATEGORY_ACCOUNT_CODES = [
        'laptop' => '1203',
        'phone' => '1203',
        'furniture' => '1202',
        'vehicle' => '1204',
        'other' => '1203',
    ];

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

    public function getByIdForBusiness(int $id, int $businessId): FixedAsset
    {
        $asset = $this->getById($id);
        if ((int) $asset->business_id !== $businessId) {
            throw new \RuntimeException('Fixed asset not found');
        }
        return $asset;
    }

    public function create(int $businessId, array $data)
    {
        $data['business_id'] = $businessId;

        if (empty($data['account_id'])) {
            $data['account_id'] = $this->resolveAccountIdByCategory(
                $businessId,
                $data['category'] ?? null,
            );
        }

        $account = $this->chartOfAccountRepository->find($data['account_id']);
        if (!$account || $account->business_id !== $businessId) {
            throw ValidationException::withMessages([
                'account_id' => 'Invalid account.',
            ]);
        }

        if (!isset($data['book_value'])) {
            $data['book_value'] = $data['cost'];
        }

        if (!isset($data['condition'])) {
            $data['condition'] = 'good';
        }

        return $this->fixedAssetRepository->create($data);
    }

    public function update(int $id, array $data, ?int $businessId = null)
    {
        $asset = $businessId
            ? $this->getByIdForBusiness($id, $businessId)
            : $this->getById($id);

        if (($data['status'] ?? null) === 'disposed') {
            $this->assertNotAssignedBeforeDispose($asset);
        }

        if (!empty($data['cost'])) {
            $data['book_value'] = $data['cost'] - ($asset->cost - $asset->book_value);
        }

        return $this->fixedAssetRepository->update($asset, $data);
    }

    public function updateCustody(int $id, array $data, int $businessId): FixedAsset
    {
        $asset = $this->getByIdForBusiness($id, $businessId);

        $allowed = [
            'asset_tag',
            'serial_number',
            'category',
            'location',
            'condition',
            'notes',
        ];

        $payload = array_intersect_key($data, array_flip($allowed));

        return $this->fixedAssetRepository->update($asset, $payload);
    }

    public function assign(
        int $assetId,
        int $employeeId,
        int $userId,
        ?string $notes,
        int $businessId,
    ): FixedAsset {
        return DB::transaction(function () use ($assetId, $employeeId, $userId, $notes, $businessId) {
            $asset = $this->getByIdForBusiness($assetId, $businessId);
            $this->assertEmployeeBelongsToBusiness($employeeId, $businessId);

            if ($asset->status === 'disposed') {
                throw ValidationException::withMessages([
                    'asset_id' => 'Cannot assign a disposed asset.',
                ]);
            }

            if ($asset->assigned_employee_id) {
                throw ValidationException::withMessages([
                    'employee_id' => 'Asset is already assigned. Transfer or return it first.',
                ]);
            }

            $asset = $this->fixedAssetRepository->update($asset, [
                'assigned_employee_id' => $employeeId,
                'assigned_at' => now(),
                'returned_at' => null,
            ]);

            $this->writeAssignmentHistory(
                $asset,
                null,
                $employeeId,
                'assign',
                $userId,
                $notes,
            );

            return $this->getByIdForBusiness($asset->id, $businessId);
        });
    }

    public function transfer(
        int $assetId,
        int $toEmployeeId,
        int $userId,
        ?string $notes,
        int $businessId,
    ): FixedAsset {
        return DB::transaction(function () use ($assetId, $toEmployeeId, $userId, $notes, $businessId) {
            $asset = $this->getByIdForBusiness($assetId, $businessId);
            $this->assertEmployeeBelongsToBusiness($toEmployeeId, $businessId);

            if (!$asset->assigned_employee_id) {
                throw ValidationException::withMessages([
                    'employee_id' => 'Asset is not assigned. Assign it first.',
                ]);
            }

            if ((int) $asset->assigned_employee_id === $toEmployeeId) {
                throw ValidationException::withMessages([
                    'employee_id' => 'Asset is already assigned to this employee.',
                ]);
            }

            $fromEmployeeId = (int) $asset->assigned_employee_id;

            $asset = $this->fixedAssetRepository->update($asset, [
                'assigned_employee_id' => $toEmployeeId,
                'assigned_at' => now(),
                'returned_at' => null,
            ]);

            $this->writeAssignmentHistory(
                $asset,
                $fromEmployeeId,
                $toEmployeeId,
                'transfer',
                $userId,
                $notes,
            );

            return $this->getByIdForBusiness($asset->id, $businessId);
        });
    }

    public function returnAsset(
        int $assetId,
        int $userId,
        ?string $notes,
        int $businessId,
    ): FixedAsset {
        return DB::transaction(function () use ($assetId, $userId, $notes, $businessId) {
            $asset = $this->getByIdForBusiness($assetId, $businessId);

            if (!$asset->assigned_employee_id) {
                throw ValidationException::withMessages([
                    'asset_id' => 'Asset is not assigned.',
                ]);
            }

            $fromEmployeeId = (int) $asset->assigned_employee_id;

            $asset = $this->fixedAssetRepository->update($asset, [
                'assigned_employee_id' => null,
                'returned_at' => now(),
            ]);

            $this->writeAssignmentHistory(
                $asset,
                $fromEmployeeId,
                null,
                'return',
                $userId,
                $notes,
            );

            return $this->getByIdForBusiness($asset->id, $businessId);
        });
    }

    public function getAssignments(int $assetId, int $businessId): Collection
    {
        $asset = $this->getByIdForBusiness($assetId, $businessId);

        return FixedAssetAssignment::query()
            ->where('asset_id', $asset->id)
            ->where('business_id', $businessId)
            ->with(['fromEmployee', 'toEmployee', 'performedBy'])
            ->orderByDesc('occurred_at')
            ->get();
    }

    public function getMaintenanceExpenses(int $assetId, int $businessId): Collection
    {
        $this->getByIdForBusiness($assetId, $businessId);

        return Expense::query()
            ->where('business_id', $businessId)
            ->where('fixed_asset_id', $assetId)
            ->with(['expenseCategory', 'recordedBy'])
            ->orderByDesc('expense_date')
            ->get();
    }

    public function delete(int $id): void
    {
        $asset = $this->getById($id);
        $this->fixedAssetRepository->delete($asset);
    }

    protected function assertNotAssignedBeforeDispose(FixedAsset $asset): void
    {
        if ($asset->assigned_employee_id) {
            throw ValidationException::withMessages([
                'status' => 'Cannot dispose an asset that is still assigned. Return it first.',
            ]);
        }
    }

    protected function resolveAccountIdByCategory(int $businessId, ?string $category): int
    {
        $code = self::CATEGORY_ACCOUNT_CODES[$category ?? 'other'] ?? '1203';
        $account = $this->chartOfAccountRepository->findByCode($businessId, $code);

        if (!$account) {
            throw ValidationException::withMessages([
                'account_id' => "Could not resolve chart of account for category ({$code}).",
            ]);
        }

        return $account->id;
    }

    protected function assertEmployeeBelongsToBusiness(int $employeeId, int $businessId): void
    {
        $exists = HrEmployee::query()
            ->where('id', $employeeId)
            ->where('business_id', $businessId)
            ->exists();

        if (!$exists) {
            throw ValidationException::withMessages([
                'employee_id' => 'Invalid employee for this business.',
            ]);
        }
    }

    protected function writeAssignmentHistory(
        FixedAsset $asset,
        ?int $fromEmployeeId,
        ?int $toEmployeeId,
        string $action,
        int $userId,
        ?string $notes,
    ): FixedAssetAssignment {
        return FixedAssetAssignment::create([
            'business_id' => $asset->business_id,
            'asset_id' => $asset->id,
            'from_employee_id' => $fromEmployeeId,
            'to_employee_id' => $toEmployeeId,
            'action' => $action,
            'notes' => $notes,
            'performed_by' => $userId,
            'occurred_at' => now(),
        ]);
    }
}
