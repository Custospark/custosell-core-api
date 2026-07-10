<?php

declare(strict_types=1);

namespace App\Services\Hr;

use App\Models\Hr\HrAttendanceDay;
use App\Models\Hr\HrAttendanceEvent;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class HrAttendanceService
{
    public const EVENT_TYPES = ['clock_in', 'clock_out', 'break_start', 'break_end'];

    public const DAY_STATUSES = ['present', 'absent', 'leave', 'holiday'];

    public function __construct(
        protected HrEmployeeService $employees,
        protected HrAuditService $audit,
    ) {}

    public function clock(
        int $businessId,
        int $employeeId,
        string $type,
        ?string $occurredAt = null,
        ?string $source = null,
        ?string $note = null,
        ?int $actorUserId = null,
    ): HrAttendanceEvent {
        if (! in_array($type, self::EVENT_TYPES, true)) {
            throw ValidationException::withMessages([
                'type' => 'Invalid attendance event type.',
            ]);
        }

        $this->employees->findOrFail($businessId, $employeeId);

        $at = $occurredAt ? Carbon::parse($occurredAt) : now();

        return DB::transaction(function () use ($businessId, $employeeId, $type, $at, $source, $note, $actorUserId) {
            $event = HrAttendanceEvent::create([
                'business_id' => $businessId,
                'employee_id' => $employeeId,
                'type' => $type,
                'occurred_at' => $at,
                'source' => $source ?? 'api',
                'note' => $note,
            ]);

            $this->recomputeDay($businessId, $employeeId, $at->toDateString());

            $this->audit->record($businessId, $actorUserId, 'attendance.clock', 'hr_attendance_event', $event->id, [
                'employee_id' => $employeeId,
                'type' => $type,
            ]);

            return $event;
        });
    }

    public function listEvents(int $businessId, array $filters = []): Collection
    {
        $query = HrAttendanceEvent::query()
            ->where('business_id', $businessId)
            ->with('employee:id,first_name,last_name,employee_number')
            ->orderByDesc('occurred_at');

        if (! empty($filters['employee_id'])) {
            $query->where('employee_id', (int) $filters['employee_id']);
        }

        if (! empty($filters['date_from'])) {
            $query->where('occurred_at', '>=', Carbon::parse($filters['date_from'])->startOfDay());
        }

        if (! empty($filters['date_to'])) {
            $query->where('occurred_at', '<=', Carbon::parse($filters['date_to'])->endOfDay());
        }

        return $query->limit(500)->get();
    }

    public function listRegister(int $businessId, array $filters = []): Collection
    {
        $query = HrAttendanceDay::query()
            ->where('business_id', $businessId)
            ->with('employee:id,first_name,last_name,employee_number')
            ->orderByDesc('work_date');

        if (! empty($filters['employee_id'])) {
            $query->where('employee_id', (int) $filters['employee_id']);
        }

        if (! empty($filters['date_from'])) {
            $query->whereDate('work_date', '>=', Carbon::parse($filters['date_from'])->toDateString());
        }

        if (! empty($filters['date_to'])) {
            $query->whereDate('work_date', '<=', Carbon::parse($filters['date_to'])->toDateString());
        }

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        return $query->limit(500)->get();
    }

    public function correctDay(
        int $businessId,
        int $employeeId,
        string $workDate,
        array $data,
        ?int $actorUserId = null,
    ): HrAttendanceDay {
        $this->employees->findOrFail($businessId, $employeeId);

        if (isset($data['status']) && ! in_array($data['status'], self::DAY_STATUSES, true)) {
            throw ValidationException::withMessages([
                'status' => 'Invalid attendance day status.',
            ]);
        }

        $day = $this->upsertDay($businessId, $employeeId, $workDate, [
            'status' => $data['status'] ?? 'present',
            'minutes_worked' => (int) ($data['minutes_worked'] ?? 0),
            'notes' => $data['notes'] ?? null,
        ]);

        $this->audit->record($businessId, $actorUserId, 'attendance.day_corrected', 'hr_attendance_day', $day->id, [
            'employee_id' => $employeeId,
            'work_date' => $workDate,
        ]);

        return $day->load('employee:id,first_name,last_name,employee_number');
    }

    protected function recomputeDay(int $businessId, int $employeeId, string $workDate): void
    {
        $events = HrAttendanceEvent::query()
            ->where('business_id', $businessId)
            ->where('employee_id', $employeeId)
            ->whereDate('occurred_at', $workDate)
            ->orderBy('occurred_at')
            ->get();

        $minutes = 0;
        $clockIn = null;

        foreach ($events as $event) {
            if ($event->type === 'clock_in') {
                $clockIn = Carbon::parse($event->occurred_at);
            } elseif ($event->type === 'clock_out' && $clockIn) {
                $minutes += (int) max(0, $clockIn->diffInMinutes(Carbon::parse($event->occurred_at)));
                $clockIn = null;
            }
        }

        $status = $events->isEmpty() ? 'absent' : 'present';

        $this->upsertDay($businessId, $employeeId, $workDate, [
            'status' => $status,
            'minutes_worked' => $minutes,
        ]);
    }

    /** @param  array{status?: string, minutes_worked?: int, notes?: string|null}  $attributes */
    protected function upsertDay(int $businessId, int $employeeId, string $workDate, array $attributes): HrAttendanceDay
    {
        $date = Carbon::parse($workDate)->toDateString();

        $day = HrAttendanceDay::query()
            ->where('business_id', $businessId)
            ->where('employee_id', $employeeId)
            ->whereDate('work_date', $date)
            ->first();

        if ($day) {
            $day->fill($attributes);
            $day->save();

            return $day;
        }

        return HrAttendanceDay::create([
            'business_id' => $businessId,
            'employee_id' => $employeeId,
            'work_date' => $date,
            'status' => $attributes['status'] ?? 'present',
            'minutes_worked' => $attributes['minutes_worked'] ?? 0,
            'notes' => $attributes['notes'] ?? null,
        ]);
    }
}
