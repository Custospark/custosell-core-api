<?php

declare(strict_types=1);

namespace App\Services\Hr;

use App\Models\Hr\HrOnboardingTask;
use App\Models\Hr\HrOnboardingTemplate;
use App\Models\Hr\HrReview;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

class HrTalentService
{
    public const TASK_STATUSES = ['pending', 'done', 'skipped'];

    public const REVIEW_STATUSES = ['draft', 'submitted', 'completed'];

    public function __construct(
        protected HrEmployeeService $employees,
        protected HrAuditService $audit,
    ) {}

    public function listTemplates(int $businessId): Collection
    {
        return HrOnboardingTemplate::query()
            ->where('business_id', $businessId)
            ->orderBy('name')
            ->get();
    }

    public function createTemplate(int $businessId, array $data, ?int $actorUserId = null): HrOnboardingTemplate
    {
        $template = HrOnboardingTemplate::create([
            'business_id' => $businessId,
            'name' => $data['name'],
            'tasks_json' => $data['tasks_json'] ?? $data['tasks'] ?? [],
        ]);

        $this->audit->record($businessId, $actorUserId, 'onboarding_template.created', 'hr_onboarding_template', $template->id);

        return $template;
    }

    public function updateTemplate(int $businessId, int $id, array $data, ?int $actorUserId = null): HrOnboardingTemplate
    {
        $template = $this->findTemplateOrFail($businessId, $id);

        if (isset($data['tasks'])) {
            $data['tasks_json'] = $data['tasks'];
        }

        $template->fill(array_intersect_key($data, array_flip(['name', 'tasks_json'])));
        $template->save();

        $this->audit->record($businessId, $actorUserId, 'onboarding_template.updated', 'hr_onboarding_template', $template->id);

        return $template->fresh();
    }

    public function findTemplateOrFail(int $businessId, int $id): HrOnboardingTemplate
    {
        $template = HrOnboardingTemplate::query()
            ->where('business_id', $businessId)
            ->whereKey($id)
            ->first();

        if (! $template) {
            abort(404, 'Onboarding template not found');
        }

        return $template;
    }

    public function assignOnboarding(int $businessId, int $employeeId, ?int $templateId = null, ?int $actorUserId = null): Collection
    {
        $this->employees->findOrFail($businessId, $employeeId);

        $tasksSpec = [];
        if ($templateId !== null) {
            $template = $this->findTemplateOrFail($businessId, $templateId);
            $tasksSpec = is_array($template->tasks_json) ? $template->tasks_json : [];
        }

        $created = collect();

        foreach ($tasksSpec as $spec) {
            $title = is_array($spec) ? ($spec['title'] ?? $spec['name'] ?? null) : (string) $spec;
            if (! $title) {
                continue;
            }

            $created->push(HrOnboardingTask::create([
                'business_id' => $businessId,
                'employee_id' => $employeeId,
                'template_id' => $templateId,
                'title' => $title,
                'status' => 'pending',
                'due_date' => is_array($spec) ? ($spec['due_date'] ?? null) : null,
            ]));
        }

        $this->audit->record($businessId, $actorUserId, 'onboarding.assigned', 'hr_employee', $employeeId, [
            'template_id' => $templateId,
            'task_count' => $created->count(),
        ]);

        return $created;
    }

    public function createTask(int $businessId, array $data, ?int $actorUserId = null): HrOnboardingTask
    {
        $this->employees->findOrFail($businessId, (int) $data['employee_id']);

        $task = HrOnboardingTask::create([
            'business_id' => $businessId,
            'employee_id' => $data['employee_id'],
            'template_id' => $data['template_id'] ?? null,
            'title' => $data['title'],
            'status' => $data['status'] ?? 'pending',
            'due_date' => $data['due_date'] ?? null,
        ]);

        $this->audit->record($businessId, $actorUserId, 'onboarding_task.created', 'hr_onboarding_task', $task->id);

        return $task;
    }

