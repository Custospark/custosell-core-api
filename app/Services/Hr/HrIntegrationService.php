<?php

declare(strict_types=1);

namespace App\Services\Hr;

use App\Models\Hr\HrEmployee;
use App\Models\Shift;
use App\Models\TimesheetEntry;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class HrIntegrationService
{
    public function __construct(
        protected HrAttendanceService $attendance,
        protected HrEmployeeService $employees,
        protected HrAuditService $audit,
    ) {}

    /**
     * Import approved project timesheets into HR attendance days (minutes_worked).
     * Timesheets remain the source of truth for project costing — this only mirrors hours for HR review.
     *
     * @return array{imported: int, skipped: int}
     */
    public function importApprovedTimesheets(
        int $businessId,
        string $dateFrom,
        string $dateTo,
        ?int $employeeId = null,
        ?int $actorUserId = null,
    ): array {
        $from = Carbon::parse($dateFrom)->toDateString();
        $to = Carbon::parse($dateTo)->toDateString();

        if ($from > $to) {
            throw ValidationException::withMessages([
                'date_from' => 'Start date must be on or before end date.',
            ]);
        }

        $employeesQuery = HrEmployee::query()
            ->where('business_id', $businessId)
            ->whereNotNull('user_id');

        if ($employeeId) {
            $employeesQuery->whereKey($employeeId);
        }

        $employees = $employeesQuery->get(['id', 'user_id']);
        $userToEmployee = $employees->keyBy('user_id');

        if ($userToEmployee->isEmpty()) {
            return ['imported' => 0, 'skipped' => 0];
        }

        $entries = TimesheetEntry::query()
            ->where('business_id', $businessId)
            ->where('status', 'approved')
            ->whereIn('user_id', $userToEmployee->keys())
            ->whereDate('entry_date', '>=', $from)
            ->whereDate('entry_date', '<=', $to)
            ->get();

        $grouped = $entries->groupBy(fn (TimesheetEntry $entry) => $entry->user_id.'|'.Carbon::parse($entry->entry_date)->toDateString());

        $imported = 0;
        $skipped = 0;

        foreach ($grouped as $key => $dayEntries) {
            [$userId, $workDate] = explode('|', (string) $key, 2);
            $employee = $userToEmployee->get((int) $userId);
            if (! $employee) {
                $skipped++;
                continue;
            }

            $minutes = (int) round((float) $dayEntries->sum('hours') * 60);

            $this->attendance->correctDay(
                $businessId,
                (int) $employee->id,
                $workDate,
                [
                    'status' => $minutes > 0 ? 'present' : 'absent',
                    'minutes_worked' => $minutes,
                    'notes' => 'Imported from approved project timesheets',
                ],
                $actorUserId,
            );
            $imported++;
        }

        $this->audit->record($businessId, $actorUserId, 'attendance.import_timesheets', 'hr_attendance', null, [
            'date_from' => $from,
            'date_to' => $to,
            'imported' => $imported,
            'skipped' => $skipped,
        ]);

        return ['imported' => $imported, 'skipped' => $skipped];
    }

    /**
     * Read-only POS shifts for linked employees (sales floor clock ≠ HR attendance).
     *
     * @return list<array<string, mixed>>
     */
    public function listPosShifts(int $businessId, ?string $workDate = null, ?int $employeeId = null): array
    {
        $employeesQuery = HrEmployee::query()
            ->where('business_id', $businessId)
            ->whereNotNull('user_id');

        if ($employeeId) {
            $employeesQuery->whereKey($employeeId);
        }

        /** @var Collection<int, HrEmployee> $employees */
        $employees = $employeesQuery->get(['id', 'user_id', 'first_name', 'last_name']);
        $byUser = $employees->keyBy('user_id');

        if ($byUser->isEmpty()) {
            return [];
        }

        $query = Shift::query()
            ->where('business_id', $businessId)
            ->whereIn('user_id', $byUser->keys())
            ->orderByDesc('clock_in');

        if ($workDate) {
            $query->whereDate('clock_in', Carbon::parse($workDate)->toDateString());
        }

        return $query->limit(200)->get()->map(function (Shift $shift) use ($byUser) {
            $employee = $byUser->get((int) $shift->user_id);

            return [
                'id' => $shift->id,
                'user_id' => (int) $shift->user_id,
                'employee_id' => $employee?->id,
                'employee_name' => $employee
                    ? trim($employee->first_name.' '.$employee->last_name)
                    : null,
                'clock_in' => $shift->clock_in?->toISOString(),
                'clock_out' => $shift->clock_out?->toISOString(),
                'status' => $shift->status,
                'total_sales' => (float) $shift->total_sales,
            ];
        })->values()->all();
    }
}
