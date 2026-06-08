<?php

namespace App\Http\Controllers\Api\Platform;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class PlatformRoleController extends Controller
{
    public function index(): JsonResponse
    {
        $roles = Role::where('guard_name', 'web')
            ->with('permissions:id,name')
            ->orderBy('name')
            ->get()
            ->map(fn (Role $role) => [
                'id' => $role->id,
                'name' => $role->name,
                'permissions' => $role->permissions->pluck('name')->values(),
            ]);

        return response()->json(['data' => $roles]);
    }

    public function permissions(): JsonResponse
    {
        $permissions = Permission::where('guard_name', 'web')
            ->where('name', 'like', 'platform.%')
            ->orderBy('name')
            ->pluck('name');

        return response()->json(['data' => $permissions]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100', 'unique:platform_roles,name'],
            'permissions' => ['required', 'array'],
            'permissions.*' => ['string', 'exists:platform_permissions,name'],
        ]);

        $role = Role::create(['name' => $validated['name'], 'guard_name' => 'web']);
        $role->syncPermissions($validated['permissions']);

        return response()->json([
            'data' => [
                'id' => $role->id,
                'name' => $role->name,
                'permissions' => $role->permissions->pluck('name'),
            ],
        ], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $role = Role::where('guard_name', 'web')->findOrFail($id);

        if ($role->name === 'platform-admin') {
            return response()->json(['message' => 'Cannot modify the platform-admin role.'], 422);
        }

        $validated = $request->validate([
            'permissions' => ['required', 'array'],
            'permissions.*' => ['string', 'exists:platform_permissions,name'],
        ]);

        $role->syncPermissions($validated['permissions']);

        return response()->json([
            'data' => [
                'id' => $role->id,
                'name' => $role->name,
                'permissions' => $role->permissions->pluck('name'),
            ],
        ]);
    }

    public function destroy(int $id): JsonResponse
    {
        $role = Role::where('guard_name', 'web')->findOrFail($id);

        if (in_array($role->name, ['platform-admin', 'platform-analyst', 'platform-support'], true)) {
            return response()->json(['message' => 'Cannot delete a built-in platform role.'], 422);
        }

        $role->delete();

        return response()->json(['message' => 'Role deleted.']);
    }
}
