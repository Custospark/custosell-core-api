<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ConfirmLinkAccountRequest;
use App\Http\Requests\ConfirmUnlinkAccountRequest;
use App\Http\Requests\LinkAccountRequest;
use App\Http\Requests\UnlinkAccountRequest;
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

    public function initiateLink(LinkAccountRequest $request): JsonResponse
    {
        $result = $this->linkedAccountService->initiateLink(
            $request->user()->id,
            $request->string('email')->toString(),
            $request->string('password')->toString(),
            $request->ip(),
            $request->userAgent(),
        );

        return response()->json(['data' => $result]);
    }

    public function confirmLink(ConfirmLinkAccountRequest $request): JsonResponse
    {
        $result = $this->linkedAccountService->confirmLink(
            $request->user()->id,
            (int) $request->integer('target_user_id'),
            $request->string('code')->toString(),
        );

        return response()->json([
            'data' => array_merge($result, [
                'accounts' => $this->linkedAccountService->listFor($request->user()->id),
            ]),
        ]);
    }

    public function switchTo(Request $request, int $id): JsonResponse
    {
        $payload = $this->linkedAccountService->switchTo($request->user()->id, (int) $id);

        return response()->json(['data' => $payload]);
    }

    public function setPrimary(Request $request, int $id): JsonResponse
    {
        $this->linkedAccountService->setPrimary($request->user()->id, (int) $id);

        return response()->json([
            'data' => $this->linkedAccountService->listFor($request->user()->id),
        ]);
    }

    public function initiateUnlink(UnlinkAccountRequest $request, int $id): JsonResponse
    {
        $result = $this->linkedAccountService->initiateUnlink(
            $request->user()->id,
            (int) $id,
            $request->ip(),
            $request->userAgent(),
        );

        return response()->json(['data' => $result]);
    }

    public function confirmUnlink(ConfirmUnlinkAccountRequest $request, int $id): JsonResponse
    {
        $this->linkedAccountService->confirmUnlink(
            $request->user()->id,
            (int) $id,
            $request->string('code')->toString(),
        );

        return response()->json([
            'data' => $this->linkedAccountService->listFor($request->user()->id),
        ]);
    }
}
