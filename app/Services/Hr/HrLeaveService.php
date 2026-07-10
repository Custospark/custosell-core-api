<?php

declare(strict_types=1);

namespace App\Services\Hr;

use App\Models\Hr\HrLeaveBalance;
use App\Models\Hr\HrLeaveRequest;
use App\Models\Hr\HrLeaveType;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class HrLeaveService
{
    public const REQUEST_STATUSES = ['pending', 'approved', 'rejected', 'cancelled'];

    public function __construct(
        protected HrEmployeeService $employees,
        protected HrAuditService $audit,
    ) {}

    public function listTypes(int $businessId): Collection
    {
        return HrLeaveType::query()
            ->where('business_id', $businessId)
            ->orderBy('name')
            ->get();
    }

    public function createType(int $businessId, array $data, ?int $actorUserId = null): HrLeaveType
    {
        $type = HrLeaveType::create([
            'business_id' => $businessId,
            'name' => $data['name'],
            'code' => strtoupper($data['code']),
            'paid' => (bool) ($data['paid'] ?? true),
            'days_per_year' => $data['days_per_year'] ?? 0,
            'requires_approval' => (bool) ($data['requires_approval'] ?? true),
        ]);

        $this->audit->record($businessId, $actorUserId, 'leave_type.created', 'hr_leave_type', $type->id);

        return $type;
    }

    public function updateType(int $businessId, int $id, array $data, ?int $actorUserId = null): HrLeaveType
    {
        $type = $this->findTypeOrFail($businessId, $id);

        if (isset($data['code'])) {
            $data['code'] = strtoupper($data['code']);
        }

        $type->fill(array_intersect_key($data, array_flip([
            'name', 'code', 'paid', 'days_per_year', 'requires_approval',
        ])));
        $type->save();

        $this->audit->record($businessId, $actorUserId, 'leave_type.updated', 'hr_leave_type', $type->id);

        return $type->fresh();
    }

    public function deleteType(int $businessId, int $id, ?int $actorUserId = null): void
    {
        $type = $this->findTypeOrFail($businessId, $id);
        $type->delete();
        $this->audit->record($businessId, $actorUserId, 'leave_type.deleted', 'hr_leave_type', $id);
    }

    public function findTypeOrFail(int $businessId, int $id): HrLeaveType
    {
        $type = HrLeaveType::query()
            ->where('business_id', $businessId)
            ->whereKey($id)
            ->first();

        if (! $type) {
            abort(404, 'Leave type not found');
        }

        return $type;
    }

    public function ensureBalance(int $businessId, int $employeeId, int $leaveTypeId, ?int $year = null): HrLeaveBalance
    {
        $this->employees->findOrFail($businessId, $employeeId);
        $type = $this->findTypeOrFail($businessId, $leaveTypeId);
        $year = $year ?? (int) now()->year;

        return HrLeaveBalance::query()->firstOrCreate(
            [
                'employee_id' => $employeeId,
                'leave_type_id' => $leaveTypeId,
                'year' => $year,
            ],
            [
                'business_id' => $businessId,
                'entitled' => $type->days_per_year,
                'used' => 0,
                'pending' => 0,
            ],
        );
    }

    public function listBalances(int $businessId, ?int $employeeId = null, ?int $year = null): Collection
    {
        $query = HrLeaveBalance::query()
            ->where('business_id', $businessId)
            ->with(['leaveType:id,name,code', 'employee:id,first_name,last_name,employee_number']);

        if ($employeeId !== null) {
            $query->where('employee_id', $employeeId);
        }

        if ($year !== null) {
            $query->where('year', $year);
        }

        return $query->get();
    }

    public function requestLeave(int $businessId, array $data, ?int $actorUserId = null): HrLeaveRequest
    {
        $employee = $this->employees->findOrFail($businessId, (int) $data['employee_id']);
        $type = $this->findTypeOrFail($businessId, (int) $data['leave_type_id']);

        $start = Carbon::parse($data['start_date'])->startOfDay();
        $end = Carbon::parse($data['end_date'])->startOfDay();

        if ($end->lt($start)) {
            throw ValidationException::withMessages([
                'end_date' => 'End date must be on or after start date.',
            ]);
        }

        $days = isset($data['days'])
            ? (float) $data['days']
            : (float) ($start->diffInDays($end) + 1);

        if ($days <= 0) {
            throw ValidationException::withMessages([
                'days' => 'Leave days must be greater than zero.',
            ]);
        }

        return DB::transaction(function () use ($businessId, $employee, $type, $start, $end, $days, $data, $actorUserId) {
            $balance = $this->ensureBalance($businessId, $employee->id, $type->id, (int) $start->year);
            $available = (float) $balance->entitled - (float) $balance->used - (float) $balance->pending;

            if ($days > $available + 0.001) {
                throw ValidationException::withMessages([
                    'days' => "Insufficient leave balance. Available: {$available}.",
                ]);
            }

            $status = $type->requires_approval ? 'pending' : 'approved';

            $request = HrLeaveRequest::create([
                'business_id' => $businessId,
                'employee_id' => $employee->id,
                'leave_type_id' => $type->id,
                'start_date' => $start->toDateString(),
                'end_date' => $end->toDateString(),
                'days' => $days,
                'status' => $status,
                'reason' => $data['reason'] ?? null,
                'reviewer_id' => $status === 'approved' ? $actorUserId : null,
                'reviewed_at' => $status === 'approved' ? now() : null,
            ]);

            if ($status === 'pending') {
                $balance->pending = (float) $balance->pending + $days;
            } else {
                $balance->used = (float) $balance->used + $days;
            }
            $balance->save();

            $this->audit->record($businessId, $actorUserId, 'leave.requested', 'hr_leave_request', $request->id, [
                'status' => $status,
                'days' => $days,
            ]);

            return $request->load(['leaveType', 'employee:id,first_name,last_name,employee_number']);
        });
    }

    public function listRequests(int $businessId, array $filters = []): Collection
    {
        $query = HrLeaveRequest::query()
            ->where('business_id', $businessId)
            ->with([
                'leaveType:id,name,code',
                'employee:id,first_name,last_name,employee_number',
                'reviewer:id,name',
            ])
            ->orderByDesc('created_at');

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['employee_id'])) {
            $query->where('employee_id', (int) $filters['employee_id']);
        }

        return $query->limit(200)->get();
    }

    public function findRequestOrFail(int $businessId, int $id): HrLeaveRequest
    {
        $request = HrLeaveRequest::query()
            ->where('business_id', $businessId)
            ->with(['leaveType', 'employee', 'reviewer:id,name'])
            ->whereKey($id)
            ->first();

        if (! $request) {
            abort(404, 'Leave request not found');
        }

        return $request;
    }

    public function approve(int $businessId, int $id, int $reviewerId, ?string $note = null): HrLeaveRequest
    {
        return $this->review($businessId, $id, $reviewerId, 'approved', $note);
    }

    public function reject(int $businessId, int $id, int $reviewerId, ?string $note = null): HrLeaveRequest
    {
        return $this->review($businessId, $id, $reviewerId, 'rejected', $note);
    }

    /**
     * Cancel a pending or approved leave request.
     * Limited HR (no hr_full) may only cancel when the request's employee is linked to $actorUserId.
     * Full HR may cancel any request in the business.
     */
    public function cancel(int $businessId, int $id, ?int $actorUserId = null, bool $isFullHr = false): HrLeaveRequest
    {
        return DB::transaction(function () use ($businessId, $id, $actorUserId, $isFullHr) {
            $request = $this->findRequestOrFail($businessId, $id);

            if (! $isFullHr) {
                $request->loadMissing('employee');
                if (! $actorUserId || (int) ($request->employee?->user_id) !== (int) $actorUserId) {
                    abort(403, 'You can only cancel your own leave requests.');
                }
            }

            if (! in_array($request->status, ['pending', 'approved'], true)) {
                throw ValidationException::withMessages([
                    'status' => 'Only pending or approved leave can be cancelled.',
                ]);
            }

            $balance = $this->ensureBalance(
                $businessId,
                $request->employee_id,
                $request->leave_type_id,
                (int) Carbon::parse($request->start_date)->year,
            );

            if ($request->status === 'pending') {
                $balance->pending = max(0, (float) $balance->pending - (float) $request->days);
            } else {
                $balance->used = max(0, (float) $balance->used - (float) $request->days);
            }
            $balance->save();

            $request->status = 'cancelled';
            $request->save();

            $this->audit->record($businessId, $actorUserId, 'leave.cancelled', 'hr_leave_request', $request->id);

            return $request->fresh(['leaveType', 'employee']);
        });
    }

    protected function review(
        int $businessId,
        int $id,
        int $reviewerId,
        string $status,
        ?string $note,
    ): HrLeaveRequest {
        return DB::transaction(function () use ($businessId, $id, $reviewerId, $status, $note) {
            $request = $this->findRequestOrFail($businessId, $id);

            if ($request->status !== 'pending') {
                throw ValidationException::withMessages([
                    'status' => 'Only pending leave requests can be reviewed.',
                ]);
            }

            $balance = $this->ensureBalance(
                $businessId,
                $request->employee_id,
                $request->leave_type_id,
                (int) Carbon::parse($request->start_date)->year,
            );

            $balance->pending = max(0, (float) $balance->pending - (float) $request->days);

            if ($status === 'approved') {
                $balance->used = (float) $balance->used + (float) $request->days;
            }

            $balance->save();

            $request->update([
                'status' => $status,
                'reviewer_id' => $reviewerId,
                'reviewed_at' => now(),
                'review_note' => $note,
            ]);

            $this->audit->record($businessId, $reviewerId, "leave.{$status}", 'hr_leave_request', $request->id);

            return $request->fresh(['leaveType', 'employee', 'reviewer:id,name']);
        });
    }
}
