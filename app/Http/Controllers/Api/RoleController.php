<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\RoleRequest;
use App\Http\Resources\RoleCollection;
use App\Http\Resources\RoleResource;
use App\Services\Contracts\RoleServiceInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RoleController extends Controller
{
    public function __construct(
        protected RoleServiceInterface $roleService,
    ) {}

    public function index(Request $request): RoleCollection
    {
        $businessId = $request->user()->business_id;
        return new RoleCollection($this->roleService->getAll($businessId));
    }

    public function show(int $id): RoleResource
    {
        $role = $this->roleService->getById($id);
        if (!$role) {
            abort(404, 'Role not found');
        }
        return new RoleResource($role);
    }

    public function store(RoleRequest $request): JsonResponse
    {
        $businessId = $request->user()->business_id;
        $role = $this->roleService->create($businessId, $request->validated());
        return response()->json(new RoleResource($role), 201);
    }

    public function update(RoleRequest $request, int $id): RoleResource
    {
        $role = $this->roleService->update($id, $request->validated());
        return new RoleResource($role);
    }

    public function destroy(int $id): JsonResponse
    {
        $this->roleService->delete($id);
        return response()->json(null, 204);
    }
}
