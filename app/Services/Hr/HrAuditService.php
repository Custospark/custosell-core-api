<?php

declare(strict_types=1);

namespace App\Services\Hr;

use App\Models\Hr\HrAuditLog;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class HrAuditService
{
    public function record(
        int $businessId,
        ?int $actorUserId,
        string $action,
        string $subjectType,
        int $subjectId,
        ?array $meta = null,
    ): HrAuditLog {
        return HrAuditLog::create([
            'business_id' => $businessId,
            'actor_user_id' => $actorUserId,
            'action' => $action,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'meta_json' => $meta,
        ]);
    }

    public function list(int $businessId, array $filters = [], int $perPage = 50): LengthAwarePaginator
    {
        $query = HrAuditLog::query()
            ->where('business_id', $businessId)
            ->with('actor:id,name,email')
            ->orderByDesc('created_at');

        if (! empty($filters['action'])) {
            $query->where('action', $filters['action']);
        }

        if (! empty($filters['subject_type'])) {
            $query->where('subject_type', $filters['subject_type']);
        }

        if (! empty($filters['subject_id'])) {
            $query->where('subject_id', (int) $filters['subject_id']);
        }

        return $query->paginate(min(max($perPage, 1), 200));
    }
}
