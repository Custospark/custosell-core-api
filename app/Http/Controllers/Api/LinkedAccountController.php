<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\LinkAccountRequest;
use App\Services\LinkedAccountService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LinkedAccountController extends Controller
{
    public function __construct(
        protected LinkedAccountService $linkedAccountService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        return response()->json([
            'data' => $this->linkedAccountService->listFor($request->user()->id),
        ]);
    }

    public function store(LinkAccountRequest $request): JsonResponse
    {
        $result = $this->linkedAccountService->link(
            $request->user()->id,
            $request->string('email')->toString(),
            $request->string('password')->toString(),
        );

        return response()->json([
            'data' => array_merge($result, [
                'accounts' => $this->linkedAccountService->listFor($request->user()->id),
            ]),
        ], 201);
    }

    public function switchTo(Request $request, int $id): JsonResponse
    {
        $linkedUserId = (int) $id;
        $payload = $this->linkedAccountService->switchTo($request->user()->id, $linkedUserId);

        return response()->json(['data' => $payload]);
    }

    public function setPrimary(Request $request, int $id): JsonResponse
    {
        $this->linkedAccountService->setPrimary($request->user()->id, (int) $id);

        return response()->json([
            'data' => $this->linkedAccountService->listFor($request->user()->id),
        ]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $this->linkedAccountService->unlink($request->user()->id, (int) $id);

        return response()->json([
            'data' => $this->linkedAccountService->listFor($request->user()->id),
        ]);
    }
}
