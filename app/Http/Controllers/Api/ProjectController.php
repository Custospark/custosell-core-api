<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreProjectRequest;
use App\Http\Requests\StoreTimesheetEntryRequest;
use App\Http\Resources\ProjectCostAllocationResource;
use App\Http\Resources\ProjectMemberResource;
use App\Http\Resources\ProjectResource;
use App\Http\Resources\ProjectTaskResource;
use App\Http\Resources\TimesheetEntryResource;
use App\Services\Contracts\ProjectServiceInterface;
use App\Services\ProjectAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ProjectController extends Controller
{
    public function __construct(
        protected ProjectServiceInterface $projectService,
        protected ProjectAccessService $projectAccess,
    ) {}

    public function myProjects(Request $request): AnonymousResourceCollection
    {
        $user = $request->user();
        $projects = $this->projectAccess->canViewAllProjects($user)
            ? $this->projectService->getAll((int) $user->business_id)
            : $this->projectService->getMemberProjects((int) $user->business_id, (int) $user->id);

        return ProjectResource::collection($projects);
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $user = $request->user();
        $filters = $request->only(['status', 'customer_id']);

        $projects = $this->projectAccess->canViewAllProjects($user)
            ? $this->projectService->getAll((int) $user->business_id, $filters)
            : $this->projectService->getMemberProjects((int) $user->business_id, (int) $user->id);

        return ProjectResource::collection($projects);
    }

    public function show(Request $request, int $id): ProjectResource
    {
        $project = $this->projectService->getById($id);
        if (!$project) {
            abort(404, 'Project not found');
        }

        $this->projectAccess->assertCanAccessProject($request->user(), $project);

        return new ProjectResource($project->load(['members.user']));
    }

    public function store(StoreProjectRequest $request): JsonResponse
    {
        if (!$this->projectAccess->canViewAllProjects($request->user())) {
            abort(403, 'You do not have permission to create projects.');
        }

        $project = $this->projectService->create(
            $request->user()->business_id,
            $request->user()->id,
            $request->validated(),
        );

        return response()->json(new ProjectResource($project), 201);
    }

    public function update(StoreProjectRequest $request, int $id): ProjectResource
    {
        $project = $this->projectService->getById($id);
        if (!$project) {
            abort(404, 'Project not found');
        }

        if (!$this->projectAccess->canViewAllProjects($request->user())) {
            abort(403, 'You do not have permission to update projects.');
        }

        $project = $this->projectService->update($id, $request->validated());

        return new ProjectResource($project);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        if (!$this->projectAccess->canViewAllProjects($request->user())) {
            abort(403, 'You do not have permission to delete projects.');
        }

        $this->projectService->delete($id);

        return response()->json(null, 204);
    }

    public function storeTask(Request $request, int $projectId): JsonResponse
    {
        $project = $this->projectService->getById($projectId);
        if (!$project) {
            return response()->json(['message' => 'Project not found'], 404);
        }

        $this->projectAccess->assertCanAccessProject($request->user(), $project);
        if (!$this->projectAccess->canViewAllProjects($request->user())
            && !$this->projectAccess->isProjectMember($request->user(), $projectId, 'contributor')) {
            abort(403, 'You cannot add tasks to this project.');
        }

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
        $project = $this->projectService->getById($projectId);
        if (!$project) {
            return response()->json(['message' => 'Project not found'], 404);
        }

        $this->projectAccess->assertCanAccessProject($request->user(), $project);
        $this->projectAccess->assertCanViewProjectCosting($request->user());

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

    public function budgetSummary(Request $request, int $id): JsonResponse
    {
        $project = $this->projectService->getById($id);
        if (!$project) {
            abort(404, 'Project not found');
        }

        $this->projectAccess->assertCanAccessProject($request->user(), $project);
        $this->projectAccess->assertCanViewProjectCosting($request->user());

        try {
            $summary = $this->projectService->budgetSummary($id);
        } catch (\RuntimeException $e) {
            abort(404, $e->getMessage());
        }

        return response()->json(['data' => $summary]);
    }

    public function profitability(Request $request, int $id): JsonResponse
    {
        $project = $this->projectService->getById($id);
        if (!$project) {
            abort(404, 'Project not found');
        }

        $this->projectAccess->assertCanAccessProject($request->user(), $project);
        $this->projectAccess->assertCanViewProjectCosting($request->user());

        try {
            $data = $this->projectService->profitability($id);
        } catch (\RuntimeException $e) {
            abort(404, $e->getMessage());
        }

        return response()->json(['data' => $data]);
    }

    public function members(Request $request, int $id): JsonResponse
    {
        $project = $this->projectService->getById($id);
        if (!$project) {
            abort(404, 'Project not found');
        }

        $this->projectAccess->assertCanAccessProject($request->user(), $project);

        $members = $this->projectService->listMembers($id);

        return response()->json([
            'data' => ProjectMemberResource::collection($members),
        ]);
    }

    public function storeMember(Request $request, int $id): JsonResponse
    {
        $project = $this->projectService->getById($id);
        if (!$project) {
            abort(404, 'Project not found');
        }

        $this->projectAccess->assertCanManageProjectMembers($request->user(), $project);

        $validated = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'role' => ['nullable', 'string', 'in:viewer,contributor,manager'],
        ]);

        $member = $this->projectService->addMember($id, $validated);

        return response()->json(new ProjectMemberResource($member), 201);
    }

    public function updateMember(Request $request, int $id, int $userId): ProjectMemberResource
    {
        $project = $this->projectService->getById($id);
        if (!$project) {
            abort(404, 'Project not found');
        }

        $this->projectAccess->assertCanManageProjectMembers($request->user(), $project);

        $validated = $request->validate([
            'role' => ['required', 'string', 'in:viewer,contributor,manager'],
        ]);

        $member = $this->projectService->updateMemberRole($id, $userId, $validated['role']);

        return new ProjectMemberResource($member);
    }

    public function destroyMember(Request $request, int $id, int $userId): JsonResponse
    {
        $project = $this->projectService->getById($id);
        if (!$project) {
            abort(404, 'Project not found');
        }

        $this->projectAccess->assertCanManageProjectMembers($request->user(), $project);

        $this->projectService->removeMember($id, $userId);

        return response()->json(null, 204);
    }
}
