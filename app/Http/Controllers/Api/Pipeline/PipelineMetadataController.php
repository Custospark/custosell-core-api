<?php

namespace App\Http\Controllers\Api\Pipeline;

use App\Http\Controllers\Controller;
use App\Http\Resources\PipelineBoardMetaFieldResource;
use App\Http\Resources\PipelineLeadMetaValueResource;
use App\Models\PipelineBoard;
use App\Models\PipelineBoardMetaField;
use App\Models\User;
use App\Services\PipelineService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class PipelineMetadataController extends Controller
{
    public function __construct(
        protected PipelineService $pipelineService,
    ) {}

    public function boardMetaFields(Request $request, int $boardId): AnonymousResourceCollection
    {
        $this->pipelineService->getBoard(
            (int) $request->user()->business_id,
            $request->user(),
            $boardId,
        );

        $fields = PipelineBoardMetaField::query()
            ->where('board_id', $boardId)
            ->orderBy('sort_order')
            ->get();

        return PipelineBoardMetaFieldResource::collection($fields);
    }

    public function storeBoardMetaField(Request $request, int $boardId): PipelineBoardMetaFieldResource
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'type' => ['required', 'in:text,number,date,select,multi_select'],
            'options' => ['nullable', 'array'],
            'options.*' => ['string', 'max:255'],
            'required' => ['nullable', 'boolean'],
        ]);

        $businessId = (int) $request->user()->business_id;
        $board = PipelineBoard::query()->where('business_id', $businessId)->where('id', $boardId)->firstOrFail();
        $this->pipelineService->assertCanEditBoard($request->user(), $board);

        $maxSort = PipelineBoardMetaField::query()->where('board_id', $boardId)->max('sort_order') ?? 0;

        $field = PipelineBoardMetaField::create([
            'board_id' => $boardId,
            'name' => $validated['name'],
            'type' => $validated['type'],
            'options' => in_array($validated['type'], ['select', 'multi_select']) ? ($validated['options'] ?? []) : null,
            'required' => $validated['required'] ?? false,
            'sort_order' => $maxSort + 1,
        ]);

        return new PipelineBoardMetaFieldResource($field);
    }

    public function updateBoardMetaField(Request $request, int $id): PipelineBoardMetaFieldResource
    {
        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'type' => ['sometimes', 'in:text,number,date,select,multi_select'],
            'options' => ['nullable', 'array'],
            'options.*' => ['string', 'max:255'],
            'required' => ['nullable', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ]);

        $field = PipelineBoardMetaField::query()->findOrFail($id);
        $businessId = (int) $request->user()->business_id;
        $board = PipelineBoard::query()->where('business_id', $businessId)->where('id', $field->board_id)->firstOrFail();
        $this->pipelineService->assertCanEditBoard($request->user(), $board);

        $data = [];
        if (isset($validated['name'])) $data['name'] = $validated['name'];
        if (isset($validated['type'])) $data['type'] = $validated['type'];
        if (array_key_exists('required', $validated)) $data['required'] = $validated['required'];
        if (array_key_exists('options', $validated)) {
            $data['options'] = in_array($validated['type'] ?? $field->type, ['select', 'multi_select'])
                ? ($validated['options'] ?? [])
                : null;
        }
        if (isset($validated['sort_order'])) $data['sort_order'] = $validated['sort_order'];

        $field->update($data);
        return new PipelineBoardMetaFieldResource($field->fresh());
    }

    public function destroyBoardMetaField(Request $request, int $id): JsonResponse
    {
        $field = PipelineBoardMetaField::query()->findOrFail($id);
        $businessId = (int) $request->user()->business_id;
        $board = PipelineBoard::query()->where('business_id', $businessId)->where('id', $field->board_id)->firstOrFail();
        $this->pipelineService->assertCanEditBoard($request->user(), $board);

        $field->delete();
        return response()->json(['message' => 'Meta field deleted']);
    }
}
