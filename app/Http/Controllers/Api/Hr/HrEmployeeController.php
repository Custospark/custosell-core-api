<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Hr;

use App\Http\Controllers\Controller;
use App\Models\Hr\HrEmployee;
use App\Models\User;
use App\Services\Contracts\RoleServiceInterface;
use App\Services\Hr\HrEmployeeService;
use App\Services\Hr\HrStaffMirrorService;
use App\Services\ModuleAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class HrEmployeeController extends Controller
{
    public function __construct(
        protected HrEmployeeService $employees,
        protected HrStaffMirrorService $mirror,
        protected RoleServiceInterface $roles,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['nullable', 'string'],
            'department_id' => ['nullable', 'integer'],
            'employment_type' => ['nullable', 'string'],
            'q' => ['nullable', 'string', 'max:200'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
            'sync_staff' => ['nullable', 'boolean'],
        ]);

        $businessId = (int) $request->user()->business_id;

        // Heal gaps so existing Settings staff appear in People.
        if ($request->boolean('sync_staff', true)) {
            $this->mirror->backfillBusiness($businessId, $request->user()->id);
        }

        $paginator = $this->employees->list(
            $businessId,
            $validated,
            (int) ($validated['per_page'] ?? 50),
        );

        return response()->json([
            'data' => $paginator->items(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ]);
    }

    public function syncStaff(Request $request): JsonResponse
    {
        $created = $this->mirror->backfillBusiness(
            (int) $request->user()->business_id,
            $request->user()->id,
        );

        return response()->json([
            'data' => ['created' => $created],
        ]);
    }

    public function accountOptions(Request $request): JsonResponse
    {
        $businessId = (int) $request->user()->business_id;
        $linkedUserIds = HrEmployee::query()
            ->where('business_id', $businessId)
            ->whereNotNull('user_id')
            ->pluck('user_id')
            ->all();

        $unlinkedUsers = User::query()
            ->where('business_id', $businessId)
            ->when($linkedUserIds !== [], fn ($q) => $q->whereNotIn('id', $linkedUserIds))
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'phone']);

        return response()->json([
            'data' => [
                'roles' => $this->roles->getAll($businessId)->values(),
                'unlinked_users' => $unlinkedUsers,
                'assignable_modules' => ModuleAccessService::assignableModuleSlugs(),
            ],
        ]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        return response()->json([
            'data' => $this->employees->findOrFail((int) $request->user()->business_id, $id),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate($this->employeeRules());

        $employee = $this->employees->create(
            (int) $request->user()->business_id,
            $validated,
            $request->user()->id,
        );

        return response()->json(['data' => $employee], 201);
    }

    public function storeWithAccount(Request $request): JsonResponse
    {
        $validated = $request->validate(array_merge(
            $this->employeeRules(requireEmail: false),
            $this->accountRules(),
        ));

        [$employeeData, $accountData] = $this->splitEmployeeAndAccount($validated);

        $employee = $this->employees->createWithAccount(
            (int) $request->user()->business_id,
            $employeeData,
            $accountData,
            $request->user()->id,
        );

        return response()->json(['data' => $employee], 201);
    }

    public function createAccount(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate($this->accountRules());

        $accountData = [
            'name' => $validated['account_name']
                ?? trim(($request->input('first_name', '').' '.$request->input('last_name', ''))),
            'email' => $validated['email'],
            'phone' => $validated['phone'] ?? null,
            'password' => $validated['password'],
            'role_id' => $validated['role_id'] ?? null,
            'modules' => $validated['modules'] ?? [],
        ];

        if ($accountData['name'] === '') {
            $employee = $this->employees->findOrFail((int) $request->user()->business_id, $id);
            $accountData['name'] = trim($employee->first_name.' '.$employee->last_name);
        }

        $employee = $this->employees->createAccountForEmployee(
            (int) $request->user()->business_id,
            $id,
            $accountData,
            $request->user()->id,
        );

        return response()->json(['data' => $employee], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'employee_number' => ['sometimes', 'string', 'max:64'],
            'first_name' => ['sometimes', 'string', 'max:120'],
            'last_name' => ['sometimes', 'string', 'max:120'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:64'],
            'department_id' => ['nullable', 'integer'],
            'position_id' => ['nullable', 'integer'],
            'manager_employee_id' => ['nullable', 'integer'],
            'employment_type' => ['nullable', 'in:full_time,part_time,contract,casual'],
            'status' => ['nullable', 'in:onboarding,active,on_leave,terminated'],
            'hire_date' => ['nullable', 'date'],
            'termination_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string'],
        ]);

        $employee = $this->employees->update(
            (int) $request->user()->business_id,
            $id,
            $validated,
            $request->user()->id,
        );

        return response()->json(['data' => $employee]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'remove_account' => ['nullable', 'boolean'],
        ]);

        $this->employees->delete(
            (int) $request->user()->business_id,
            $id,
            $request->user()->id,
            (bool) ($validated['remove_account'] ?? false),
        );

        return response()->json(null, 204);
    }

    public function linkUser(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'user_id' => ['required', 'integer'],
        ]);

        $employee = $this->employees->linkUser(
            (int) $request->user()->business_id,
            $id,
            (int) $validated['user_id'],
            $request->user()->id,
        );

        return response()->json(['data' => $employee]);
    }

    public function unlinkUser(Request $request, int $id): JsonResponse
    {
        $employee = $this->employees->unlinkUser(
            (int) $request->user()->business_id,
            $id,
            $request->user()->id,
        );

        return response()->json(['data' => $employee]);
    }

    public function removeAccount(Request $request, int $id): JsonResponse
    {
        $employee = $this->employees->removeAccount(
            (int) $request->user()->business_id,
            $id,
            $request->user()->id,
        );

        return response()->json(['data' => $employee]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function employeeRules(bool $requireEmail = false): array
    {
        return [
            'employee_number' => ['required', 'string', 'max:64'],
            'first_name' => ['required', 'string', 'max:120'],
            'last_name' => ['required', 'string', 'max:120'],
            'email' => [$requireEmail ? 'required' : 'nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:64'],
            'user_id' => ['nullable', 'integer'],
            'department_id' => ['nullable', 'integer'],
            'position_id' => ['nullable', 'integer'],
            'manager_employee_id' => ['nullable', 'integer'],
            'employment_type' => ['nullable', 'in:full_time,part_time,contract,casual'],
            'status' => ['nullable', 'in:onboarding,active,on_leave,terminated'],
            'hire_date' => ['nullable', 'date'],
            'termination_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function accountRules(): array
    {
        return [
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:6', 'confirmed'],
            'role_id' => ['nullable', 'integer', 'exists:roles,id'],
            'modules' => ['sometimes', 'array'],
            'modules.*' => ['string', Rule::in(ModuleAccessService::assignableModuleSlugs())],
            'phone' => ['nullable', 'string', 'max:64'],
            'account_name' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    protected function splitEmployeeAndAccount(array $validated): array
    {
        $employeeKeys = [
            'employee_number', 'first_name', 'last_name', 'email', 'phone',
            'department_id', 'position_id', 'manager_employee_id',
            'employment_type', 'status', 'hire_date', 'termination_date', 'notes',
        ];

        $employeeData = array_intersect_key($validated, array_flip($employeeKeys));

        $accountName = $validated['account_name']
            ?? trim(($validated['first_name'] ?? '').' '.($validated['last_name'] ?? ''));

        $accountData = [
            'name' => $accountName !== '' ? $accountName : 'Staff Member',
            'email' => $validated['email'],
            'phone' => $validated['phone'] ?? null,
            'password' => $validated['password'],
            'role_id' => $validated['role_id'] ?? null,
            'modules' => $validated['modules'] ?? [],
        ];

        if (empty($employeeData['email'])) {
            $employeeData['email'] = $accountData['email'];
        }

        return [$employeeData, $accountData];
    }
}
