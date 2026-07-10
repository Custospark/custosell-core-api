<?php

declare(strict_types=1);

namespace App\Services\Hr;

use App\Models\Hr\HrEmployee;
use App\Models\User;

class HrStaffMirrorService
{
    public function __construct(
        protected HrAuditService $audit,
    ) {}

    /**
     * Ensure a business staff user has a linked HR employee. Idempotent.
     */
    public function ensureEmployeeForUser(User $user, ?int $actorUserId = null): HrEmployee
    {
        if (! $user->business_id) {
            throw new \InvalidArgumentException('User must belong to a business to mirror into HR.');
        }

        $existing = HrEmployee::query()
            ->where('business_id', $user->business_id)
            ->where('user_id', $user->id)
            ->first();

        if ($existing) {
            return $existing->load(['department:id,name', 'position:id,title', 'user:id,name,email']);
        }

        [$firstName, $lastName] = $this->splitName((string) $user->name);
        $employeeNumber = $this->uniqueEmployeeNumber((int) $user->business_id, (int) $user->id);

        $employee = HrEmployee::create([
            'business_id' => $user->business_id,
            'user_id' => $user->id,
            'employee_number' => $employeeNumber,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'email' => $user->email,
            'phone' => $user->phone,
            'employment_type' => 'full_time',
            'status' => 'active',
        ]);

        $this->audit->record(
            (int) $user->business_id,
            $actorUserId,
            'employee.mirrored_from_staff',
            'hr_employee',
            $employee->id,
            ['user_id' => $user->id, 'employee_number' => $employeeNumber],
        );

        return $employee->load(['department:id,name', 'position:id,title', 'user:id,name,email']);
    }

    /**
     * Create missing HR employees for all staff in a business. Returns how many were created.
     */
    public function backfillBusiness(int $businessId, ?int $actorUserId = null): int
    {
        $linkedUserIds = HrEmployee::query()
            ->where('business_id', $businessId)
            ->whereNotNull('user_id')
            ->pluck('user_id')
            ->all();

        $users = User::query()
            ->where('business_id', $businessId)
            ->when($linkedUserIds !== [], fn ($q) => $q->whereNotIn('id', $linkedUserIds))
            ->orderBy('id')
            ->get();

        $created = 0;
        foreach ($users as $user) {
            $before = HrEmployee::query()
                ->where('business_id', $businessId)
                ->where('user_id', $user->id)
                ->exists();
            $this->ensureEmployeeForUser($user, $actorUserId);
            if (! $before) {
                $created++;
            }
        }

        return $created;
    }

    /**
     * Soft-sync contact fields from staff user onto the linked HR employee.
     */
    public function syncContactFromUser(User $user): void
    {
        if (! $user->business_id) {
            return;
        }

        $employee = HrEmployee::query()
            ->where('business_id', $user->business_id)
            ->where('user_id', $user->id)
            ->first();

        if (! $employee) {
            return;
        }

        [$firstName, $lastName] = $this->splitName((string) $user->name);
        $employee->fill([
            'first_name' => $firstName,
            'last_name' => $lastName,
            'email' => $user->email,
            'phone' => $user->phone,
        ]);
        $employee->save();
    }

    /**
     * @return array{0: string, 1: string}
     */
    public function splitName(string $name): array
    {
        $trimmed = trim($name);
        if ($trimmed === '') {
            return ['Staff', 'Member'];
        }

        $parts = preg_split('/\s+/', $trimmed, 2) ?: [$trimmed];
        $first = $parts[0];
        $last = $parts[1] ?? $parts[0];

        return [$first, $last];
    }

    protected function uniqueEmployeeNumber(int $businessId, int $userId): string
    {
        $base = 'STF-'.$userId;
        if (! $this->employeeNumberExists($businessId, $base)) {
            return $base;
        }

        $suffix = 1;
        while ($this->employeeNumberExists($businessId, $base.'-'.$suffix)) {
            $suffix++;
        }

        return $base.'-'.$suffix;
    }

    protected function employeeNumberExists(int $businessId, string $number): bool
    {
        return HrEmployee::query()
            ->where('business_id', $businessId)
            ->where('employee_number', $number)
            ->exists();
    }
}
