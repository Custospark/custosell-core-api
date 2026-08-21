<?php

namespace App\Http\Controllers\Api\Pipeline;

use App\Http\Controllers\Controller;
use App\Http\Resources\PipelineLeadLinkResource;
use App\Http\Resources\PipelineLeadMetaValueResource;
use App\Http\Resources\PipelineLeadResource;
use App\Models\PipelineLeadMetaValue;
use App\Models\User;
use App\Services\PipelineService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class PipelineLeadController extends Controller
{
    public function __construct(
        protected PipelineService $pipelineService,
    ) {}

    public function leads(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'board_id' => ['nullable', 'integer'],
            'assigned_to' => ['nullable'],
            'status' => ['nullable', 'in:open,won,lost,converted,archived'],
            'search' => ['nullable', 'string', 'max:255'],
            'source_id' => ['nullable', 'integer'],
            'card_type' => ['nullable', 'in:lead,card'],
        ]);

        $leads = $this->pipelineService->listLeads(
            (int) $request->user()->business_id,
            $request->user(),
            $filters,
        );

        return response()->json([
            'data' => PipelineLeadResource::collection($leads),
        ]);
    }

    public function storeLead(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'board_id' => ['required', 'integer'],
            'stage_id' => ['required', 'integer'],
            'title' => ['required', 'string', 'max:255'],
            'card_type' => ['nullable', 'in:lead,card'],
            'description' => ['nullable', 'string', 'max:60000'],
            'contact_name' => ['nullable', 'string', 'max:255'],
            'contact_email' => ['nullable', 'email', 'max:255'],
            'contact_phone' => ['nullable', 'string', 'max:50'],
            'customer_id' => ['nullable', 'integer'],
            'source_id' => ['nullable', 'integer'],
            'assigned_to' => ['nullable', 'integer'],
            'assignee_ids' => ['nullable', 'array'],
            'assignee_ids.*' => ['integer'],
            'estimated_value' => ['nullable', 'numeric', 'min:0'],
            'currency' => ['nullable', 'string', 'max:8'],
            'expected_close_date' => ['nullable', 'date'],
            'due_date' => ['nullable', 'date'],
            'start_date' => ['nullable', 'date'],
            'priority' => ['nullable', 'in:low,medium,high,urgent'],
            'label_ids' => ['nullable', 'array'],
            'label_ids.*' => ['integer'],
        ]);

        $lead = $this->pipelineService->createLead(
            (int) $request->user()->business_id,
            $request->user(),
            $validated,
        );

        return (new PipelineLeadResource($lead))
            ->response()
            ->setStatusCode(201);
    }

    public function showLead(Request $request, int $id): PipelineLeadResource
    {
        $lead = $this->pipelineService->getLead(
            (int) $request->user()->business_id,
            $request->user(),
            $id,
        );

        return new PipelineLeadResource($lead);
    }

    public function updateLead(Request $request, int $id): PipelineLeadResource
    {
        $validated = $request->validate([
            'title' => ['sometimes', 'string', 'max:255'],
            'card_type' => ['sometimes', 'in:lead,card'],
            'description' => ['nullable', 'string', 'max:60000'],
            'contact_name' => ['nullable', 'string', 'max:255'],
            'contact_email' => ['nullable', 'email', 'max:255'],
            'contact_phone' => ['nullable', 'string', 'max:50'],
            'customer_id' => ['nullable', 'integer'],
            'source_id' => ['nullable', 'integer'],
            'assigned_to' => ['nullable', 'integer'],
            'assignee_ids' => ['nullable', 'array'],
            'assignee_ids.*' => ['integer'],
            'estimated_value' => ['nullable', 'numeric', 'min:0'],
            'currency' => ['nullable', 'string', 'max:8'],
            'expected_close_date' => ['nullable', 'date'],
            'due_date' => ['nullable', 'date'],
            'start_date' => ['nullable', 'date'],
            'priority' => ['nullable', 'in:low,medium,high,urgent'],
            'background_color' => ['nullable', 'string', 'max:20'],
            'lost_reason' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', 'in:open,won,lost'],
            'booking_status' => ['nullable', 'in:pending,approved,rejected,completed'],
            'meeting_link' => ['nullable', 'string', 'max:500'],
            'is_pinned' => ['nullable', 'boolean'],
            'label_ids' => ['nullable', 'array'],
            'label_ids.*' => ['integer'],
        ]);

        $lead = $this->pipelineService->updateLead(
            (int) $request->user()->business_id,
            $request->user(),
            $id,
            $validated,
        );

        return new PipelineLeadResource($lead);
    }

    public function destroyLead(Request $request, int $id): JsonResponse
    {
        $this->pipelineService->archiveLead(
            (int) $request->user()->business_id,
            $request->user(),
            $id,
        );

        return response()->json(['message' => 'Lead archived']);
    }

    public function moveLead(Request $request, int $id): PipelineLeadResource
    {
        $validated = $request->validate([
            'stage_id' => ['required', 'integer'],
            'position' => ['required', 'numeric'],
        ]);

        $lead = $this->pipelineService->moveLead(
            (int) $request->user()->business_id,
            $request->user(),
            $id,
            (int) $validated['stage_id'],
            (float) $validated['position'],
        );

        return new PipelineLeadResource($lead);
    }

    public function convertLead(Request $request, int $id): PipelineLeadResource
    {
        $validated = $request->validate([
            'customer_id' => ['nullable', 'integer'],
        ]);

        $lead = $this->pipelineService->convertLead(
            (int) $request->user()->business_id,
            $request->user(),
            $id,
            $validated,
        );

        return new PipelineLeadResource($lead);
    }

    public function leadLinks(Request $request, int $lead): AnonymousResourceCollection
    {
        $businessId = (int) $request->user()->business_id;
        $leadModel = $this->pipelineService->findLeadForBusiness($businessId, $lead);
        $leadModel->load(['links.linkedLead.stage', 'links.linkedLead.board', 'links.linkedBoard', 'links.creator', 'linkedFrom.linkedLead.stage', 'linkedFrom.linkedLead.board', 'linkedFrom.linkedBoard', 'linkedFrom.creator']);
        return PipelineLeadLinkResource::collection($leadModel->links->concat($leadModel->linkedFrom));
    }

    public function storeLeadLink(Request $request, int $lead): PipelineLeadLinkResource
    {
        $validated = $request->validate([
            'linked_lead_id' => ['nullable', 'integer', 'exists:pipeline_leads,id'],
            'linked_board_id' => ['nullable', 'integer', 'exists:pipeline_boards,id'],
            'label' => ['nullable', 'string', 'max:80'],
        ]);

        if (!isset($validated['linked_lead_id']) && !isset($validated['linked_board_id'])) {
            return response()->json(['message' => 'Either linked_lead_id or linked_board_id is required.'], 422);
        }

        $link = $this->pipelineService->createLeadLink(
            (int) $request->user()->business_id,
            $request->user(),
            $lead,
            $validated,
        );

        $link->load(['linkedLead.stage', 'linkedLead.board', 'linkedBoard', 'creator']);

        return new PipelineLeadLinkResource($link);
    }

    public function destroyLeadLink(Request $request, int $id): JsonResponse
    {
        $this->pipelineService->deleteLeadLink(
            (int) $request->user()->business_id,
            $request->user(),
            $id,
        );

        return response()->json(['message' => 'Link removed']);
    }

    public function syncLeadMetaValues(Request $request, int $leadId): JsonResponse
    {
        $businessId = (int) $request->user()->business_id;
        $lead = $this->pipelineService->findLeadForBusiness($businessId, $leadId);

        if ($request->isMethod('get')) {
            $values = PipelineLeadMetaValue::query()
                ->where('lead_id', $leadId)
                ->with('metaField')
                ->get();

            return response()->json([
                'data' => PipelineLeadMetaValueResource::collection($values),
            ]);
        }

        $this->pipelineService->assertCanEditBoard($request->user(), $lead->board);

        $validated = $request->validate([
            'values' => ['required', 'array'],
            'values.*.meta_field_id' => ['required', 'integer'],
            'values.*.value' => ['nullable', 'string'],
        ]);

        foreach ($validated['values'] as $item) {
            PipelineLeadMetaValue::updateOrCreate(
                ['lead_id' => $leadId, 'meta_field_id' => (int) $item['meta_field_id']],
                ['value' => $item['value'] ?? ''],
            );
        }

        $values = PipelineLeadMetaValue::query()
            ->where('lead_id', $leadId)
            ->with('metaField')
            ->get();

        return response()->json([
            'data' => PipelineLeadMetaValueResource::collection($values),
        ]);
    }
}
