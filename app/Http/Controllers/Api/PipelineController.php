<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\PipelineAttachmentResource;
use App\Http\Resources\PipelineBoardResource;
use App\Http\Resources\PipelineChecklistItemResource;
use App\Http\Resources\PipelineChecklistResource;
use App\Http\Resources\PipelineLabelResource;
use App\Http\Resources\PipelineLeadActivityResource;
use App\Http\Resources\PipelineLeadResource;
use App\Http\Resources\PipelineSourceResource;
use App\Http\Resources\PipelineStageResource;
use App\Services\PipelineService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PipelineController extends Controller
{
    public function __construct(
        protected PipelineService $pipelineService,
    ) {}

    public function boards(Request $request): JsonResponse
    {
        $businessId = (int) $request->user()->business_id;
        $salesOnly = $request->boolean('sales_only', !$request->boolean('project_only', false) && !$request->boolean('estimates_workspace', false));
        $projectOnly = $request->boolean('project_only', false);
        $estimatesWorkspace = $request->boolean('estimates_workspace', false);
        $boards = $this->pipelineService->listBoards(
            $businessId,
            $request->user(),
            $salesOnly,
            $projectOnly,
            $estimatesWorkspace,
        );

        return response()->json([
            'data' => PipelineBoardResource::collection($boards),
        ]);
    }

    public function storeBoard(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'visibility' => ['required', 'in:team,private,shared'],
            'cover_color' => ['nullable', 'string', 'max:32'],
            'background_type' => ['nullable', 'string', 'in:color,gallery,upload'],
            'background_value' => ['nullable', 'string', 'max:500'],
            'member_ids' => ['nullable', 'array'],
            'member_ids.*' => ['integer'],
            'members' => ['nullable', 'array'],
            'members.*.user_id' => ['required_with:members', 'integer'],
            'members.*.role' => ['nullable', 'in:viewer,editor'],
            'workspace' => ['nullable', 'in:pipeline,estimates'],
        ]);

        $board = $this->pipelineService->createBoard(
            (int) $request->user()->business_id,
            (int) $request->user()->id,
            $validated,
        );

        return (new PipelineBoardResource($board))
            ->response()
            ->setStatusCode(201);
    }

    public function showBoard(Request $request, int $id): PipelineBoardResource
    {
        $board = $this->pipelineService->getBoard(
            (int) $request->user()->business_id,
            $request->user(),
            $id,
        );

        return new PipelineBoardResource($board);
    }

    public function updateBoard(Request $request, int $id): PipelineBoardResource
    {
        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'visibility' => ['sometimes', 'in:team,private,shared'],
            'cover_color' => ['nullable', 'string', 'max:32'],
            'background_type' => ['nullable', 'string', 'in:color,gallery,upload'],
            'background_value' => ['nullable', 'string', 'max:500'],
            'is_archived' => ['sometimes', 'boolean'],
            'member_ids' => ['nullable', 'array'],
            'member_ids.*' => ['integer'],
            'members' => ['nullable', 'array'],
            'members.*.user_id' => ['required_with:members', 'integer'],
            'members.*.role' => ['nullable', 'in:viewer,editor'],
        ]);

        $board = $this->pipelineService->updateBoard(
            (int) $request->user()->business_id,
            $request->user(),
            $id,
            $validated,
        );

        return new PipelineBoardResource($board);
    }

    public function kanban(Request $request, int $id): PipelineBoardResource
    {
        $board = $this->pipelineService->getKanban(
            (int) $request->user()->business_id,
            $request->user(),
            $id,
        );

        return new PipelineBoardResource($board);
    }

    public function projectBoard(Request $request, int $projectId): PipelineBoardResource
    {
        $board = $this->pipelineService->getOrCreateProjectBoard(
            (int) $request->user()->business_id,
            $request->user(),
            $projectId,
        );

        return new PipelineBoardResource($board);
    }

    public function projectKanban(Request $request, int $projectId): PipelineBoardResource
    {
        $board = $this->pipelineService->getOrCreateProjectBoard(
            (int) $request->user()->business_id,
            $request->user(),
            $projectId,
        );

        $kanban = $this->pipelineService->getKanban(
            (int) $request->user()->business_id,
            $request->user(),
            $board->id,
        );

        return new PipelineBoardResource($kanban);
    }

    public function uploadBoardBackground(Request $request, int $boardId): \Illuminate\Http\JsonResponse
    {
        $board = $this->pipelineService->getBoard(
            (int) $request->user()->business_id,
            $request->user(),
            $boardId,
        );

        $request->validate([
            'background' => ['required', 'file', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ]);

        $path = $request->file('background')->store('pipeline-board-bg', 'public');

        $board->update([
            'background_type' => 'upload',
            'background_value' => $path,
        ]);

        return response()->json([
            'background_type' => 'upload',
            'background_value' => $path,
            'url' => url('storage/' . $path),
        ]);
    }

    public function storeStage(Request $request, int $boardId): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'color' => ['nullable', 'string', 'max:32'],
            'is_won' => ['sometimes', 'boolean'],
            'is_lost' => ['sometimes', 'boolean'],
            'rotting_days' => ['nullable', 'integer', 'min:1', 'max:365'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ]);

        $stage = $this->pipelineService->createStage(
            (int) $request->user()->business_id,
            $request->user(),
            $boardId,
            $validated,
        );

        return (new PipelineStageResource($stage))
            ->response()
            ->setStatusCode(201);
    }

    public function updateStage(Request $request, int $stageId): PipelineStageResource
    {
        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'color' => ['nullable', 'string', 'max:32'],
            'is_won' => ['sometimes', 'boolean'],
            'is_lost' => ['sometimes', 'boolean'],
            'rotting_days' => ['nullable', 'integer', 'min:1', 'max:365'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ]);

        $stage = $this->pipelineService->updateStage(
            (int) $request->user()->business_id,
            $request->user(),
            $stageId,
            $validated,
        );

        return new PipelineStageResource($stage);
    }

    public function reorderStages(Request $request, int $boardId): JsonResponse
    {
        $validated = $request->validate([
            'stage_ids' => ['required', 'array', 'min:1'],
            'stage_ids.*' => ['integer'],
        ]);

        $stages = $this->pipelineService->reorderStages(
            (int) $request->user()->business_id,
            $request->user(),
            $boardId,
            $validated['stage_ids'],
        );

        return response()->json([
            'data' => PipelineStageResource::collection($stages),
        ]);
    }

    public function destroyStage(Request $request, int $stageId): JsonResponse
    {
        $validated = $request->validate([
            'migrate_to_stage_id' => ['nullable', 'integer'],
        ]);

        $this->pipelineService->deleteStage(
            (int) $request->user()->business_id,
            $request->user(),
            $stageId,
            $validated['migrate_to_stage_id'] ?? null,
        );

        return response()->json(['message' => 'Stage deleted']);
    }

    public function calendar(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'year' => ['required', 'integer', 'min:2000', 'max:2100'],
            'month' => ['required', 'integer', 'min:1', 'max:12'],
            'date_field' => ['nullable', 'in:due,start,close,all'],
        ]);

        $days = $this->pipelineService->boardCalendar(
            (int) $request->user()->business_id,
            $request->user(),
            $id,
            (int) $validated['year'],
            (int) $validated['month'],
            $validated['date_field'] ?? 'due',
        );

        return response()->json(['data' => $days]);
    }

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
            'description' => ['nullable', 'string', 'max:5000'],
            'contact_name' => ['nullable', 'string', 'max:255'],
            'contact_email' => ['nullable', 'email', 'max:255'],
            'contact_phone' => ['nullable', 'string', 'max:50'],
            'customer_id' => ['nullable', 'integer'],
            'source_id' => ['nullable', 'integer'],
            'assigned_to' => ['nullable', 'integer'],
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
            'description' => ['nullable', 'string', 'max:5000'],
            'contact_name' => ['nullable', 'string', 'max:255'],
            'contact_email' => ['nullable', 'email', 'max:255'],
            'contact_phone' => ['nullable', 'string', 'max:50'],
            'customer_id' => ['nullable', 'integer'],
            'source_id' => ['nullable', 'integer'],
            'assigned_to' => ['nullable', 'integer'],
            'estimated_value' => ['nullable', 'numeric', 'min:0'],
            'currency' => ['nullable', 'string', 'max:8'],
            'expected_close_date' => ['nullable', 'date'],
            'due_date' => ['nullable', 'date'],
            'start_date' => ['nullable', 'date'],
            'priority' => ['nullable', 'in:low,medium,high,urgent'],
            'background_color' => ['nullable', 'string', 'max:20'],
            'lost_reason' => ['nullable', 'string', 'max:255'],
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

    public function storeActivity(Request $request, int $leadId): JsonResponse
    {
        $validated = $request->validate([
            'type' => ['required', 'in:note,comment,call,email,meeting'],
            'body' => ['required', 'string', 'max:5000'],
        ]);

        $activity = $this->pipelineService->addActivity(
            (int) $request->user()->business_id,
            $request->user(),
            $leadId,
            $validated['type'],
            $validated['body'],
        );

        return (new PipelineLeadActivityResource($activity->load('user:id,name,avatar')))
            ->response()
            ->setStatusCode(201);
    }

    public function destroyActivity(Request $request, int $id): JsonResponse
    {
        $this->pipelineService->deleteActivity(
            (int) $request->user()->business_id,
            $request->user(),
            $id,
        );

        return response()->json(null, 204);
    }

    public function sources(Request $request): JsonResponse
    {
        $sources = $this->pipelineService->listSources((int) $request->user()->business_id);

        return response()->json([
            'data' => PipelineSourceResource::collection($sources),
        ]);
    }

    public function storeSource(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ]);

        $source = $this->pipelineService->createSource(
            (int) $request->user()->business_id,
            $request->user(),
            $validated,
        );

        return (new PipelineSourceResource($source))
            ->response()
            ->setStatusCode(201);
    }

    public function updateSource(Request $request, int $id): PipelineSourceResource
    {
        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ]);

        $source = $this->pipelineService->updateSource(
            (int) $request->user()->business_id,
            $id,
            $validated,
        );

        return new PipelineSourceResource($source);
    }

    public function destroySource(Request $request, int $id): JsonResponse
    {
        $this->pipelineService->deleteSource((int) $request->user()->business_id, $id);

        return response()->json(['message' => 'Source deleted']);
    }

    public function insights(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'board_id' => ['nullable', 'integer'],
        ]);

        $summary = $this->pipelineService->insightsSummary(
            (int) $request->user()->business_id,
            $request->user(),
            $validated['board_id'] ?? null,
        );

        return response()->json(['data' => $summary]);
    }

    public function labels(Request $request): JsonResponse
    {
        $validated = $request->validate(['board_id' => ['nullable', 'integer']]);

        $labels = $this->pipelineService->listLabels(
            (int) $request->user()->business_id,
            $request->user(),
            $validated['board_id'] ?? null,
        );

        return response()->json(['data' => PipelineLabelResource::collection($labels)]);
    }

    public function storeLabel(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'board_id' => ['nullable', 'integer'],
            'name' => ['required', 'string', 'max:80'],
            'color' => ['nullable', 'string', 'max:32'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ]);

        $label = $this->pipelineService->createLabel(
            (int) $request->user()->business_id,
            $request->user(),
            $validated,
        );

        return (new PipelineLabelResource($label))->response()->setStatusCode(201);
    }

    public function updateLabel(Request $request, int $id): PipelineLabelResource
    {
        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:80'],
            'color' => ['nullable', 'string', 'max:32'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ]);

        $label = $this->pipelineService->updateLabel(
            (int) $request->user()->business_id,
            $request->user(),
            $id,
            $validated,
        );

        return new PipelineLabelResource($label);
    }

    public function destroyLabel(Request $request, int $id): JsonResponse
    {
        $this->pipelineService->deleteLabel((int) $request->user()->business_id, $request->user(), $id);

        return response()->json(['message' => 'Label deleted']);
    }

    public function storeChecklist(Request $request, int $leadId): JsonResponse
    {
        $validated = $request->validate([
            'title' => ['nullable', 'string', 'max:255'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ]);

        $checklist = $this->pipelineService->createChecklist(
            (int) $request->user()->business_id,
            $request->user(),
            $leadId,
            $validated,
        );

        return (new PipelineChecklistResource($checklist))->response()->setStatusCode(201);
    }

    public function updateChecklist(Request $request, int $id): PipelineChecklistResource
    {
        $validated = $request->validate([
            'title' => ['sometimes', 'string', 'max:255'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ]);

        $checklist = $this->pipelineService->updateChecklist(
            (int) $request->user()->business_id,
            $request->user(),
            $id,
            $validated,
        );

        return new PipelineChecklistResource($checklist);
    }

    public function destroyChecklist(Request $request, int $id): JsonResponse
    {
        $this->pipelineService->deleteChecklist((int) $request->user()->business_id, $request->user(), $id);

        return response()->json(['message' => 'Checklist deleted']);
    }

    public function storeChecklistItem(Request $request, int $checklistId): JsonResponse
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:500'],
            'is_done' => ['sometimes', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ]);

        $item = $this->pipelineService->createChecklistItem(
            (int) $request->user()->business_id,
            $request->user(),
            $checklistId,
            $validated,
        );

        return (new PipelineChecklistItemResource($item))->response()->setStatusCode(201);
    }

    public function updateChecklistItem(Request $request, int $id): PipelineChecklistItemResource
    {
        $validated = $request->validate([
            'title' => ['sometimes', 'string', 'max:500'],
            'is_done' => ['sometimes', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ]);

        $item = $this->pipelineService->updateChecklistItem(
            (int) $request->user()->business_id,
            $request->user(),
            $id,
            $validated,
        );

        return new PipelineChecklistItemResource($item);
    }

    public function destroyChecklistItem(Request $request, int $id): JsonResponse
    {
        $this->pipelineService->deleteChecklistItem((int) $request->user()->business_id, $request->user(), $id);

        return response()->json(['message' => 'Checklist item deleted']);
    }

    public function storeAttachment(Request $request, int $leadId): JsonResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'max:10240', 'mimes:jpg,jpeg,png,gif,pdf,doc,docx,xlsx,txt,csv'],
        ]);

        $attachment = $this->pipelineService->addAttachment(
            (int) $request->user()->business_id,
            $request->user(),
            $leadId,
            $request->file('file'),
        );

        return (new PipelineAttachmentResource($attachment))->response()->setStatusCode(201);
    }

    public function destroyAttachment(Request $request, int $id): JsonResponse
    {
        $this->pipelineService->deleteAttachment((int) $request->user()->business_id, $request->user(), $id);

        return response()->json(['message' => 'Attachment deleted']);
    }
}
