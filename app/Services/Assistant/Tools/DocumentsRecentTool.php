<?php

declare(strict_types=1);

namespace App\Services\Assistant\Tools;

use App\Models\Document;
use App\Models\DocumentCabinet;
use App\Models\User;
use App\Services\ModuleAccessService;

class DocumentsRecentTool extends AbstractAssistantTool
{
    public function __construct(ModuleAccessService $moduleAccess)
    {
        parent::__construct($moduleAccess);
    }

    public function name(): string
    {
        return 'documents_recent';
    }

    public function description(): string
    {
        return 'Document counts by cabinet. Counts only - titles stay behind cabinet access rules.';
    }

    public function parameters(): array
    {
        return [];
    }

    public function requiredModule(): string
    {
        return 'documents';
    }

    public function endpoint(): string
    {
        return 'GET /documents/cabinets';
    }

    public function execute(User $user, array $args): array
    {
        if ($denied = $this->denied($user)) {
            return $denied;
        }

        $businessId = $this->businessId($user);
        $cabinets = DocumentCabinet::where('business_id', $businessId)
            ->get(['id', 'name'])
            ->map(fn ($cabinet) => [
                'name' => $cabinet->name,
                'documents' => Document::where('business_id', $businessId)
                    ->where('cabinet_id', $cabinet->id)
                    ->count(),
            ])
            ->values()
            ->all();

        $this->audit($user);

        return [
            'cabinets' => $cabinets,
            'total_documents' => Document::where('business_id', $businessId)->count(),
        ];
    }
}
