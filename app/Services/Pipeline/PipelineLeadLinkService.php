<?php

declare(strict_types=1);

namespace App\Services\Pipeline;

use App\Models\PipelineLeadLink;
use App\Models\User;
use App\Services\Pipeline\PipelineBoardLookupService;
use App\Services\Pipeline\PipelineBoardPermissionService;

class PipelineLeadLinkService
{
    public function __construct(
        protected PipelineBoardLookupService $lookup,
        protected PipelineBoardPermissionService $permission,
    ) {}

    public function createLeadLink(int $businessId, User $user, int $leadId, array $data): PipelineLeadLink
    {
        $lead = $this->lookup->findLeadForUser($user, $leadId);
        $this->permission->assertCanEditBoard($user, $lead->board);

        if (!empty($data['linked_lead_id'])) {
            $this->lookup->findLeadForUser($user, (int) $data['linked_lead_id']);
        }

        return PipelineLeadLink::create([
            'lead_id' => $leadId,
            'linked_lead_id' => $data['linked_lead_id'] ?? null,
            'linked_board_id' => $data['linked_board_id'] ?? null,
            'label' => $data['label'] ?? null,
            'created_by' => $user->id,
        ]);
    }

    public function deleteLeadLink(int $businessId, User $user, int $linkId): void
    {
        $link = PipelineLeadLink::query()
            ->where('id', $linkId)
            ->firstOrFail();

        $lead = $this->lookup->findLeadForUser($user, $link->lead_id);
        $this->permission->assertCanEditBoard($user, $lead->board);

        $link->delete();
    }
}
