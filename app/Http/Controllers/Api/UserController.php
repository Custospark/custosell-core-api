<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ProfileRequest;
use App\Http\Requests\RegisterRequest;
use App\Http\Requests\UpdateUserRequest;
use App\Http\Resources\UserCollection;
use App\Http\Resources\UserResource;
use App\Services\Contracts\UserServiceInterface;
use App\Services\ModuleAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;

class UserController extends Controller
{
    public function __construct(
        protected UserServiceInterface $userService,
        protected ModuleAccessService $moduleAccess,
    ) {}

    public function index(Request $request): UserCollection
    {
        $businessId = $request->user()->business_id;
        return new UserCollection($this->userService->getAll($businessId));
    }

    public function show(Request $request, int $id): UserResource
    {
        $user = $this->userService->getByIdForBusiness($id, $request->user()->business_id);
        if (!$user) {
            abort(404, 'User not found');
        }
        return new UserResource($user);
    }

    public function store(RegisterRequest $request): JsonResponse
    {
        $businessId = $request->user()->business_id;
        $data = $request->validated();
        $data['role_id'] = $request->role_id;
        $user = $this->userService->createStaff($businessId, $data);
        return response()->json(new UserResource($user), 201);
    }

    public function update(UpdateUserRequest $request, int $id): UserResource
    {
        $user = $this->userService->update(
            $id,
            $request->user()->business_id,
            $request->user()->id,
            $request->validated(),
        );
        return new UserResource($user);
    }

    public function updateProfile(ProfileRequest $request): UserResource
    {
        $user = $request->user();
        $data = [];

        if ($request->has('name')) {
            $data['name'] = $request->input('name');
        }
        if ($request->has('email')) {
            $data['email'] = $request->input('email');
        }
        if ($request->has('phone')) {
            $data['phone'] = $request->input('phone');
        }

        if ($request->hasFile('avatar')) {
            if ($user->avatar) {
                $oldPath = str_replace('/storage/', '', $user->avatar);
                Storage::disk('public')->delete($oldPath);
            }
            $data['avatar'] = '/storage/' . $request->file('avatar')->store('avatars', 'public');
        }

        if ($request->filled('password')) {
            $data['password'] = Hash::make($request->input('password'));
        }

        if ($request->has('modules')) {
            if (! $this->moduleAccess->isBusinessOwner($user)) {
                abort(403, 'Only the business owner can update module access from profile settings.');
            }

            $data['modules'] = $this->moduleAccess->normalizeOwnerModules($request->input('modules'));
        }

        DB::transaction(function () use ($request, $user, $data): void {
            $user->update($data);
            $user->load('business');

            if ($request->has('modules') && $this->moduleAccess->isBusinessOwner($user)) {
                $this->userService->clampStaffModulesAfterOwnerUpdate($user);
            }
        });

        return new UserResource($user);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $this->userService->delete($id, $request->user()->business_id, $request->user()->id);
        return response()->json(null, 204);
    }
}
