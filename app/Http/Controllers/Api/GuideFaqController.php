<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\GuideFaq;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GuideFaqController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $items = GuideFaq::query()
            ->published()
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        return response()->json([
            'data' => $items->map(fn (GuideFaq $faq) => [
                'uuid' => $faq->uuid,
                'question' => $faq->question,
                'answer' => $faq->answer,
                'sort_order' => $faq->sort_order,
            ])->values(),
        ]);
    }
}
