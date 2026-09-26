<?php

declare(strict_types=1);

namespace App\Services\Assistant\Tools;

use App\Models\User;
use App\Services\Hr\HrEmployeeService;
use App\Services\Hr\HrLeaveService;
use App\Services\Hr\HrOrgService;
use App\Services\ModuleAccessService;

class HrTodayTool extends AbstractAssistantTool
{
    public function __construct(
        ModuleAccessService $moduleAccess,
        private HrEmployeeService $employees,
        private HrOrgService $org,
        private HrLeaveService $leave,
    ) {
        parent::__construct($moduleAccess);
    }

    public function name(): string
    {
        return 'hr_today';
    }

    public function description(): string
    {
        return 'Workforce counts: headcount, departments, pending leave requests. No names or pay.';
    }

    public function parameters(): array
    {
        return [];
    }

    public function requiredModule(): string
    {
        return 'hr';
    }

    public function endpoint(): string
    {
        return 'GET /hr/employees';
    }

    public function execute(User $user, array $args): array
    {
        if ($denied = $this->denied($user)) {
            return $denied;
        }

        $businessId = $this->businessId($user);
        $pendingLeave = $this->leave
            ->listRequests($businessId)
            ->filter(fn ($request) => in_array($request->status, ['pending', 'submitted'], true))
            ->count();

        $this->audit($user);

        return [
            'headcount' => $this->employees->list($businessId)->total(),
            'departments' => $this->org->listDepartments($businessId)->count(),
            'pending_leave_requests' => $pendingLeave,
        ];
    }
}
