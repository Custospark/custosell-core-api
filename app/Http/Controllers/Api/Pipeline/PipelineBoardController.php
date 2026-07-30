<?php

namespace App\Http\Controllers\Api\Pipeline;

use App\Http\Controllers\Controller;
use App\Http\Resources\PipelineBoardMetaFieldResource;
use App\Http\Resources\PipelineBoardResource;
use App\Models\PipelineBoard;
use App\Models\User;
use App\Services\BoardMemberLimitService;
use App\Services\Contracts\SubscriptionServiceInterface;
use App\Services\Pipeline\PipelineLeadImportService;
use App\Services\PipelineService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PipelineBoardController extends Controller
{
    public function __construct(
        protected PipelineService $pipelineService,
        protected PipelineLeadImportService $leadImport,
        protected BoardMemberLimitService $boardMemberLimit,
        protected SubscriptionServiceInterface $subscriptionService,
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
            'members.*.send_notification' => ['nullable', 'boolean'],
            'workspace' => ['nullable', 'in:pipeline,estimates'],
        ]);

        if ($validated['visibility'] === 'shared' && (!empty($validated['members']) || !empty($validated['member_ids']))) {
            $subscription = $this->subscriptionService->getByBusiness((int) $request->user()->business_id);
            $incomingCount = !empty($validated['members']) ? count($validated['members']) : count($validated['member_ids']);
            $this->boardMemberLimit->assertWithinBoardMemberLimit($subscription, 0, $incomingCount);
        }

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
            'members.*.send_notification' => ['nullable', 'boolean'],
        ]);

        if ($validated['visibility'] === 'shared' && (!empty($validated['members']) || !empty($validated['member_ids']))) {
            $board = $this->pipelineService->getBoard((int) $request->user()->business_id, $request->user(), $id);
            $currentMemberCount = $board->members->count();
            $subscription = $this->subscriptionService->getByBusiness((int) $board->business_id);
            $incomingCount = !empty($validated['members']) ? count($validated['members']) : count($validated['member_ids']);
            $this->boardMemberLimit->assertWithinBoardMemberLimit($subscription, $currentMemberCount, $incomingCount);
        }

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

    public function duplicateBoard(Request $request, int $id): JsonResponse
    {
        $board = $this->pipelineService->duplicateBoard(
            (int) $request->user()->business_id,
            $request->user(),
            $id,
        );

        return response()->json(['data' => new PipelineBoardResource($board)]);
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
        if (function_exists('ini_set')) {
            @ini_set('max_execution_time', '600');
            @ini_set('memory_limit', '512M');
        }

        $results = $this->leadImport->import(
            (int) $request->user()->business_id,
            $request->user(),
            $board,
            $request->file('file')->getPathname(),
        );

        return response()->json($results);
    }
}
