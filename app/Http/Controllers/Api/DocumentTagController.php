<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Documents\DocumentTagService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DocumentTagController extends Controller
{
    public function __construct(
        protected DocumentTagService $tags,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:50'],
        ]);

        $businessId = (int) $request->user()->business_id;

        return response()->json([
            'data' => $this->tags->list($businessId, $validated['q'] ?? null),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:50'],
        ]);

        $businessId = (int) $request->user()->business_id;

        return response()->json([
            'data' => $this->tags->create($businessId, $validated['name']),
        ], 201);
    }
}
