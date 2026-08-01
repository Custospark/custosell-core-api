<?php

namespace App\Http\Controllers\Api\Platform;

use App\Http\Controllers\Controller;
use App\Http\Resources\PlatformUserResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
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
                'users_count' => $role->users()->count(),
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
            'permissions' => ['sometimes', 'array'],
            'permissions.*' => ['string', 'exists:platform_permissions,name'],
        ]);

        $role = Role::create(['name' => $validated['name'], 'guard_name' => 'web']);

        if (! empty($validated['permissions'] ?? [])) {
            $role->syncPermissions($validated['permissions']);
        }

        return response()->json([
            'data' => [
                'id' => $role->id,
                'name' => $role->name,
                'permissions' => $role->permissions->pluck('name'),
                'users_count' => 0,
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
            'name' => [
                'sometimes', 'required', 'string', 'max:100',
                Rule::unique('platform_roles', 'name')->where(fn ($q) => $q->where('guard_name', 'web'))->ignore($role->id),
            ],
            'permissions' => ['sometimes', 'array'],
            'permissions.*' => ['string', 'exists:platform_permissions,name'],
        ]);

        if (! empty($validated['name'] ?? null)) {
            $role->update(['name' => $validated['name']]);
        }

        if (array_key_exists('permissions', $validated)) {
            $role->syncPermissions($validated['permissions']);
        }

        return response()->json([
            'data' => [
                'id' => $role->id,
                'name' => $role->name,
                'permissions' => $role->permissions->pluck('name'),
                'users_count' => $role->users()->count(),
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

    public function members(Request $request, int $id): JsonResponse
    {
        $role = Role::where('guard_name', 'web')->findOrFail($id);

        $query = $role->users()->with(['business:id,name', 'roles:id,guard_name']);

        $search = trim((string) $request->query('search'));
        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%");
            });
        }

        $members = $query->orderBy('name')->paginate((int) $request->query('per_page', 50));

        return PlatformUserResource::collection($members)->response();
    }
}
