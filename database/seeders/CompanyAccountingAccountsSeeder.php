<?php

namespace Database\Seeders;

use App\Models\AccountType;
use App\Models\Business;
use App\Models\ChartOfAccount;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Log;

/**
 * Ensures Custospark's company ledger accounts exist in the company business's
 * chart of accounts. Idempotent - safe to run repeatedly.
 *
 * Resolves the company business by its owner email (default
 * oscar@custospark.com, overridable via COMPANY_ACCOUNT_EMAIL) and creates any
 * missing accounts from config('platform.company_accounting.account_codes').
 */
class CompanyAccountingAccountsSeeder extends Seeder
{
    public function run(): void
    {
        $email = config('platform.company_accounting.owner_email');
        if (!$email) {
            return;
        }

        $owner = User::query()->where('email', $email)->whereNull('deleted_at')->first();
        if (!$owner) {
            Log::warning('[CompanyBooks] Company owner email not found; cannot ensure ledger accounts', [
                'owner_email' => $email,
            ]);

            return;
        }

        $business = Business::query()->where('owner_id', $owner->id)->orderByDesc('id')->first();
        if (!$business) {
            Log::warning('[CompanyBooks] Company business not found; cannot ensure ledger accounts', [
                'owner_email' => $email,
            ]);

            return;
        }

        $accounts = $this->accountsToEnsure();

        $existingCodes = ChartOfAccount::where('business_id', $business->id)
            ->pluck('id', 'code')
            ->toArray();

        $typeIds = AccountType::pluck('id', 'name')->toArray();

        $created = 0;
        foreach ($accounts as $account) {
            if (isset($existingCodes[$account['code']])) {
                continue;
            }

            $typeId = $account['type_name']
                ? ($typeIds[$account['type_name']] ?? null)
                : null;
            if (!$typeId) {
                continue;
            }

            $parentId = $account['parent_code']
                ? ($existingCodes[$account['parent_code']] ?? null)
                : null;

            $model = ChartOfAccount::create([
                'business_id' => $business->id,
                'code' => $account['code'],
                'name' => $account['name'],
                'parent_id' => $parentId,
                'type_id' => $typeId,
                'normal_balance' => $account['normal_balance'],
                'is_active' => true,
                'is_system' => true,
            ]);

            $existingCodes[$account['code']] = $model->id;
            $created++;
        }

        if ($created > 0) {
            Log::info('[CompanyBooks] Company ledger accounts ensured', [
                'business_id' => $business->id,
                'created' => $created,
            ]);
        }
    }

    /**
     * @return array<int, array{code: string, name: string, type_name: string, normal_balance: string, parent_code: string|null}>
     */
    protected function accountsToEnsure(): array
    {
        $codes = config('platform.company_accounting.account_codes');

        return [
            ['code' => $codes['bank'], 'name' => 'Bank', 'type_name' => 'Asset', 'normal_balance' => 'debit', 'parent_code' => '1100'],
            ['code' => $codes['deferred_revenue'], 'name' => 'Deferred Revenue', 'type_name' => 'Liability', 'normal_balance' => 'credit', 'parent_code' => '2100'],
            ['code' => $codes['software_revenue'], 'name' => 'Software Revenue', 'type_name' => 'Revenue', 'normal_balance' => 'credit', 'parent_code' => '4000'],
            ['code' => $codes['referral_commission_expense'], 'name' => 'Referral & Commission Expense', 'type_name' => 'Expense', 'normal_balance' => 'debit', 'parent_code' => '6000'],
        ];
    }
}
