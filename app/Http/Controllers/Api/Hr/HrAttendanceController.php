<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Hr;

use App\Http\Controllers\Controller;
use App\Models\Hr\HrEmployee;
use App\Models\User;
use App\Services\Hr\HrAttendanceService;
use App\Services\Hr\HrIntegrationService;
use App\Services\ModuleAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

class HrAttendanceController extends Controller
{
    public function __construct(
        protected HrAttendanceService $attendance,
        protected HrIntegrationService $integrations,
        protected ModuleAccessService $moduleAccess,
    ) {}

    public function clock(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'employee_id' => ['required', 'integer'],
            'type' => ['required', 'in:clock_in,clock_out,break_start,break_end'],
            'occurred_at' => ['nullable', 'date'],
            'source' => ['nullable', 'string', 'max:64'],
            'note' => ['nullable', 'string'],
        ]);

        $user = $request->user();
        $employeeId = $this->resolveSelfServiceEmployeeId($user, (int) $validated['employee_id']);

        $event = $this->attendance->clock(
            (int) $user->business_id,
            $employeeId,
            $validated['type'],
            $validated['occurred_at'] ?? null,
            $validated['source'] ?? null,
            $validated['note'] ?? null,
            $user->id,
        );

        return response()->json(['data' => $event], 201);
    }

    public function events(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'employee_id' => ['nullable', 'integer'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
        ]);

        return response()->json([
            'data' => $this->attendance->listEvents((int) $request->user()->business_id, $validated),
        ]);
    }

    public function register(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'employee_id' => ['nullable', 'integer'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'status' => ['nullable', 'in:present,absent,leave,holiday'],
        ]);

        return response()->json([
            'data' => $this->attendance->listRegister((int) $request->user()->business_id, $validated),
        ]);
    }

    public function correctDay(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'employee_id' => ['required', 'integer'],
            'work_date' => ['required', 'date'],
            'status' => ['required', 'in:present,absent,leave,holiday'],
            'minutes_worked' => ['nullable', 'integer', 'min:0'],
            'notes' => ['nullable', 'string'],
        ]);

        $day = $this->attendance->correctDay(
            (int) $request->user()->business_id,
            (int) $validated['employee_id'],
            $validated['work_date'],
            $validated,
            $request->user()->id,
        );

        return response()->json(['data' => $day]);
    }

    public function importTimesheets(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'date_from' => ['required', 'date'],
            'date_to' => ['required', 'date'],
            'employee_id' => ['nullable', 'integer'],
        ]);

        $result = $this->integrations->importApprovedTimesheets(
            (int) $request->user()->business_id,
            $validated['date_from'],
            $validated['date_to'],
            isset($validated['employee_id']) ? (int) $validated['employee_id'] : null,
            $request->user()->id,
        );

        return response()->json(['data' => $result]);
    }

    public function posShifts(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'work_date' => ['nullable', 'date'],
            'employee_id' => ['nullable', 'integer'],
        ]);

        return response()->json([
            'data' => $this->integrations->listPosShifts(
                (int) $request->user()->business_id,
                $validated['work_date'] ?? null,
                isset($validated['employee_id']) ? (int) $validated['employee_id'] : null,
            ),
        ]);
    }

    /**
     * Limited HR users may only clock for their linked employee record.
     */
    protected function resolveSelfServiceEmployeeId(User $user, int $requestedEmployeeId): int
    {
        if ($this->moduleAccess->hasFullHrWorkspace($user)) {
            return $requestedEmployeeId;
        }

        $linked = HrEmployee::query()
            ->where('business_id', $user->business_id)
            ->where('user_id', $user->id)
            ->first();

        if (! $linked) {
            throw new HttpException(403, 'No HR employee record is linked to your account.');
        }

        if ((int) $linked->id !== $requestedEmployeeId) {
            throw new HttpException(403, 'You can only clock attendance for your own employee record.');
        }

        return (int) $linked->id;
    }
}
