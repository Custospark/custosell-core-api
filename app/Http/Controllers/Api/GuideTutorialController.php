<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\GuideTutorialResource;
use App\Models\GuideTutorial;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GuideTutorialController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $items = GuideTutorial::query()
            ->where('is_published', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        return response()->json([
            'data' => $items
                ->map(fn (GuideTutorial $t) => (new GuideTutorialResource($t))->toArray($request))
                ->values(),
        ]);
    }
}
