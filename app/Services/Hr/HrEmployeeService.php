<?php

declare(strict_types=1);

namespace App\Services\Hr;

use App\Models\Hr\HrEmployee;
use App\Models\User;
use App\Services\Contracts\UserServiceInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class HrEmployeeService
{
    public function __construct(
        protected HrOrgService $org,
        protected HrAuditService $audit,
        protected UserServiceInterface $users,
    ) {}

    public function list(int $businessId, array $filters = [], int $perPage = 50): LengthAwarePaginator
    {
        $query = HrEmployee::query()
            ->where('business_id', $businessId)
            ->with(['department:id,name', 'position:id,title', 'user:id,name,email', 'manager:id,first_name,last_name'])
            ->orderBy('last_name')
            ->orderBy('first_name');

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['department_id'])) {
            $query->where('department_id', (int) $filters['department_id']);
        }

        if (! empty($filters['employment_type'])) {
            $query->where('employment_type', $filters['employment_type']);
        }

        if (! empty($filters['q'])) {
            $term = '%'.trim($filters['q']).'%';
            $query->where(function ($q) use ($term): void {
                $q->where('first_name', 'like', $term)
                    ->orWhere('last_name', 'like', $term)
                    ->orWhere('email', 'like', $term)
                    ->orWhere('employee_number', 'like', $term)
                    ->orWhere('phone', 'like', $term);
            });
        }

        return $query->paginate(min(max($perPage, 1), 200));
    }

    public function findOrFail(int $businessId, int $id): HrEmployee
    {
        $employee = HrEmployee::query()
            ->where('business_id', $businessId)
            ->with(['department', 'position', 'user:id,name,email', 'manager:id,first_name,last_name'])
            ->whereKey($id)
            ->first();

        if (! $employee) {
            abort(404, 'Employee not found');
        }

        return $employee;
    }

    public function create(int $businessId, array $data, ?int $actorUserId = null): HrEmployee
    {
        $this->assertOrgRefs($businessId, $data);
        $this->assertUniqueEmployeeNumber($businessId, $data['employee_number']);

        if (! empty($data['user_id'])) {
            $this->assertLinkableUser($businessId, (int) $data['user_id']);
        }

        $employee = HrEmployee::create([
            'business_id' => $businessId,
            'user_id' => $data['user_id'] ?? null,
            'employee_number' => $data['employee_number'],
            'first_name' => $data['first_name'],
            'last_name' => $data['last_name'],
            'email' => $data['email'] ?? null,
            'phone' => $data['phone'] ?? null,
            'department_id' => $data['department_id'] ?? null,
            'position_id' => $data['position_id'] ?? null,
            'manager_employee_id' => $data['manager_employee_id'] ?? null,
            'employment_type' => $data['employment_type'] ?? 'full_time',
            'status' => $data['status'] ?? 'onboarding',
            'hire_date' => $data['hire_date'] ?? null,
            'termination_date' => $data['termination_date'] ?? null,
            'notes' => $data['notes'] ?? null,
        ]);

        $this->audit->record($businessId, $actorUserId, 'employee.created', 'hr_employee', $employee->id, [
            'employee_number' => $employee->employee_number,
            'name' => $employee->full_name,
        ]);

        return $employee->load(['department:id,name', 'position:id,title', 'user:id,name,email']);
    }

    public function update(int $businessId, int $id, array $data, ?int $actorUserId = null): HrEmployee
    {
        $employee = $this->findOrFail($businessId, $id);
        $this->assertOrgRefs($businessId, $data);

        if (isset($data['employee_number']) && $data['employee_number'] !== $employee->employee_number) {
            $this->assertUniqueEmployeeNumber($businessId, $data['employee_number'], $employee->id);
        }

        if (array_key_exists('manager_employee_id', $data) && (int) $data['manager_employee_id'] === $employee->id) {
            throw ValidationException::withMessages([
                'manager_employee_id' => 'An employee cannot be their own manager.',
            ]);
        }

        $employee->fill(array_intersect_key($data, array_flip([
            'employee_number', 'first_name', 'last_name', 'email', 'phone',
            'department_id', 'position_id', 'manager_employee_id',
            'employment_type', 'status', 'hire_date', 'termination_date', 'notes',
        ])));
        $employee->save();

        $this->audit->record($businessId, $actorUserId, 'employee.updated', 'hr_employee', $employee->id);

        return $employee->fresh(['department:id,name', 'position:id,title', 'user:id,name,email', 'manager:id,first_name,last_name']);
    }

    public function linkUser(int $businessId, int $employeeId, int $userId, ?int $actorUserId = null): HrEmployee
    {
        $employee = $this->findOrFail($businessId, $employeeId);
        $this->assertLinkableUser($businessId, $userId, $employee->id);

        $employee->user_id = $userId;
        $employee->save();

        $this->audit->record($businessId, $actorUserId, 'employee.linked_user', 'hr_employee', $employee->id, [
            'user_id' => $userId,
        ]);

        return $employee->fresh(['user:id,name,email']);
    }

    public function unlinkUser(int $businessId, int $employeeId, ?int $actorUserId = null): HrEmployee
    {
        $employee = $this->findOrFail($businessId, $employeeId);
        $employee->user_id = null;
        $employee->save();

        $this->audit->record($businessId, $actorUserId, 'employee.unlinked_user', 'hr_employee', $employee->id);

        return $employee->fresh();
    }

    /**
     * Create staff login + HR employee in one transaction (no auto-mirror on staff create).
     *
     * @param  array<string, mixed>  $employeeData
     * @param  array<string, mixed>  $accountData
     */
    public function createWithAccount(
        int $businessId,
        array $employeeData,
        array $accountData,
        int $actorUserId,
    ): HrEmployee {
        return DB::transaction(function () use ($businessId, $employeeData, $accountData, $actorUserId) {
            $user = $this->users->createStaff($businessId, $accountData, false);

            $employeeData['user_id'] = $user->id;
            if (empty($employeeData['email'])) {
                $employeeData['email'] = $user->email;
            }
            if (empty($employeeData['phone']) && ! empty($accountData['phone'])) {
                $employeeData['phone'] = $accountData['phone'];
            }

            return $this->create($businessId, $employeeData, $actorUserId);
        });
    }

    /**
     * Create a staff login for an existing HR employee and link it.
     *
     * @param  array<string, mixed>  $accountData
     */
    public function createAccountForEmployee(
        int $businessId,
        int $employeeId,
        array $accountData,
        int $actorUserId,
    ): HrEmployee {
        $employee = $this->findOrFail($businessId, $employeeId);

        if ($employee->user_id) {
            throw ValidationException::withMessages([
                'user_id' => 'This employee already has an app login.',
            ]);
        }

        return DB::transaction(function () use ($businessId, $employee, $accountData, $actorUserId) {
            $user = $this->users->createStaff($businessId, $accountData, false);

            $employee->user_id = $user->id;
            if (! $employee->email) {
                $employee->email = $user->email;
            }
            if (! $employee->phone && ! empty($accountData['phone'])) {
                $employee->phone = $accountData['phone'];
            }
            $employee->save();

            $this->audit->record($businessId, $actorUserId, 'employee.account_created', 'hr_employee', $employee->id, [
                'user_id' => $user->id,
            ]);

            return $employee->fresh(['department:id,name', 'position:id,title', 'user:id,name,email']);
        });
    }

    /**
     * Delete the linked staff login; HR employee remains with user_id cleared.
     */
    public function removeAccount(int $businessId, int $employeeId, int $actorUserId): HrEmployee
    {
        $employee = $this->findOrFail($businessId, $employeeId);

        if (! $employee->user_id) {
            throw ValidationException::withMessages([
                'user_id' => 'This employee does not have an app login to remove.',
            ]);
        }

        $userId = (int) $employee->user_id;

        return DB::transaction(function () use ($businessId, $employee, $userId, $actorUserId) {
            $employee->user_id = null;
            $employee->save();

            $this->users->delete($userId, $businessId, $actorUserId);

            $this->audit->record($businessId, $actorUserId, 'employee.account_removed', 'hr_employee', $employee->id, [
                'user_id' => $userId,
            ]);

            return $employee->fresh(['department:id,name', 'position:id,title', 'user:id,name,email']);
        });
    }

    /**
     * Soft-delete employee; optionally also delete their app login.
     */
    public function delete(int $businessId, int $id, ?int $actorUserId = null, bool $removeAccount = false): void
    {
        $employee = $this->findOrFail($businessId, $id);
        $userId = $employee->user_id ? (int) $employee->user_id : null;

        DB::transaction(function () use ($businessId, $employee, $id, $actorUserId, $removeAccount, $userId) {
            $employee->user_id = null;
            $employee->save();
            $employee->delete();

            $this->audit->record($businessId, $actorUserId, 'employee.deleted', 'hr_employee', $id);

            if ($removeAccount && $userId && $actorUserId) {
                $this->users->delete($userId, $businessId, $actorUserId);
            }
        });
    }

    protected function assertOrgRefs(int $businessId, array $data): void
    {
        if (! empty($data['department_id'])) {
            $this->org->findDepartmentOrFail($businessId, (int) $data['department_id']);
        }

        if (! empty($data['position_id'])) {
            $this->org->findPositionOrFail($businessId, (int) $data['position_id']);
        }

        if (! empty($data['manager_employee_id'])) {
            $manager = HrEmployee::query()
                ->where('business_id', $businessId)
                ->whereKey((int) $data['manager_employee_id'])
                ->first();

            if (! $manager) {
                throw ValidationException::withMessages([
                    'manager_employee_id' => 'Manager employee not found.',
                ]);
            }
        }
    }

    protected function assertUniqueEmployeeNumber(int $businessId, string $number, ?int $ignoreId = null): void
    {
        $exists = HrEmployee::query()
            ->where('business_id', $businessId)
            ->where('employee_number', $number)
            ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'employee_number' => 'Employee number already exists for this business.',
            ]);
        }
    }

    protected function assertLinkableUser(int $businessId, int $userId, ?int $ignoreEmployeeId = null): void
    {
        $user = User::query()
            ->where('business_id', $businessId)
            ->whereKey($userId)
            ->first();

        if (! $user) {
            throw ValidationException::withMessages([
                'user_id' => 'User not found in this business.',
            ]);
        }

        $taken = HrEmployee::query()
            ->where('business_id', $businessId)
            ->where('user_id', $userId)
            ->when($ignoreEmployeeId, fn ($q) => $q->where('id', '!=', $ignoreEmployeeId))
            ->exists();

        if ($taken) {
            throw ValidationException::withMessages([
                'user_id' => 'User is already linked to another employee.',
            ]);
        }
    }
}
