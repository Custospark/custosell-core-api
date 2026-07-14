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
use App\Services\Pipeline\PipelineBoardActivityService;
use App\Services\Pipeline\PipelineBoardAutomationService;
use App\Services\Pipeline\PipelineBoardConversationService;
use App\Services\Pipeline\PipelineBoardProgressService;
use App\Services\Pipeline\PipelineBoardResourceService;
use App\Services\Pipeline\PipelineBoardTemplateService;
use App\Services\Pipeline\PipelineCollaborationService;
use App\Services\Pipeline\PipelineLeadImportService;
use App\Services\PipelineService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PipelineController extends Controller
{
    public function __construct(
        protected PipelineService $pipelineService,
        protected PipelineCollaborationService $collaboration,
        protected PipelineBoardResourceService $boardResources,
        protected PipelineBoardConversationService $boardConversation,
        protected PipelineBoardActivityService $boardActivity,
        protected PipelineBoardAutomationService $boardAutomations,
        protected PipelineBoardTemplateService $boardTemplates,
        protected PipelineBoardProgressService $boardProgress,
        protected PipelineLeadImportService $leadImport,
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

    public function teamMembers(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'workspace' => ['nullable', 'in:pipeline,estimates'],
            'scope' => ['nullable', 'in:workspace,business'],
        ]);

        $members = $this->pipelineService->listBoardTeamMembers(
            (int) $request->user()->business_id,
            $validated['workspace'] ?? 'pipeline',
            $validated['scope'] ?? 'workspace',
        );

        return response()->json(['data' => $members]);
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
            'members.*.role' => ['nullable', 'in:viewer,contributor,manager,editor'],
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
            'members.*.role' => ['nullable', 'in:viewer,contributor,manager,editor'],
        ]);

        $board = $this->pipelineService->updateBoard(
            (int) $request->user()->business_id,
            $request->user(),
            $id,
            $validated,
        );

        return new PipelineBoardResource($board);
    }

    public function destroyBoard(Request $request, int $id): JsonResponse
    {
        $this->pipelineService->deleteBoard(
            (int) $request->user()->business_id,
            $request->user(),
            $id,
        );

        return response()->json(['message' => 'Board deleted']);
    }

    public function downloadLeadImportTemplate(Request $request, int $id)
    {
        $board = $this->pipelineService->getBoard(
            (int) $request->user()->business_id,
            $request->user(),
            $id,
        );
        $this->pipelineService->ensureCanEditBoard($request->user(), $board);

        $spreadsheet = $this->leadImport->generateTemplate($board);
        $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);

        $fileName = 'board-card-import-template.xlsx';
        $tempFile = tempnam(sys_get_temp_dir(), $fileName);
        $writer->save($tempFile);

        return response()->download($tempFile, $fileName, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])->deleteFileAfterSend(true);
    }

    public function importLeads(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'mimes:xlsx,xls,csv', 'max:20480'],
        ]);

        $board = $this->pipelineService->getBoard(
            (int) $request->user()->business_id,
            $request->user(),
            $id,
        );
        $this->pipelineService->ensureCanEditBoard($request->user(), $board);

        set_time_limit(600);

        $results = $this->leadImport->import(
            (int) $request->user()->business_id,
            $request->user(),
            $board,
            $request->file('file')->getPathname(),
        );

        return response()->json($results);
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
        $user = $request->user();
        $board = $this->pipelineService->getBoard(
            (int) $user->business_id,
            $user,
            $boardId,
        );
        $this->pipelineService->ensureCanManageBoard($user, $board);

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
            'description' => ['nullable', 'string', 'max:5000'],
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
            'parent_id' => ['nullable', 'integer'],
        ]);

        $activity = $this->pipelineService->addActivityAndNotify(
            (int) $request->user()->business_id,
            $request->user(),
            $leadId,
            $validated['type'],
            $validated['body'],
            null,
            $validated['parent_id'] ?? null,
        );

        $activity->load(['user:id,name,avatar', 'lead.board']);
        $canModerate = $activity->lead?->board
            ? $this->pipelineService->userCanManageBoard($request->user(), $activity->lead->board)
            : false;
        $request->attributes->set('pipeline_can_moderate_board', $canModerate);

        return (new PipelineLeadActivityResource($activity))
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

    public function updateActivity(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'body' => ['required', 'string', 'max:5000'],
        ]);

        $activity = $this->pipelineService->updateActivity(
            (int) $request->user()->business_id,
            $request->user(),
            $id,
            $validated['body'],
        );

        $activity->loadMissing('lead.board');
        $canModerate = $activity->lead?->board
            ? $this->pipelineService->userCanManageBoard($request->user(), $activity->lead->board)
            : false;
        $request->attributes->set('pipeline_can_moderate_board', $canModerate);

        return (new PipelineLeadActivityResource($activity))
            ->response();
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
            'description' => ['nullable', 'string', 'max:5000'],
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
            'description' => ['nullable', 'string', 'max:5000'],
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
            'description' => ['nullable', 'string', 'max:5000'],
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
            'description' => ['nullable', 'string', 'max:5000'],
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

    public function toggleActivityReaction(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'reaction' => ['nullable', 'in:like,dislike'],
        ]);

        $summary = $this->collaboration->toggleReaction(
            (int) $request->user()->business_id,
            $request->user(),
            $id,
            $validated['reaction'] ?? null,
        );

        return response()->json(['data' => $summary]);
    }

    public function boardCollaborationSummary(Request $request, int $boardId): JsonResponse
    {
        $summary = $this->collaboration->boardCollaborationSummary(
            (int) $request->user()->business_id,
            $request->user(),
            $boardId,
        );

        return response()->json(['data' => $summary]);
    }

    public function boardAnnouncements(Request $request, int $boardId): JsonResponse
    {
        $items = $this->collaboration->listAnnouncements(
            (int) $request->user()->business_id,
            $request->user(),
            $boardId,
        );

        return response()->json(['data' => $items]);
    }

    public function storeBoardAnnouncement(Request $request, int $boardId): JsonResponse
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string', 'max:5000'],
            'is_pinned' => ['sometimes', 'boolean'],
        ]);

        $item = $this->collaboration->createAnnouncement(
            (int) $request->user()->business_id,
            $request->user(),
            $boardId,
            $validated['title'],
            $validated['body'],
            (bool) ($validated['is_pinned'] ?? false),
        );

        return response()->json(['data' => $item], 201);
    }

    public function destroyBoardAnnouncement(Request $request, int $id): JsonResponse
    {
        $this->collaboration->deleteAnnouncement(
            (int) $request->user()->business_id,
            $request->user(),
            $id,
        );

        return response()->json(['message' => 'Announcement removed']);
    }

    public function setAnnouncementRead(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'is_read' => ['required', 'boolean'],
        ]);

        $item = $this->collaboration->setAnnouncementReadState(
            (int) $request->user()->business_id,
            $request->user(),
            $id,
            (bool) $validated['is_read'],
        );

        return response()->json(['data' => $item]);
    }

    public function boardPolls(Request $request, int $boardId): JsonResponse
    {
        $leadId = $request->query('lead_id') ? (int) $request->query('lead_id') : null;
        $polls = $this->collaboration->listPolls(
            (int) $request->user()->business_id,
            $request->user(),
            $boardId,
            $leadId,
        );

        return response()->json(['data' => $polls]);
    }

    public function storeBoardPoll(Request $request, int $boardId): JsonResponse
    {
        $validated = $request->validate([
            'question' => ['required', 'string', 'max:500'],
            'options' => ['required', 'array', 'min:2', 'max:12'],
            'options.*' => ['required', 'string', 'max:255'],
            'lead_id' => ['nullable', 'integer'],
            'closes_at' => ['nullable', 'date'],
            'results_visibility' => ['sometimes', 'in:team,creator_only'],
        ]);

        $poll = $this->collaboration->createPoll(
            (int) $request->user()->business_id,
            $request->user(),
            $boardId,
            $validated['question'],
            $validated['options'],
            $validated['lead_id'] ?? null,
            $validated['closes_at'] ?? null,
            $validated['results_visibility'] ?? 'team',
        );

        return response()->json(['data' => $poll], 201);
    }

    public function updatePoll(Request $request, int $pollId): JsonResponse
    {
        $validated = $request->validate([
            'question' => ['sometimes', 'string', 'max:500'],
            'options' => ['sometimes', 'array', 'min:2', 'max:12'],
            'options.*.id' => ['sometimes', 'integer'],
            'options.*.label' => ['required_with:options', 'string', 'max:255'],
            'closes_at' => ['nullable', 'date'],
            'results_visibility' => ['sometimes', 'in:team,creator_only'],
        ]);

        $poll = $this->collaboration->updatePoll(
            (int) $request->user()->business_id,
            $request->user(),
            $pollId,
            $validated,
        );

        return response()->json(['data' => $poll]);
    }

    public function votePoll(Request $request, int $pollId): JsonResponse
    {
        $validated = $request->validate([
            'option_id' => ['required', 'integer'],
        ]);

        $poll = $this->collaboration->votePoll(
            (int) $request->user()->business_id,
            $request->user(),
            $pollId,
            (int) $validated['option_id'],
        );

        return response()->json(['data' => $poll]);
    }

    public function removePollVote(Request $request, int $pollId): JsonResponse
    {
        $validated = $request->validate([
            'user_id' => ['nullable', 'integer'],
        ]);

        $poll = $this->collaboration->removePollVote(
            (int) $request->user()->business_id,
            $request->user(),
            $pollId,
            isset($validated['user_id']) ? (int) $validated['user_id'] : null,
        );

        return response()->json(['data' => $poll]);
    }

    public function destroyPoll(Request $request, int $pollId): JsonResponse
    {
        $this->collaboration->deletePoll(
            (int) $request->user()->business_id,
            $request->user(),
            $pollId,
        );

        return response()->json(['message' => 'Poll removed']);
    }

    public function leadReminders(Request $request, int $leadId): JsonResponse
    {
        $items = $this->collaboration->listReminders(
            (int) $request->user()->business_id,
            $request->user(),
            $leadId,
        );

        return response()->json(['data' => $items]);
    }

    public function storeLeadReminder(Request $request, int $leadId): JsonResponse
    {
        $validated = $request->validate([
            'remind_at' => ['required', 'date'],
            'message' => ['nullable', 'string', 'max:500'],
            'channel' => ['nullable', 'in:in_app,email,both'],
            'user_id' => ['nullable', 'integer'],
        ]);

        $reminder = $this->collaboration->createReminder(
            (int) $request->user()->business_id,
            $request->user(),
            $leadId,
            $validated['remind_at'],
            $validated['message'] ?? null,
            $validated['channel'] ?? 'both',
            $validated['user_id'] ?? null,
        );

        return response()->json(['data' => $reminder], 201);
    }

    public function destroyReminder(Request $request, int $id): JsonResponse
    {
        $this->collaboration->cancelReminder(
            (int) $request->user()->business_id,
            $request->user(),
            $id,
        );

        return response()->json(['message' => 'Reminder cancelled']);
    }

    public function boardResourcesSummary(Request $request, int $boardId): JsonResponse
    {
        $summary = $this->boardResources->resourcesSummary(
            (int) $request->user()->business_id,
            $request->user(),
            $boardId,
        );

        return response()->json(['data' => $summary]);
    }

    public function boardResources(Request $request, int $boardId): JsonResponse
    {
        $items = $this->boardResources->listResources(
            (int) $request->user()->business_id,
            $request->user(),
            $boardId,
        );

        return response()->json(['data' => $items]);
    }

    public function boardResourceMembers(Request $request, int $boardId): JsonResponse
    {
        $members = $this->boardResources->listAccessibleMembers(
            (int) $request->user()->business_id,
            $request->user(),
            $boardId,
        );

        return response()->json(['data' => $members]);
    }

    public function storeBoardLinkResource(Request $request, int $boardId): JsonResponse
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'url' => ['required', 'string', 'max:2048'],
            'description' => ['nullable', 'string', 'max:5000'],
            'group_name' => ['nullable', 'string', 'max:100'],
            'visibility' => ['required', 'in:board,team,members,owner_only'],
            'member_user_ids' => ['nullable', 'array'],
            'member_user_ids.*' => ['integer'],
        ]);

        $resource = $this->boardResources->createLinkResource(
            (int) $request->user()->business_id,
            $request->user(),
            $boardId,
            $validated['title'],
            $validated['url'],
            $validated['visibility'],
            $validated['description'] ?? null,
            $validated['group_name'] ?? null,
            $validated['member_user_ids'] ?? [],
        );

        return response()->json(['data' => $resource], 201);
    }

    public function uploadBoardResource(Request $request, int $boardId): JsonResponse
    {
        $validated = $request->validate([
            'file' => ['required', 'file', 'max:10240', 'mimes:jpg,jpeg,png,gif,webp,pdf,doc,docx,xlsx,txt,csv'],
            'title' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'group_name' => ['nullable', 'string', 'max:100'],
            'visibility' => ['required', 'in:board,team,members,owner_only'],
            'member_user_ids' => ['nullable', 'array'],
            'member_user_ids.*' => ['integer'],
        ]);

        $resource = $this->boardResources->uploadResource(
            (int) $request->user()->business_id,
            $request->user(),
            $boardId,
            $request->file('file'),
            $validated['visibility'],
            $validated['title'] ?? null,
            $validated['description'] ?? null,
            $validated['group_name'] ?? null,
            $validated['member_user_ids'] ?? [],
        );

        return response()->json(['data' => $resource], 201);
    }

    public function updateBoardResource(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'title' => ['sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'group_name' => ['nullable', 'string', 'max:100'],
            'visibility' => ['sometimes', 'in:board,team,members,owner_only'],
            'url' => ['sometimes', 'string', 'max:2048'],
            'member_user_ids' => ['nullable', 'array'],
            'member_user_ids.*' => ['integer'],
        ]);

        $resource = $this->boardResources->updateResource(
            (int) $request->user()->business_id,
            $request->user(),
            $id,
            $validated['title'] ?? null,
            array_key_exists('description', $validated) ? $validated['description'] : null,
            $validated['visibility'] ?? null,
            $validated['url'] ?? null,
            array_key_exists('group_name', $validated) ? ($validated['group_name'] ?? null) : null,
            $validated['member_user_ids'] ?? [],
            array_key_exists('group_name', $validated),
        );

        return response()->json(['data' => $resource]);
    }

    public function destroyBoardResource(Request $request, int $id): JsonResponse
    {
        $this->boardResources->deleteResource(
            (int) $request->user()->business_id,
            $request->user(),
            $id,
        );

        return response()->json(['message' => 'Resource deleted']);
    }

    public function recordBoardResourceView(Request $request, int $id): JsonResponse
    {
        $resource = $this->boardResources->recordView(
            (int) $request->user()->business_id,
            $request->user(),
            $id,
        );

        return response()->json(['data' => $resource]);
    }

    public function recordBoardResourceDownload(Request $request, int $id): JsonResponse
    {
        $resource = $this->boardResources->recordDownload(
            (int) $request->user()->business_id,
            $request->user(),
            $id,
        );

        return response()->json(['data' => $resource]);
    }

    public function boardConversationSummary(Request $request, int $boardId): JsonResponse
    {
        $summary = $this->boardConversation->conversationSummary(
            (int) $request->user()->business_id,
            $request->user(),
            $boardId,
        );

        return response()->json(['data' => $summary]);
    }

    public function boardConversationMessages(Request $request, int $boardId): JsonResponse
    {
        $messages = $this->boardConversation->listMessages(
            (int) $request->user()->business_id,
            $request->user(),
            $boardId,
        );

        return response()->json(['data' => $messages]);
    }

    public function storeBoardConversationMessage(Request $request, int $boardId): JsonResponse
    {
        $validated = $request->validate([
            'body' => ['required', 'string', 'max:5000'],
            'parent_id' => ['nullable', 'integer'],
        ]);

        $message = $this->boardConversation->storeMessage(
            (int) $request->user()->business_id,
            $request->user(),
            $boardId,
            $validated['body'],
            $validated['parent_id'] ?? null,
        );

        return response()->json(['data' => $message], 201);
    }

    public function updateBoardConversationMessage(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'body' => ['required', 'string', 'max:5000'],
        ]);

        $message = $this->boardConversation->updateMessage(
            (int) $request->user()->business_id,
            $request->user(),
            $id,
            $validated['body'],
        );

        return response()->json(['data' => $message]);
    }

    public function destroyBoardConversationMessage(Request $request, int $id): JsonResponse
    {
        $this->boardConversation->deleteMessage(
            (int) $request->user()->business_id,
            $request->user(),
            $id,
        );

        return response()->json(['message' => 'Message deleted']);
    }

    public function toggleBoardConversationReaction(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'reaction' => ['nullable', 'string', 'max:32'],
        ]);

        $summary = $this->boardConversation->toggleReaction(
            (int) $request->user()->business_id,
            $request->user(),
            $id,
            $validated['reaction'] ?? null,
        );

        return response()->json(['data' => $summary]);
    }

    public function markBoardConversationRead(Request $request, int $boardId): JsonResponse
    {
        $validated = $request->validate([
            'last_read_message_id' => ['nullable', 'integer'],
        ]);

        $state = $this->boardConversation->markConversationRead(
            (int) $request->user()->business_id,
            $request->user(),
            $boardId,
            $validated['last_read_message_id'] ?? null,
        );

        return response()->json(['data' => $state]);
    }

    public function boardConversationActivity(Request $request, int $boardId): JsonResponse
    {
        $events = $this->boardActivity->listActivity(
            (int) $request->user()->business_id,
            $request->user(),
            $boardId,
        );

        return response()->json(['data' => $events]);
    }

    public function toggleBoardConversationPin(Request $request, int $id): JsonResponse
    {
        $message = $this->boardConversation->togglePin(
            (int) $request->user()->business_id,
            $request->user(),
            $id,
        );

        return response()->json(['data' => $message]);
    }

    public function uploadBoardConversationAttachment(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'file' => ['required', 'file', 'max:10240'],
        ]);

        $attachment = $this->boardConversation->uploadAttachment(
            (int) $request->user()->business_id,
            $request->user(),
            $id,
            $validated['file'],
        );

        return response()->json(['data' => $attachment], 201);
    }

    public function destroyBoardConversationAttachment(Request $request, int $id): JsonResponse
    {
        $this->boardConversation->deleteAttachment(
            (int) $request->user()->business_id,
            $request->user(),
            $id,
        );

        return response()->json(['message' => 'Attachment deleted']);
    }

    public function boardAutomations(Request $request, int $boardId): JsonResponse
    {
        $items = $this->boardAutomations->listAutomations(
            (int) $request->user()->business_id,
            $request->user(),
            $boardId,
        );

        return response()->json(['data' => $items]);
    }

    public function storeBoardAutomation(Request $request, int $boardId): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'trigger_type' => ['required', 'in:stage_entered,status_won,status_lost'],
            'trigger_stage_id' => ['nullable', 'integer'],
            'action_type' => ['required', 'in:conversation_post,conversation_notify'],
            'action_body' => ['required', 'string', 'max:2000'],
        ]);

        $automation = $this->boardAutomations->createAutomation(
            (int) $request->user()->business_id,
            $request->user(),
            $boardId,
            $validated['name'],
            $validated['trigger_type'],
            $validated['action_type'],
            $validated['action_body'],
            $validated['trigger_stage_id'] ?? null,
        );

        return response()->json(['data' => $automation], 201);
    }

    public function syncBoardAutomations(Request $request, int $boardId): JsonResponse
    {
        $validated = $request->validate([
            'rules' => ['required', 'array'],
            'rules.*.trigger_type' => ['required', 'in:stage_entered,status_won,status_lost'],
            'rules.*.trigger_stage_id' => ['nullable', 'integer'],
            'rules.*.action_body' => ['required', 'string', 'max:2000'],
            'rules.*.is_active' => ['nullable', 'boolean'],
        ]);

        $automations = $this->boardAutomations->syncBoardAutomations(
            (int) $request->user()->business_id,
            $request->user(),
            $boardId,
            $validated['rules'],
        );

        return response()->json(['data' => $automations]);
    }

    public function destroyBoardAutomation(Request $request, int $id): JsonResponse
    {
        $this->boardAutomations->deleteAutomation(
            (int) $request->user()->business_id,
            $request->user(),
            $id,
        );

        return response()->json(['message' => 'Automation deleted']);
    }

    public function boardTemplates(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'workspace' => ['nullable', 'in:pipeline,estimates'],
        ]);

        $templates = $this->boardTemplates->listTemplates(
            (int) $request->user()->business_id,
            $request->user(),
            $validated['workspace'] ?? 'pipeline',
        );

        return response()->json(['data' => $templates]);
    }

    public function storeBoardTemplate(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:2000'],
            'workspace' => ['nullable', 'in:pipeline,estimates'],
            'stages' => ['nullable', 'array'],
            'labels' => ['nullable', 'array'],
            'resources' => ['nullable', 'array'],
            'automations' => ['nullable', 'array'],
        ]);

        $template = $this->boardTemplates->createTemplate(
            (int) $request->user()->business_id,
            $request->user(),
            $validated['name'],
            $validated['workspace'] ?? 'pipeline',
            $validated['description'] ?? null,
            $validated['stages'] ?? null,
            $validated['labels'] ?? null,
            $validated['resources'] ?? null,
            $validated['automations'] ?? null,
        );

        return response()->json(['data' => $template], 201);
    }

    public function applyBoardTemplate(Request $request, int $boardId): JsonResponse
    {
        $validated = $request->validate([
            'template_id' => ['required', 'integer'],
        ]);

        $this->boardTemplates->applyTemplate(
            (int) $request->user()->business_id,
            $request->user(),
            $boardId,
            (int) $validated['template_id'],
        );

        return response()->json(['message' => 'Template applied']);
    }

    public function boardProgressSummary(Request $request, int $boardId): JsonResponse
    {
        $validated = $request->validate([
            'period' => ['nullable', 'in:day,week,month,quarter,year,custom'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'stage_ids' => ['nullable', 'array'],
            'stage_ids.*' => ['integer'],
        ]);

        $summary = $this->boardProgress->progressSummary(
            (int) $request->user()->business_id,
            $request->user(),
            $boardId,
            $validated['period'] ?? 'month',
            $validated['from'] ?? null,
            $validated['to'] ?? null,
            $validated['stage_ids'] ?? null,
        );

        return response()->json(['data' => $summary]);
    }

    public function boardProgressQuery(Request $request, int $boardId): JsonResponse
    {
        $validated = $request->validate([
            'period' => ['nullable', 'in:day,week,month,quarter,year,custom'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'stage_ids' => ['nullable', 'array'],
            'stage_ids.*' => ['integer'],
            'member_ids' => ['nullable', 'array'],
            'member_ids.*' => ['integer'],
            'metrics' => ['nullable', 'array'],
            'metrics.*' => ['string'],
            'planning_level' => ['nullable', 'in:decade,five_year,year,quarter,month,week,day'],
        ]);

        $result = $this->boardProgress->progressQuery(
            (int) $request->user()->business_id,
            $request->user(),
            $boardId,
            $validated,
        );

        return response()->json(['data' => $result]);
    }

    public function myBoardProgress(Request $request, int $boardId): JsonResponse
    {
        $validated = $request->validate([
            'period' => ['nullable', 'in:day,week,month,quarter,year,custom'],
        ]);

        $result = $this->boardProgress->myProgress(
            (int) $request->user()->business_id,
            $request->user(),
            $boardId,
            $validated['period'] ?? 'month',
        );

        return response()->json(['data' => $result]);
    }

    public function boardProgressConfig(Request $request, int $boardId): JsonResponse
    {
        $config = $this->boardProgress->getProgressConfig(
            (int) $request->user()->business_id,
            $request->user(),
            $boardId,
        );

        return response()->json(['data' => $config]);
    }

    public function updateBoardProgressConfig(Request $request, int $boardId): JsonResponse
    {
        $validated = $request->validate([
            'charts' => ['nullable', 'array'],
            'funnel_mode' => ['nullable', 'in:count,value'],
        ]);

        $config = $this->boardProgress->saveProgressConfig(
            (int) $request->user()->business_id,
            $request->user(),
            $boardId,
            $validated,
        );

        return response()->json(['data' => $config]);
    }

    public function decomposeBoardTargetPreview(Request $request, int $boardId): JsonResponse
    {
        $validated = $request->validate([
            'planning_level' => ['required', 'in:decade,five_year,year,quarter,month,week,day'],
            'target_value' => ['required', 'numeric', 'min:0'],
            'anchor_start' => ['nullable', 'date'],
            'anchor_end' => ['nullable', 'date', 'after_or_equal:anchor_start'],
            'stage_ids' => ['required', 'array', 'min:1'],
            'stage_ids.*' => ['integer'],
            'member_user_ids' => ['nullable', 'array'],
            'member_user_ids.*' => ['integer'],
            'decomposition_mode' => ['nullable', 'in:equal,velocity,hybrid'],
        ]);

        $preview = $this->boardProgress->decomposePreview(
            (int) $request->user()->business_id,
            $request->user(),
            $boardId,
            $validated,
        );

        return response()->json(['data' => $preview]);
    }

    public function boardTargets(Request $request, int $boardId): JsonResponse
    {
        $targets = $this->boardProgress->listTargets(
            (int) $request->user()->business_id,
            $request->user(),
            $boardId,
        );

        return response()->json(['data' => $targets]);
    }

    public function storeBoardTarget(Request $request, int $boardId): JsonResponse
    {
        $validated = $request->validate([
            'type' => ['required', 'in:kpi,goal,objective,key_result'],
            'goal_tag' => ['nullable', 'in:kpi,goal,objective,deliverable,decision'],
            'parent_id' => ['nullable', 'integer'],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'metric_key' => ['required', 'string'],
            'target_value' => ['required', 'numeric', 'min:0'],
            'unit' => ['required', 'in:count,currency,percent,days'],
            'period_type' => ['required', 'in:day,week,month,quarter,year,custom'],
            'planning_level' => ['nullable', 'in:decade,five_year,year,quarter,month,week,day'],
            'anchor_start' => ['nullable', 'date'],
            'anchor_end' => ['nullable', 'date', 'after_or_equal:anchor_start'],
            'period_start' => ['nullable', 'date'],
            'period_end' => ['nullable', 'date', 'after_or_equal:period_start'],
            'scope' => ['required', 'in:board,member'],
            'member_user_id' => ['nullable', 'integer'],
            'stage_id' => ['required', 'integer'],
            'stage_ids' => ['nullable', 'array'],
            'decomposition_mode' => ['nullable', 'in:equal,velocity,hybrid'],
            'weight' => ['nullable', 'integer', 'min:1', 'max:100'],
            'status' => ['nullable', 'in:draft,active,paused,completed,archived'],
            'allocations' => ['nullable', 'array'],
            'key_results' => ['nullable', 'array'],
            'key_results.*.title' => ['required_with:key_results', 'string', 'max:255'],
            'key_results.*.metric_key' => ['required_with:key_results', 'string'],
            'key_results.*.target_value' => ['required_with:key_results', 'numeric', 'min:0'],
            'key_results.*.unit' => ['required_with:key_results', 'in:count,currency,percent,days'],
            'key_results.*.scope' => ['nullable', 'in:board,member'],
            'key_results.*.member_user_id' => ['nullable', 'integer'],
            'key_results.*.stage_id' => ['nullable', 'integer'],
        ]);

        $target = $this->boardProgress->storeTarget(
            (int) $request->user()->business_id,
            $request->user(),
            $boardId,
            $validated,
        );

        return response()->json(['data' => $target], 201);
    }

    public function updateBoardTarget(Request $request, int $targetId): JsonResponse
    {
        $validated = $request->validate([
            'title' => ['sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'metric_key' => ['sometimes', 'string'],
            'target_value' => ['sometimes', 'numeric', 'min:0'],
            'unit' => ['sometimes', 'in:count,currency,percent,days'],
            'period_type' => ['sometimes', 'in:day,week,month,quarter,year,custom'],
            'planning_level' => ['sometimes', 'in:decade,five_year,year,quarter,month,week,day'],
            'anchor_start' => ['nullable', 'date'],
            'anchor_end' => ['nullable', 'date', 'after_or_equal:anchor_start'],
            'period_start' => ['nullable', 'date'],
            'period_end' => ['nullable', 'date', 'after_or_equal:period_start'],
            'scope' => ['sometimes', 'in:board,member'],
            'member_user_id' => ['nullable', 'integer'],
            'stage_id' => ['sometimes', 'integer'],
            'decomposition_mode' => ['nullable', 'in:equal,velocity,hybrid'],
            'weight' => ['nullable', 'integer', 'min:1', 'max:100'],
            'status' => ['nullable', 'in:draft,active,paused,completed,archived'],
            'allocations' => ['nullable', 'array'],
        ]);

        $target = $this->boardProgress->updateTarget(
            (int) $request->user()->business_id,
            $request->user(),
            $targetId,
            $validated,
        );

        return response()->json(['data' => $target]);
    }

    public function destroyBoardTarget(Request $request, int $targetId): JsonResponse
    {
        $this->boardProgress->archiveTarget(
            (int) $request->user()->business_id,
            $request->user(),
            $targetId,
        );

        return response()->json(null, 204);
    }

    public function exportBoardProgress(Request $request, int $boardId): JsonResponse
    {
        $validated = $request->validate([
            'period' => ['nullable', 'in:day,week,month,quarter,year,custom'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ]);

        $summary = $this->boardProgress->progressSummary(
            (int) $request->user()->business_id,
            $request->user(),
            $boardId,
            $validated['period'] ?? 'month',
            $validated['from'] ?? null,
            $validated['to'] ?? null,
        );

        return response()->json([
            'data' => $summary,
            'exported_at' => now()->toIso8601String(),
        ]);
    }
}
