<?php

namespace App\Services;

use App\Models\Location;
use App\Models\Role;
use App\Models\StaffTransfer;
use App\Models\User;
use App\Repositories\Contracts\StaffTransferRepositoryInterface;
use App\Repositories\Contracts\UserRepositoryInterface;
use App\Services\Contracts\LocationServiceInterface;
use App\Services\Contracts\StaffTransferServiceInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class StaffTransferService implements StaffTransferServiceInterface
{
    public function __construct(
        protected StaffTransferRepositoryInterface $staffTransferRepository,
        protected UserRepositoryInterface $userRepository,
        protected LocationServiceInterface $locationService,
    ) {}

    public function getAll(int $businessId): Collection
    {
        return $this->staffTransferRepository->all($businessId);
    }

    public function getById(int $id): ?StaffTransfer
    {
        return $this->staffTransferRepository->find($id);
    }

    public function getByIdForBusiness(int $id, int $businessId): ?StaffTransfer
    {
        return $this->staffTransferRepository->findForBusiness($id, $businessId);
    }

    public function transfer(int $businessId, int $actorId, array $data): StaffTransfer
    {
        $user = $this->userRepository->findForBusiness((int) $data['user_id'], $businessId);
        if (!$user) {
            throw ValidationException::withMessages([
                'user_id' => 'The selected staff member does not belong to this business.',
            ]);
        }

        $toLocation = $this->assertLocationInBusiness($data['to_location_id'] ?? null, $businessId);
        $fromLocation = $this->resolveFromLocation($data['from_location_id'] ?? null, $user, $businessId);

        $newRole = null;
        $oldRole = $user->role_id;
        if (isset($data['new_role_id']) && $data['new_role_id'] !== null && (int) $data['new_role_id'] !== (int) $oldRole) {
            $newRole = $this->assertRoleInBusiness((int) $data['new_role_id'], $businessId);
        }

        return DB::transaction(function () use (
            $businessId,
            $actorId,
            $data,
            $user,
            $fromLocation,
            $toLocation,
            $oldRole,
            $newRole,
        ) {
            // Move the staff member to the new branch: home location + pivot list.
            $this->userRepository->update($user, ['location_id' => $toLocation->id]);
            $this->locationService->assignUserToLocations($user->id, [$toLocation->id]);

            if ($newRole && $newRole->id !== $oldRole) {
                $this->userRepository->update($user, ['role_id' => $newRole->id]);
            }

            $record = $this->staffTransferRepository->create([
                'business_id' => $businessId,
                'user_id' => $user->id,
                'from_location_id' => $fromLocation?->id,
                'to_location_id' => $toLocation->id,
                'transferred_by' => $actorId,
                'transfer_type' => $data['transfer_type'] ?? 'permanent',
                'status' => $data['status'] ?? 'completed',
                'approval_required' => (bool) ($data['approval_required'] ?? false),
                'approved_by' => $data['approved_by'] ?? null,
                'approved_at' => $data['approved_at'] ?? null,
                'effective_at' => $data['effective_at'] ?? now()->toDateString(),
                'end_at' => $data['end_at'] ?? null,
                'reason' => $data['reason'] ?? null,
                'notes' => $data['notes'] ?? null,
                'old_role_id' => $oldRole,
                'new_role_id' => $newRole?->id ?? null,
                'old_shift_id' => $data['old_shift_id'] ?? null,
                'new_shift_id' => $data['new_shift_id'] ?? null,
                'old_salary' => $data['old_salary'] ?? null,
                'new_salary' => $data['new_salary'] ?? null,
                'old_employment_type' => $data['old_employment_type'] ?? null,
                'new_employment_type' => $data['new_employment_type'] ?? null,
                'meta' => $data['meta'] ?? null,
            ]);

            return $record->load([
                'user:id,name,email',
                'fromLocation:id,name',
                'toLocation:id,name',
                'transferredBy:id,name',
            ]);
        });
    }

    public function countByBusiness(int $businessId): int
    {
        return $this->staffTransferRepository->countByBusiness($businessId);
    }

    private function assertLocationInBusiness(mixed $locationId, int $businessId): Location
    {
        $location = $locationId ? $this->locationService->getById((int) $locationId) : null;
        if (!$location || $location->business_id !== $businessId) {
            throw ValidationException::withMessages([
                'to_location_id' => 'The selected branch is not available for this business.',
            ]);
        }
        if (!$location->is_active) {
            throw ValidationException::withMessages([
                'to_location_id' => 'The selected branch is inactive.',
            ]);
        }
        return $location;
    }

    private function resolveFromLocation(mixed $fromLocationId, User $user, int $businessId): ?Location
    {
        if ($fromLocationId === null) {
            return $user->location_id
                ? $this->locationService->getById((int) $user->location_id)
                : null;
        }

        $location = $this->locationService->getById((int) $fromLocationId);
        if (!$location || $location->business_id !== $businessId) {
            throw ValidationException::withMessages([
                'from_location_id' => 'The origin branch is not available for this business.',
            ]);
        }
        return $location;
    }

    private function assertRoleInBusiness(int $roleId, int $businessId): Role
    {
        $role = Role::query()
            ->whereKey($roleId)
            ->where(function ($query) use ($businessId) {
                $query->whereNull('business_id')
                    ->orWhere('business_id', $businessId);
            })
            ->first();

        if (!$role) {
            throw ValidationException::withMessages([
                'new_role_id' => 'The selected role is not available for this business.',
            ]);
        }
        return $role;
    }
}