    public function updateTask(int $businessId, int $id, array $data, ?int $actorUserId = null): HrOnboardingTask
    {
        $task = $this->findTaskOrFail($businessId, $id);

        if (isset($data['status']) && ! in_array($data['status'], self::TASK_STATUSES, true)) {
            throw ValidationException::withMessages([
                'status' => 'Invalid onboarding task status.',
            ]);
        }

        if (isset($data['status']) && in_array($data['status'], ['done', 'skipped'], true) && empty($data['completed_at'])) {
            $data['completed_at'] = now();
        }

        if (isset($data['status']) && $data['status'] === 'pending') {
            $data['completed_at'] = null;
        }

        $task->fill(array_intersect_key($data, array_flip(['title', 'status', 'due_date', 'completed_at'])));
        $task->save();

        $this->audit->record($businessId, $actorUserId, 'onboarding_task.updated', 'hr_onboarding_task', $task->id);

        return $task->fresh();
    }

    public function listTasks(int $businessId, ?int $employeeId = null): Collection
    {
        $query = HrOnboardingTask::query()
            ->where('business_id', $businessId)
            ->with('employee:id,first_name,last_name,employee_number')
            ->orderBy('due_date');

        if ($employeeId !== null) {
            $query->where('employee_id', $employeeId);
        }

        return $query->get();
    }

    public function findTaskOrFail(int $businessId, int $id): HrOnboardingTask
    {
        $task = HrOnboardingTask::query()
            ->where('business_id', $businessId)
            ->whereKey($id)
            ->first();

        if (! $task) {
            abort(404, 'Onboarding task not found');
        }

        return $task;
    }

    public function createReview(int $businessId, array $data, ?int $actorUserId = null): HrReview
    {
        $this->employees->findOrFail($businessId, (int) $data['employee_id']);

        $reviewerId = (int) ($data['reviewer_user_id'] ?? $actorUserId);
        $reviewer = User::query()
            ->where('business_id', $businessId)
            ->whereKey($reviewerId)
            ->first();

        if (! $reviewer) {
            throw ValidationException::withMessages([
                'reviewer_user_id' => 'Reviewer not found in this business.',
            ]);
        }

        $review = HrReview::create([
            'business_id' => $businessId,
            'employee_id' => $data['employee_id'],
            'reviewer_user_id' => $reviewerId,
            'period_label' => $data['period_label'],
            'status' => $data['status'] ?? 'draft',
            'rating' => $data['rating'] ?? null,
            'strengths' => $data['strengths'] ?? null,
            'improvements' => $data['improvements'] ?? null,
            'notes' => $data['notes'] ?? null,
        ]);

        $this->audit->record($businessId, $actorUserId, 'review.created', 'hr_review', $review->id);

        return $review->load(['employee:id,first_name,last_name', 'reviewer:id,name']);
    }

    public function updateReview(int $businessId, int $id, array $data, ?int $actorUserId = null): HrReview
    {
        $review = $this->findReviewOrFail($businessId, $id);

        if (isset($data['status']) && ! in_array($data['status'], self::REVIEW_STATUSES, true)) {
            throw ValidationException::withMessages([
                'status' => 'Invalid review status.',
            ]);
        }

        $review->fill(array_intersect_key($data, array_flip([
            'period_label', 'status', 'rating', 'strengths', 'improvements', 'notes',
        ])));
        $review->save();

        $this->audit->record($businessId, $actorUserId, 'review.updated', 'hr_review', $review->id);

        return $review->fresh(['employee:id,first_name,last_name', 'reviewer:id,name']);
    }

    public function listReviews(int $businessId, ?int $employeeId = null): Collection
    {
        $query = HrReview::query()
            ->where('business_id', $businessId)
            ->with(['employee:id,first_name,last_name,employee_number', 'reviewer:id,name'])
            ->orderByDesc('created_at');

        if ($employeeId !== null) {
            $query->where('employee_id', $employeeId);
        }

        return $query->get();
    }

    public function findReviewOrFail(int $businessId, int $id): HrReview
    {
        $review = HrReview::query()
            ->where('business_id', $businessId)
            ->with(['employee', 'reviewer:id,name'])
            ->whereKey($id)
            ->first();

        if (! $review) {
            abort(404, 'Review not found');
        }

        return $review;
    }
}
