<?php

declare(strict_types=1);

namespace App\Services\Hr;

use App\Models\Hr\HrDepartment;
use App\Models\Hr\HrPosition;
use Illuminate\Database\Eloquent\Collection;

class HrOrgService
{
    public function __construct(
        protected HrAuditService $audit,
    ) {}

    public function listDepartments(int $businessId): Collection
    {
        return HrDepartment::query()
            ->where('business_id', $businessId)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();
    }

    public function createDepartment(int $businessId, array $data, ?int $actorUserId = null): HrDepartment
    {
        $department = HrDepartment::create([
            'business_id' => $businessId,
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'sort_order' => (int) ($data['sort_order'] ?? 0),
        ]);

        $this->audit->record($businessId, $actorUserId, 'department.created', 'hr_department', $department->id, [
            'name' => $department->name,
        ]);

        return $department;
    }

    public function updateDepartment(int $businessId, int $id, array $data, ?int $actorUserId = null): HrDepartment
    {
        $department = $this->findDepartmentOrFail($businessId, $id);
        $department->fill(array_intersect_key($data, array_flip(['name', 'description', 'sort_order'])));
        $department->save();

        $this->audit->record($businessId, $actorUserId, 'department.updated', 'hr_department', $department->id);

        return $department->fresh();
    }

    public function deleteDepartment(int $businessId, int $id, ?int $actorUserId = null): void
    {
        $department = $this->findDepartmentOrFail($businessId, $id);
        $department->delete();

        $this->audit->record($businessId, $actorUserId, 'department.deleted', 'hr_department', $id);
    }

    public function findDepartmentOrFail(int $businessId, int $id): HrDepartment
    {
        $department = HrDepartment::query()
            ->where('business_id', $businessId)
            ->whereKey($id)
            ->first();

        if (! $department) {
            abort(404, 'Department not found');
        }

        return $department;
    }

    public function listPositions(int $businessId, ?int $departmentId = null): Collection
    {
        $query = HrPosition::query()
            ->where('business_id', $businessId)
            ->with('department:id,name')
            ->orderBy('title');

        if ($departmentId !== null) {
            $query->where('department_id', $departmentId);
        }

        return $query->get();
    }

    public function createPosition(int $businessId, array $data, ?int $actorUserId = null): HrPosition
    {
        if (! empty($data['department_id'])) {
            $this->findDepartmentOrFail($businessId, (int) $data['department_id']);
        }

        $position = HrPosition::create([
            'business_id' => $businessId,
            'department_id' => $data['department_id'] ?? null,
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
        ]);

        $this->audit->record($businessId, $actorUserId, 'position.created', 'hr_position', $position->id, [
            'title' => $position->title,
        ]);

        return $position->load('department:id,name');
    }

    public function updatePosition(int $businessId, int $id, array $data, ?int $actorUserId = null): HrPosition
    {
        $position = $this->findPositionOrFail($businessId, $id);

        if (array_key_exists('department_id', $data) && $data['department_id'] !== null) {
            $this->findDepartmentOrFail($businessId, (int) $data['department_id']);
        }

        $position->fill(array_intersect_key($data, array_flip(['department_id', 'title', 'description'])));
        $position->save();

        $this->audit->record($businessId, $actorUserId, 'position.updated', 'hr_position', $position->id);

        return $position->fresh('department:id,name');
    }

    public function deletePosition(int $businessId, int $id, ?int $actorUserId = null): void
    {
        $position = $this->findPositionOrFail($businessId, $id);
        $position->delete();

        $this->audit->record($businessId, $actorUserId, 'position.deleted', 'hr_position', $id);
    }

    public function findPositionOrFail(int $businessId, int $id): HrPosition
    {
        $position = HrPosition::query()
            ->where('business_id', $businessId)
            ->whereKey($id)
            ->first();

        if (! $position) {
            abort(404, 'Position not found');
        }

        return $position;
    }
}
