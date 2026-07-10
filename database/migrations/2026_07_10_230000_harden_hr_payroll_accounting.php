<?php

declare(strict_types=1);

use App\Models\ChartOfAccount;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Split payroll liabilities (2110–2112), settlement/remittance journal FKs, and void timestamps.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('hr_pay_runs')) {
            Schema::table('hr_pay_runs', function (Blueprint $table) {
                if (! Schema::hasColumn('hr_pay_runs', 'settlement_journal_entry_id')) {
                    $table->foreignId('settlement_journal_entry_id')
                        ->nullable()
                        ->after('posted_journal_entry_id')
                        ->constrained('journal_entries')
                        ->nullOnDelete();
                }
                if (! Schema::hasColumn('hr_pay_runs', 'statutory_journal_entry_id')) {
                    $table->foreignId('statutory_journal_entry_id')
                        ->nullable()
                        ->after('settlement_journal_entry_id')
                        ->constrained('journal_entries')
                        ->nullOnDelete();
                }
                if (! Schema::hasColumn('hr_pay_runs', 'net_settled_at')) {
                    $table->timestamp('net_settled_at')->nullable()->after('posted_at');
                }
                if (! Schema::hasColumn('hr_pay_runs', 'statutory_remitted_at')) {
                    $table->timestamp('statutory_remitted_at')->nullable()->after('net_settled_at');
                }
                if (! Schema::hasColumn('hr_pay_runs', 'voided_at')) {
                    $table->timestamp('voided_at')->nullable()->after('statutory_remitted_at');
                }
            });
        }

        $this->backfillPayrollLiabilityAccounts();
    }

    public function down(): void
    {
        if (Schema::hasTable('hr_pay_runs')) {
            Schema::table('hr_pay_runs', function (Blueprint $table) {
                if (Schema::hasColumn('hr_pay_runs', 'settlement_journal_entry_id')) {
                    $table->dropConstrainedForeignId('settlement_journal_entry_id');
                }
                if (Schema::hasColumn('hr_pay_runs', 'statutory_journal_entry_id')) {
                    $table->dropConstrainedForeignId('statutory_journal_entry_id');
                }
                foreach (['net_settled_at', 'statutory_remitted_at', 'voided_at'] as $col) {
                    if (Schema::hasColumn('hr_pay_runs', $col)) {
                        $table->dropColumn($col);
                    }
                }
            });
        }
    }

    protected function backfillPayrollLiabilityAccounts(): void
    {
        if (! Schema::hasTable('chart_of_accounts')) {
            return;
        }

        $accounts = [
            ['code' => '2110', 'name' => 'Salaries Payable', 'parent_code' => '2100', 'type_id' => 2, 'normal_balance' => 'credit'],
            ['code' => '2111', 'name' => 'PAYE Payable', 'parent_code' => '2100', 'type_id' => 2, 'normal_balance' => 'credit'],
            ['code' => '2112', 'name' => 'NSSF Payable', 'parent_code' => '2100', 'type_id' => 2, 'normal_balance' => 'credit'],
        ];

        $businessIds = ChartOfAccount::query()
            ->distinct()
            ->pluck('business_id');

        foreach ($businessIds as $businessId) {
            $byCode = ChartOfAccount::query()
                ->where('business_id', $businessId)
                ->pluck('id', 'code');

            foreach ($accounts as $account) {
                if ($byCode->has($account['code'])) {
                    continue;
                }

                $parentId = $byCode->get($account['parent_code']);
                $typeId = $account['type_id'];
                // Prefer type_id from an existing liability sibling when present.
                $sibling = ChartOfAccount::query()
                    ->where('business_id', $businessId)
                    ->where('code', '2103')
                    ->first();
                if ($sibling) {
                    $typeId = (int) $sibling->type_id;
                }

                $model = ChartOfAccount::create([
                    'business_id' => $businessId,
                    'code' => $account['code'],
                    'name' => $account['name'],
                    'parent_id' => $parentId,
                    'type_id' => $typeId,
                    'normal_balance' => $account['normal_balance'],
                    'is_active' => true,
                    'is_system' => true,
                ]);
                $byCode[$account['code']] = $model->id;
            }
        }
    }
};
