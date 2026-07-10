<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Hr;

use App\Http\Controllers\Controller;
use App\Services\Hr\HrTalentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class HrTalentController extends Controller
{
    public function __construct(
        protected HrTalentService $talent,
    ) {}

    public function indexTemplates(Request $request): JsonResponse
    {
        return response()->json([
            'data' => $this->talent->listTemplates((int) $request->user()->business_id),
        ]);
    }

    public function storeTemplate(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'tasks_json' => ['nullable', 'array'],
            'tasks' => ['nullable', 'array'],
        ]);

        $template = $this->talent->createTemplate(
            (int) $request->user()->business_id,
            $validated,
            $request->user()->id,
        );

        return response()->json(['data' => $template], 201);
    }

    public function updateTemplate(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'tasks_json' => ['nullable', 'array'],
            'tasks' => ['nullable', 'array'],
        ]);

        $template = $this->talent->updateTemplate(
            (int) $request->user()->business_id,
            $id,
            $validated,
            $request->user()->id,
        );

        return response()->json(['data' => $template]);
    }

    public function assignOnboarding(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'employee_id' => ['required', 'integer'],
            'template_id' => ['nullable', 'integer'],
        ]);

        $tasks = $this->talent->assignOnboarding(
            (int) $request->user()->business_id,
            (int) $validated['employee_id'],
            isset($validated['template_id']) ? (int) $validated['template_id'] : null,
            $request->user()->id,
        );

        return response()->json(['data' => $tasks], 201);
    }

    public function indexTasks(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'employee_id' => ['nullable', 'integer'],
        ]);

        return response()->json([
            'data' => $this->talent->listTasks(
                (int) $request->user()->business_id,
                isset($validated['employee_id']) ? (int) $validated['employee_id'] : null,
            ),
        ]);
    }

    public function storeTask(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'employee_id' => ['required', 'integer'],
            'template_id' => ['nullable', 'integer'],
            'title' => ['required', 'string', 'max:255'],
            'status' => ['nullable', 'in:pending,done,skipped'],
            'due_date' => ['nullable', 'date'],
        ]);

        $task = $this->talent->createTask(
            (int) $request->user()->business_id,
            $validated,
            $request->user()->id,
        );

        return response()->json(['data' => $task], 201);
    }

    public function updateTask(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'title' => ['sometimes', 'string', 'max:255'],
            'status' => ['nullable', 'in:pending,done,skipped'],
            'due_date' => ['nullable', 'date'],
            'completed_at' => ['nullable', 'date'],
        ]);

        $task = $this->talent->updateTask(
            (int) $request->user()->business_id,
            $id,
            $validated,
            $request->user()->id,
        );

        return response()->json(['data' => $task]);
    }

    public function indexReviews(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'employee_id' => ['nullable', 'integer'],
        ]);

        return response()->json([
            'data' => $this->talent->listReviews(
                (int) $request->user()->business_id,
                isset($validated['employee_id']) ? (int) $validated['employee_id'] : null,
            ),
        ]);
    }

    public function storeReview(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'employee_id' => ['required', 'integer'],
            'reviewer_user_id' => ['nullable', 'integer'],
            'period_label' => ['required', 'string', 'max:120'],
            'status' => ['nullable', 'in:draft,submitted,completed'],
            'rating' => ['nullable', 'numeric', 'min:0', 'max:5'],
            'strengths' => ['nullable', 'string'],
            'improvements' => ['nullable', 'string'],
            'notes' => ['nullable', 'string'],
        ]);

        $review = $this->talent->createReview(
            (int) $request->user()->business_id,
            $validated,
            $request->user()->id,
        );

        return response()->json(['data' => $review], 201);
    }

    public function updateReview(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'period_label' => ['sometimes', 'string', 'max:120'],
            'status' => ['nullable', 'in:draft,submitted,completed'],
            'rating' => ['nullable', 'numeric', 'min:0', 'max:5'],
            'strengths' => ['nullable', 'string'],
            'improvements' => ['nullable', 'string'],
            'notes' => ['nullable', 'string'],
        ]);

        $review = $this->talent->updateReview(
            (int) $request->user()->business_id,
            $id,
            $validated,
            $request->user()->id,
        );

        return response()->json(['data' => $review]);
    }
}
