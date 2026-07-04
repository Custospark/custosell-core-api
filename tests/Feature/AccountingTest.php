<?php

namespace Tests\Feature;

use App\Models\{AccountingPeriod, AccountType, Business, ChartOfAccount, Role, User};
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AccountingTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected Business $business;
    protected string $adminToken;
    protected AccountingPeriod $period;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PlanSeeder::class);

        $this->admin = User::factory()->create(['is_active' => true]);
        $this->adminToken = $this->admin->createToken('admin')->plainTextToken;

        $this->business = Business::factory()->create([
            'owner_id' => $this->admin->id,
            'currency' => 'UGX',
            'status' => 'active',
        ]);
        $this->admin->business_id = $this->business->id;
        $this->admin->save();

        $role = Role::create([
            'business_id' => $this->business->id,
            'name' => 'Admin',
            'slug' => 'admin',
            'permissions' => [
                'accounting.view' => true,
                'accounting.journal.create' => true,
                'accounting.journal.post' => true,
                'accounting.reports' => true,
                'accounting.periods.close' => true,
            ],
        ]);
        $this->admin->role_id = $role->id;
        $this->admin->save();

        $this->period = AccountingPeriod::create([
            'business_id' => $this->business->id,
            'name' => 'July 2026',
            'start_date' => '2026-07-01',
            'end_date' => '2026-07-31',
            'is_closed' => false,
        ]);

        $this->seedChartOfAccounts();
    }

    protected function seedChartOfAccounts(): void
    {
        $types = [
            ['name' => 'Asset', 'normal_balance' => 'debit'],
            ['name' => 'Liability', 'normal_balance' => 'credit'],
            ['name' => 'Equity', 'normal_balance' => 'credit'],
            ['name' => 'Revenue', 'normal_balance' => 'credit'],
            ['name' => 'Expense', 'normal_balance' => 'debit'],
        ];
        foreach ($types as $t) {
            AccountType::create($t);
        }

        $assetType = AccountType::where('name', 'Asset')->first();
        $revenueType = AccountType::where('name', 'Revenue')->first();
        $expenseType = AccountType::where('name', 'Expense')->first();
        $liabilityType = AccountType::where('name', 'Liability')->first();
        $equityType = AccountType::where('name', 'Equity')->first();

        $accounts = [
            ['code' => '1000', 'name' => 'Assets', 'type_id' => $assetType->id, 'normal_balance' => 'debit'],
            ['code' => '1100', 'name' => 'Current Assets', 'type_id' => $assetType->id, 'normal_balance' => 'debit', 'parent' => '1000'],
            ['code' => '1101', 'name' => 'Cash', 'type_id' => $assetType->id, 'normal_balance' => 'debit', 'parent' => '1100'],
            ['code' => '1103', 'name' => 'Accounts Receivable', 'type_id' => $assetType->id, 'normal_balance' => 'debit', 'parent' => '1100'],
            ['code' => '1104', 'name' => 'Inventory', 'type_id' => $assetType->id, 'normal_balance' => 'debit', 'parent' => '1100'],
            ['code' => '4000', 'name' => 'Revenue', 'type_id' => $revenueType->id, 'normal_balance' => 'credit'],
            ['code' => '4100', 'name' => 'Sales Revenue', 'type_id' => $revenueType->id, 'normal_balance' => 'credit', 'parent' => '4000'],
            ['code' => '5000', 'name' => 'Cost of Goods Sold', 'type_id' => $expenseType->id, 'normal_balance' => 'debit'],
            ['code' => '5100', 'name' => 'COGS - Products', 'type_id' => $expenseType->id, 'normal_balance' => 'debit', 'parent' => '5000'],
            ['code' => '6000', 'name' => 'Expenses', 'type_id' => $expenseType->id, 'normal_balance' => 'debit'],
            ['code' => '6100', 'name' => 'Operating Expenses', 'type_id' => $expenseType->id, 'normal_balance' => 'debit', 'parent' => '6000'],
            ['code' => '6101', 'name' => 'Salaries', 'type_id' => $expenseType->id, 'normal_balance' => 'debit', 'parent' => '6100'],
            ['code' => '6300', 'name' => 'Depreciation Expense', 'type_id' => $expenseType->id, 'normal_balance' => 'debit', 'parent' => '6000'],
            ['code' => '6400', 'name' => 'Interest Expense', 'type_id' => $expenseType->id, 'normal_balance' => 'debit', 'parent' => '6000'],
            ['code' => '6500', 'name' => 'Tax Expense', 'type_id' => $expenseType->id, 'normal_balance' => 'debit', 'parent' => '6000'],
            ['code' => '2000', 'name' => 'Liabilities', 'type_id' => $liabilityType->id, 'normal_balance' => 'credit'],
            ['code' => '2100', 'name' => 'Current Liabilities', 'type_id' => $liabilityType->id, 'normal_balance' => 'credit', 'parent' => '2000'],
            ['code' => '2101', 'name' => 'Accounts Payable', 'type_id' => $liabilityType->id, 'normal_balance' => 'credit', 'parent' => '2100'],
            ['code' => '3000', 'name' => 'Equity', 'type_id' => $equityType->id, 'normal_balance' => 'credit'],
            ['code' => '3100', 'name' => 'Share Capital', 'type_id' => $equityType->id, 'normal_balance' => 'credit', 'parent' => '3000'],
            ['code' => '3200', 'name' => 'Retained Earnings', 'type_id' => $equityType->id, 'normal_balance' => 'credit', 'parent' => '3000'],
        ];

        $inserted = [];
        foreach ($accounts as $a) {
            $parentId = $a['parent'] ?? null ? ($inserted[$a['parent']] ?? null) : null;
            $model = ChartOfAccount::create([
                'business_id' => $this->business->id,
                'code' => $a['code'],
                'name' => $a['name'],
                'parent_id' => $parentId,
                'type_id' => $a['type_id'],
                'normal_balance' => $a['normal_balance'],
                'is_active' => true,
            ]);
            $inserted[$a['code']] = $model->id;
        }
    }

    protected function apiHeaders(): array
    {
        return [
            'Authorization' => 'Bearer ' . $this->adminToken,
            'Accept' => 'application/json',
        ];
    }

    /** @test */
    public function chart_of_accounts_returns_all_accounts()
    {
        $response = $this->getJson('/api/v1/chart-of-accounts', $this->apiHeaders());
        $response->assertStatus(200)
            ->assertJsonStructure(['data' => [['id', 'code', 'name', 'type_id', 'normal_balance']]]);
    }

    /** @test */
    public function chart_of_accounts_tree_returns_hierarchy()
    {
        $response = $this->getJson('/api/v1/chart-of-accounts/tree', $this->apiHeaders());
        $response->assertStatus(200)
            ->assertJsonStructure(['data']);
    }

    /** @test */
    public function can_create_chart_of_account()
    {
        $response = $this->postJson('/api/v1/chart-of-accounts', [
            'code' => '9999',
            'name' => 'Test Account',
            'type_id' => AccountType::where('name', 'Asset')->first()->id,
            'normal_balance' => 'debit',
        ], $this->apiHeaders());
        $response->assertStatus(201)
            ->assertJsonPath('data.code', '9999');
    }

    /** @test */
    public function accounting_periods_returns_list()
    {
        $response = $this->getJson('/api/v1/accounting-periods', $this->apiHeaders());
        $response->assertStatus(200)
            ->assertJsonStructure(['data' => [['id', 'name', 'start_date', 'end_date', 'is_closed']]]);
    }

    /** @test */
    public function current_period_returns_period()
    {
        $response = $this->getJson('/api/v1/accounting-periods/current', $this->apiHeaders());
        $response->assertStatus(200)
            ->assertJsonPath('data.name', 'July 2026');
    }

    /** @test */
    public function trial_balance_returns_structured_data()
    {
        $response = $this->getJson('/api/v1/general-ledger/trial-balance?period_id=' . $this->period->id, $this->apiHeaders());
        $response->assertStatus(200)
            ->assertJsonStructure(['data' => ['accounts', 'total_debits', 'total_credits', 'is_balanced']]);
    }

    /** @test */
    public function trial_balance_works_without_period_id()
    {
        $response = $this->getJson('/api/v1/general-ledger/trial-balance', $this->apiHeaders());
        $response->assertStatus(200)
            ->assertJsonStructure(['data' => ['accounts']]);
    }

    /** @test */
    public function profit_loss_returns_structured_data()
    {
        $response = $this->getJson('/api/v1/general-ledger/profit-loss?period_id=' . $this->period->id, $this->apiHeaders());
        $response->assertStatus(200)
            ->assertJsonStructure(['data' => [
                'sections', 'total_revenue', 'total_cost_of_goods_sold',
                'gross_profit', 'total_operating_expenses', 'net_income',
            ]]);
    }

    /** @test */
    public function balance_sheet_returns_structured_data()
    {
        $response = $this->getJson('/api/v1/general-ledger/balance-sheet?period_id=' . $this->period->id, $this->apiHeaders());
        $response->assertStatus(200)
            ->assertJsonStructure(['data' => [
                'sections', 'total_assets', 'total_liabilities', 'total_equity', 'is_balanced',
            ]]);
    }

    /** @test */
    public function journal_entries_returns_list()
    {
        $response = $this->getJson('/api/v1/journal-entries', $this->apiHeaders());
        $response->assertStatus(200)
            ->assertJsonStructure(['data']);
    }

    /** @test */
    public function can_create_journal_entry()
    {
        $cashAccount = ChartOfAccount::where('code', '1101')->first();
        $revenueAccount = ChartOfAccount::where('code', '4100')->first();

        $response = $this->postJson('/api/v1/journal-entries', [
            'date' => '2026-07-15',
            'description' => 'Test journal entry',
            'lines' => [
                [
                    'account_id' => $cashAccount->id,
                    'debit_amount' => 1000,
                    'credit_amount' => 0,
                ],
                [
                    'account_id' => $revenueAccount->id,
                    'debit_amount' => 0,
                    'credit_amount' => 1000,
                ],
            ],
        ], $this->apiHeaders());

        $response->assertStatus(201)
            ->assertJsonPath('data.description', 'Test journal entry');
    }

    /** @test */
    public function cannot_create_unbalanced_entry()
    {
        $cashAccount = ChartOfAccount::where('code', '1101')->first();
        $revenueAccount = ChartOfAccount::where('code', '4100')->first();

        $response = $this->postJson('/api/v1/journal-entries', [
            'date' => '2026-07-15',
            'description' => 'Unbalanced entry',
            'lines' => [
                ['account_id' => $cashAccount->id, 'debit_amount' => 1000, 'credit_amount' => 0],
                ['account_id' => $revenueAccount->id, 'debit_amount' => 0, 'credit_amount' => 500],
            ],
        ], $this->apiHeaders());

        $response->assertStatus(422);
    }

    /** @test */
    public function can_post_journal_entry()
    {
        $cashAccount = ChartOfAccount::where('code', '1101')->first();
        $revenueAccount = ChartOfAccount::where('code', '4100')->first();

        $create = $this->postJson('/api/v1/journal-entries', [
            'date' => '2026-07-15',
            'description' => 'Entry to post',
            'lines' => [
                ['account_id' => $cashAccount->id, 'debit_amount' => 2000, 'credit_amount' => 0],
                ['account_id' => $revenueAccount->id, 'debit_amount' => 0, 'credit_amount' => 2000],
            ],
        ], $this->apiHeaders());

        $entryId = $create->json('data.id');

        $response = $this->postJson("/api/v1/journal-entries/{$entryId}/post", [], $this->apiHeaders());
        $response->assertStatus(200)
            ->assertJsonPath('data.locked', true);
    }

    /** @test */
    public function can_reverse_journal_entry()
    {
        $cashAccount = ChartOfAccount::where('code', '1101')->first();
        $revenueAccount = ChartOfAccount::where('code', '4100')->first();

        $create = $this->postJson('/api/v1/journal-entries', [
            'date' => '2026-07-15',
            'description' => 'Entry to reverse',
            'lines' => [
                ['account_id' => $cashAccount->id, 'debit_amount' => 3000, 'credit_amount' => 0],
                ['account_id' => $revenueAccount->id, 'debit_amount' => 0, 'credit_amount' => 3000],
            ],
        ], $this->apiHeaders());

        $entryId = $create->json('data.id');

        $this->postJson("/api/v1/journal-entries/{$entryId}/post", [], $this->apiHeaders());

        $response = $this->postJson("/api/v1/journal-entries/{$entryId}/reverse", [], $this->apiHeaders());
        $response->assertStatus(201);
    }

    /** @test */
    public function ratios_return_all_categories()
    {
        // Create a journal entry first so there's data
        $cash = ChartOfAccount::where('code', '1101')->first();
        $revenue = ChartOfAccount::where('code', '4100')->first();
        $expense = ChartOfAccount::where('code', '6101')->first();

        $entry = $this->postJson('/api/v1/journal-entries', [
            'date' => '2026-07-15',
            'description' => 'Data for ratios',
            'lines' => [
                ['account_id' => $cash->id, 'debit_amount' => 50000, 'credit_amount' => 0],
                ['account_id' => $revenue->id, 'debit_amount' => 0, 'credit_amount' => 50000],
            ],
        ], $this->apiHeaders());
        $this->postJson("/api/v1/journal-entries/{$entry->json('data.id')}/post", [], $this->apiHeaders());

        $expEntry = $this->postJson('/api/v1/journal-entries', [
            'date' => '2026-07-16',
            'description' => 'Expense for ratios',
            'lines' => [
                ['account_id' => $expense->id, 'debit_amount' => 10000, 'credit_amount' => 0],
                ['account_id' => $cash->id, 'debit_amount' => 0, 'credit_amount' => 10000],
            ],
        ], $this->apiHeaders());
        $this->postJson("/api/v1/journal-entries/{$expEntry->json('data.id')}/post", [], $this->apiHeaders());

        $response = $this->getJson('/api/v1/ratios?period_id=' . $this->period->id, $this->apiHeaders());
        $response->assertStatus(200)
            ->assertJsonStructure(['data' => [
                'liquidity' => ['current_ratio', 'quick_ratio', 'cash_ratio'],
                'profitability' => ['gross_profit_margin', 'net_profit_margin', 'return_on_assets', 'return_on_equity'],
                'solvency' => ['debt_to_equity', 'debt_ratio', 'interest_coverage_ratio'],
                'efficiency' => ['asset_turnover', 'inventory_turnover', 'accounts_receivable_turnover'],
                'recommendations',
            ]]);
    }

    /** @test */
    public function ratios_work_without_period_id()
    {
        $response = $this->getJson('/api/v1/ratios', $this->apiHeaders());
        $response->assertStatus(200)
            ->assertJsonStructure(['data' => ['liquidity']]);
    }

    /** @test */
    public function ratio_trends_return_data()
    {
        $response = $this->getJson('/api/v1/ratios/trends?interval=monthly&count=3', $this->apiHeaders());
        $response->assertStatus(200)
            ->assertJsonStructure(['data']);
    }

    /** @test */
    public function fixed_assets_returns_list()
    {
        $response = $this->getJson('/api/v1/fixed-assets', $this->apiHeaders());
        $response->assertStatus(200)
            ->assertJsonStructure(['data']);
    }

    /** @test */
    public function can_create_fixed_asset()
    {
        $assetAccount = ChartOfAccount::where('code', '1203')->first();

        $response = $this->postJson('/api/v1/fixed-assets', [
            'name' => 'Test Server',
            'account_id' => $assetAccount->id,
            'cost' => 5000000,
            'salvage_value' => 500000,
            'useful_life_months' => 36,
            'purchase_date' => '2026-07-01',
        ], $this->apiHeaders());

        $response->assertStatus(201)
            ->assertJsonPath('data.name', 'Test Server');
    }

    /** @test */
    public function can_close_and_reopen_period()
    {
        // First create and post an entry so trial balance can be checked
        $cash = ChartOfAccount::where('code', '1101')->first();
        $revenue = ChartOfAccount::where('code', '4100')->first();

        $entry = $this->postJson('/api/v1/journal-entries', [
            'date' => '2026-07-15',
            'description' => 'Entry for period close test',
            'lines' => [
                ['account_id' => $cash->id, 'debit_amount' => 1000, 'credit_amount' => 0],
                ['account_id' => $revenue->id, 'debit_amount' => 0, 'credit_amount' => 1000],
            ],
        ], $this->apiHeaders());
        $this->postJson("/api/v1/journal-entries/{$entry->json('data.id')}/post", [], $this->apiHeaders());

        $closeResponse = $this->postJson("/api/v1/accounting-periods/{$this->period->id}/close", [], $this->apiHeaders());
        $closeResponse->assertStatus(200)
            ->assertJsonPath('data.is_closed', true);

        $reopenResponse = $this->postJson("/api/v1/accounting-periods/{$this->period->id}/reopen", [], $this->apiHeaders());
        $reopenResponse->assertStatus(200)
            ->assertJsonPath('data.is_closed', false);
    }

    /** @test */
    public function requires_authentication()
    {
        $this->getJson('/api/v1/chart-of-accounts')->assertStatus(401);
        $this->getJson('/api/v1/journal-entries')->assertStatus(401);
        $this->getJson('/api/v1/general-ledger/trial-balance')->assertStatus(401);
        $this->getJson('/api/v1/ratios')->assertStatus(401);
        $this->getJson('/api/v1/fixed-assets')->assertStatus(401);
    }
}
