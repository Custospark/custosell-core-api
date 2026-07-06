<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreProjectRequest;
use App\Http\Requests\StoreTimesheetEntryRequest;
use App\Http\Resources\ProjectCostAllocationResource;
use App\Http\Resources\ProjectResource;
use App\Http\Resources\ProjectTaskResource;
use App\Http\Resources\TimesheetEntryResource;
use App\Services\Contracts\ProjectServiceInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ProjectController extends Controller
{
    public function __construct(
        protected ProjectServiceInterface $projectService,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->only(['status', 'customer_id']);

        return ProjectResource::collection(
            $this->projectService->getAll($request->user()->business_id, $filters)
        );
    }

    public function show(int $id): ProjectResource
    {
        $project = $this->projectService->getById($id);
        if (!$project) {
            abort(404, 'Project not found');
        }

        return new ProjectResource($project);
    }

    public function store(StoreProjectRequest $request): JsonResponse
    {
        $project = $this->projectService->create(
            $request->user()->business_id,
            $request->user()->id,
            $request->validated(),
        );

        return response()->json(new ProjectResource($project), 201);
    }

    public function update(StoreProjectRequest $request, int $id): ProjectResource
    {
        $project = $this->projectService->update($id, $request->validated());

        return new ProjectResource($project);
    }

    public function destroy(int $id): JsonResponse
    {
        $this->projectService->delete($id);

        return response()->json(null, 204);
    }

    public function storeTask(Request $request, int $projectId): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'status' => ['nullable', 'string', 'in:todo,in_progress,done,cancelled'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'estimated_hours' => ['nullable', 'numeric', 'min:0'],
            'budget_cost' => ['nullable', 'numeric', 'min:0'],
            'due_date' => ['nullable', 'date'],
            'assigned_to' => ['nullable', 'integer', 'exists:users,id'],
        ]);

        try {
            $task = $this->projectService->createTask($projectId, $data);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 404);
        }

        return response()->json(new ProjectTaskResource($task), 201);
    }

    public function updateTask(Request $request, int $taskId): ProjectTaskResource
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'status' => ['nullable', 'string', 'in:todo,in_progress,done,cancelled'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'estimated_hours' => ['nullable', 'numeric', 'min:0'],
            'actual_hours' => ['nullable', 'numeric', 'min:0'],
            'budget_cost' => ['nullable', 'numeric', 'min:0'],
            'due_date' => ['nullable', 'date'],
            'assigned_to' => ['nullable', 'integer', 'exists:users,id'],
        ]);

        try {
            $task = $this->projectService->updateTask($taskId, $data);
        } catch (\RuntimeException $e) {
            abort(404, $e->getMessage());
        }

        return new ProjectTaskResource($task);
    }

    public function destroyTask(int $taskId): JsonResponse
    {
        try {
            $this->projectService->deleteTask($taskId);
        } catch (\RuntimeException $e) {
            abort(404, $e->getMessage());
        }

        return response()->json(null, 204);
    }

    public function storeTimesheet(StoreTimesheetEntryRequest $request, int $projectId): JsonResponse
    {
        try {
            $entry = $this->projectService->createTimesheet(
                $request->user()->business_id,
                $request->user()->id,
                $projectId,
                $request->validated(),
            );
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 404);
        }

        return response()->json(new TimesheetEntryResource($entry), 201);
    }

    public function updateTimesheet(StoreTimesheetEntryRequest $request, int $entryId): TimesheetEntryResource
    {
        try {
            $entry = $this->projectService->updateTimesheet($entryId, $request->validated());
        } catch (\RuntimeException $e) {
            abort(404, $e->getMessage());
        }

        return new TimesheetEntryResource($entry);
    }

    public function destroyTimesheet(int $entryId): JsonResponse
    {
        try {
            $this->projectService->deleteTimesheet($entryId);
        } catch (\RuntimeException $e) {
            abort(404, $e->getMessage());
        }

        return response()->json(null, 204);
    }

    public function storeAllocation(Request $request, int $projectId): JsonResponse
    {
        $data = $request->validate([
            'allocation_type' => ['required', 'string', 'in:labor,material,overhead,expense,other'],
            'description' => ['required', 'string', 'max:500'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'basis' => ['nullable', 'string', 'in:fixed,percent,hours'],
            'basis_value' => ['nullable', 'numeric', 'min:0'],
            'allocation_date' => ['required', 'date'],
            'expense_id' => ['nullable', 'integer', 'exists:expenses,id'],
        ]);

        try {
            $allocation = $this->projectService->createAllocation(
                $request->user()->business_id,
                $request->user()->id,
                $projectId,
                $data,
            );
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 404);
        }

        return response()->json(new ProjectCostAllocationResource($allocation), 201);
    }

    public function updateAllocation(Request $request, int $allocationId): ProjectCostAllocationResource
    {
        $data = $request->validate([
            'allocation_type' => ['sometimes', 'string', 'in:labor,material,overhead,expense,other'],
            'description' => ['sometimes', 'string', 'max:500'],
            'amount' => ['sometimes', 'numeric', 'min:0.01'],
            'basis' => ['nullable', 'string', 'in:fixed,percent,hours'],
            'basis_value' => ['nullable', 'numeric', 'min:0'],
            'allocation_date' => ['sometimes', 'date'],
            'expense_id' => ['nullable', 'integer', 'exists:expenses,id'],
        ]);

        try {
            $allocation = $this->projectService->updateAllocation($allocationId, $data);
        } catch (\RuntimeException $e) {
            abort(404, $e->getMessage());
        }

        return new ProjectCostAllocationResource($allocation);
    }

    public function destroyAllocation(int $allocationId): JsonResponse
    {
        try {
            $this->projectService->deleteAllocation($allocationId);
        } catch (\RuntimeException $e) {
            abort(404, $e->getMessage());
        }

        return response()->json(null, 204);
    }

    public function budgetSummary(int $id): JsonResponse
    {
        try {
            $summary = $this->projectService->budgetSummary($id);
        } catch (\RuntimeException $e) {
            abort(404, $e->getMessage());
        }

        return response()->json(['data' => $summary]);
    }

    public function profitability(int $id): JsonResponse
    {
        try {
            $data = $this->projectService->profitability($id);
        } catch (\RuntimeException $e) {
            abort(404, $e->getMessage());
        }

        return response()->json(['data' => $data]);
    }
}
