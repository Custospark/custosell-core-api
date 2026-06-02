<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\RegisterRequest;
use App\Http\Resources\UserCollection;
use App\Http\Resources\UserResource;
use App\Services\Contracts\UserServiceInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UserController extends Controller
{
    public function __construct(
        protected UserServiceInterface $userService,
    ) {}

    public function index(Request $request): UserCollection
    {
        $businessId = $request->user()->business_id;
        return new UserCollection($this->userService->getAll($businessId));
    }

    public function show(int $id): UserResource
    {
        $user = $this->userService->getById($id);
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

    public function destroy(int $id): JsonResponse
    {
        $this->userService->delete($id);
        return response()->json(null, 204);
    }
}
