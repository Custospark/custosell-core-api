<?php

declare(strict_types=1);

namespace App\Services\Assistant\Tools;

use App\Models\User;
use App\Services\ChartOfAccountService;
use App\Services\ModuleAccessService;

class AccountingSnapshotTool extends AbstractAssistantTool
{
    public function __construct(
        ModuleAccessService $moduleAccess,
        private ChartOfAccountService $accounts,
    ) {
        parent::__construct($moduleAccess);
    }

    public function name(): string
    {
        return 'accounting_snapshot';
    }

    public function description(): string
    {
        return 'Chart of accounts size by type. Names and balances only, no journal lines.';
    }

    public function parameters(): array
    {
        return [];
    }

    public function requiredModule(): string
    {
        return 'accounting';
    }

    public function endpoint(): string
    {
        return 'GET /chart-of-accounts/tree';
    }

    public function execute(User $user, array $args): array
    {
        if ($denied = $this->denied($user)) {
            return $denied;
        }

        $tree = $this->accounts->getTree($this->businessId($user));
        $byType = [];
        $walk = function ($nodes) use (&$walk, &$byType) {
            foreach ($nodes as $node) {
                $node = is_array($node) ? $node : (array) $node;
                $type = (string) ($node['type'] ?? $node['account_type'] ?? 'unknown');
                $byType[$type] = ($byType[$type] ?? 0) + 1;
                foreach ((array) ($node['children'] ?? []) as $child) {
                    $walk([$child]);
                }
            }
        };
        $walk(is_array($tree) ? $tree : $tree->toArray());

        $this->audit($user);

        return ['accounts_by_type' => $byType];
    }
}
