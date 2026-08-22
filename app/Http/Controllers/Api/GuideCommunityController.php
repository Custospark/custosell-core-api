<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\GuideCommunity;
use Illuminate\Http\JsonResponse;

/**
 * Authenticated user-facing Communities list (company-wide, published only).
 */
class GuideCommunityController extends Controller
{
    public function index(): JsonResponse
    {
        $items = GuideCommunity::query()
            ->published()
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        return response()->json([
            'data' => $items->map(fn (GuideCommunity $community) => [
                'uuid' => $community->uuid,
                'name' => $community->name,
                'description' => $community->description,
                'platform' => $community->platform,
                'url' => $community->url,
                'icon' => $community->icon,
                'sort_order' => $community->sort_order,
            ])->values(),
        ]);
    }
}