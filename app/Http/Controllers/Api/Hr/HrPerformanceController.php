<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Hr;

use App\Http\Controllers\Controller;
use App\Services\Hr\HrPerformanceService;
use App\Services\ModuleAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class HrPerformanceController extends Controller
{
    public function __construct(
        protected HrPerformanceService $performance,
        protected ModuleAccessService $moduleAccess,
    ) {}

    /** @return array{0: string, 1: ?string, 2: ?string} */
    protected function periodArgs(Request $request): array
    {
        $validated = $request->validate([
            'period' => ['nullable', 'in:day,week,month,quarter,year,custom'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ]);

        return [
            $validated['period'] ?? 'month',
            $validated['from'] ?? null,
            $validated['to'] ?? null,
        ];
    }

    public function roster(Request $request): JsonResponse
    {
        $user = $request->user();
        $fullHr = $this->moduleAccess->hasFullHrWorkspace($user);
        [$period, $from, $to] = $this->periodArgs($request);

        return response()->json([
            'data' => $this->performance->roster(
                (int) $user->business_id,
                (int) $user->id,
                $fullHr,
                $period,
                $from,
                $to,
            ),
        ]);
    }

    public function showEmployee(Request $request, int $employeeId): JsonResponse
    {
        $user = $request->user();
        $fullHr = $this->moduleAccess->hasFullHrWorkspace($user);
        [$period, $from, $to] = $this->periodArgs($request);

        return response()->json([
            'data' => $this->performance->evaluateEmployee(
                (int) $user->business_id,
                $employeeId,
                (int) $user->id,
                $fullHr,
                $period,
                $from,
                $to,
            ),
        ]);
    }

    public function showByUser(Request $request, int $userId): JsonResponse
    {
        $user = $request->user();
        $fullHr = $this->moduleAccess->hasFullHrWorkspace($user);
        [$period, $from, $to] = $this->periodArgs($request);

        return response()->json([
            'data' => $this->performance->evaluateByUserId(
                (int) $user->business_id,
                $userId,
                (int) $user->id,
                $fullHr,
                $period,
                $from,
                $to,
            ),
        ]);
    }

    public function seedReview(Request $request, int $employeeId): JsonResponse
    {
        $user = $request->user();
        [$period, $from, $to] = $this->periodArgs($request);

        $result = $this->performance->seedReviewFromSnapshot(
            (int) $user->business_id,
            $employeeId,
            (int) $user->id,
            $period,
            $from,
            $to,
        );

        return response()->json(['data' => $result], 201);
    }
}
