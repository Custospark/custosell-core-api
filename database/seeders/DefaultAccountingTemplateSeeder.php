<?php

namespace Database\Seeders;

use App\Models\ChartOfAccount;
use Illuminate\Database\Seeder;

class DefaultAccountingTemplateSeeder extends Seeder
{
    public function run(): void
    {
        if (ChartOfAccount::count() > 0) {
            return;
        }

        $accounts = [
            ['code' => '1000', 'name' => 'Assets', 'parent_code' => null, 'type_id' => 1, 'normal_balance' => 'debit'],
            ['code' => '1100', 'name' => 'Current Assets', 'parent_code' => '1000', 'type_id' => 1, 'normal_balance' => 'debit'],
            ['code' => '1101', 'name' => 'Cash', 'parent_code' => '1100', 'type_id' => 1, 'normal_balance' => 'debit'],
            ['code' => '1102', 'name' => 'Bank', 'parent_code' => '1100', 'type_id' => 1, 'normal_balance' => 'debit'],
            ['code' => '1103', 'name' => 'Accounts Receivable', 'parent_code' => '1100', 'type_id' => 1, 'normal_balance' => 'debit'],
            ['code' => '1104', 'name' => 'Inventory', 'parent_code' => '1100', 'type_id' => 1, 'normal_balance' => 'debit'],
            ['code' => '1105', 'name' => 'Prepaid Expenses', 'parent_code' => '1100', 'type_id' => 1, 'normal_balance' => 'debit'],
            ['code' => '1200', 'name' => 'Fixed Assets', 'parent_code' => '1000', 'type_id' => 1, 'normal_balance' => 'debit'],
            ['code' => '1201', 'name' => 'Land & Buildings', 'parent_code' => '1200', 'type_id' => 1, 'normal_balance' => 'debit'],
            ['code' => '1202', 'name' => 'Furniture & Fixtures', 'parent_code' => '1200', 'type_id' => 1, 'normal_balance' => 'debit'],
            ['code' => '1203', 'name' => 'Computer Equipment', 'parent_code' => '1200', 'type_id' => 1, 'normal_balance' => 'debit'],
            ['code' => '1204', 'name' => 'Motor Vehicles', 'parent_code' => '1200', 'type_id' => 1, 'normal_balance' => 'debit'],
            ['code' => '1205', 'name' => 'Accumulated Depreciation', 'parent_code' => '1200', 'type_id' => 1, 'normal_balance' => 'credit'],
            ['code' => '2000', 'name' => 'Liabilities', 'parent_code' => null, 'type_id' => 2, 'normal_balance' => 'credit'],
            ['code' => '2100', 'name' => 'Current Liabilities', 'parent_code' => '2000', 'type_id' => 2, 'normal_balance' => 'credit'],
            ['code' => '2101', 'name' => 'Accounts Payable', 'parent_code' => '2100', 'type_id' => 2, 'normal_balance' => 'credit'],
            ['code' => '2102', 'name' => 'VAT Payable', 'parent_code' => '2100', 'type_id' => 2, 'normal_balance' => 'credit'],
            ['code' => '2103', 'name' => 'Accrued Expenses', 'parent_code' => '2100', 'type_id' => 2, 'normal_balance' => 'credit'],
            ['code' => '2104', 'name' => 'Short-term Loans', 'parent_code' => '2100', 'type_id' => 2, 'normal_balance' => 'credit'],
            ['code' => '2200', 'name' => 'Long-term Liabilities', 'parent_code' => '2000', 'type_id' => 2, 'normal_balance' => 'credit'],
            ['code' => '2201', 'name' => 'Bank Loans', 'parent_code' => '2200', 'type_id' => 2, 'normal_balance' => 'credit'],
            ['code' => '2202', 'name' => 'Shareholder Loans', 'parent_code' => '2200', 'type_id' => 2, 'normal_balance' => 'credit'],
            ['code' => '3000', 'name' => 'Equity', 'parent_code' => null, 'type_id' => 3, 'normal_balance' => 'credit'],
            ['code' => '3100', 'name' => 'Share Capital', 'parent_code' => '3000', 'type_id' => 3, 'normal_balance' => 'credit'],
            ['code' => '3200', 'name' => 'Retained Earnings', 'parent_code' => '3000', 'type_id' => 3, 'normal_balance' => 'credit'],
            ['code' => '3300', 'name' => 'Drawings', 'parent_code' => '3000', 'type_id' => 3, 'normal_balance' => 'credit'],
            ['code' => '4000', 'name' => 'Revenue', 'parent_code' => null, 'type_id' => 4, 'normal_balance' => 'credit'],
            ['code' => '4100', 'name' => 'Sales Revenue', 'parent_code' => '4000', 'type_id' => 4, 'normal_balance' => 'credit'],
            ['code' => '4200', 'name' => 'Service Revenue', 'parent_code' => '4000', 'type_id' => 4, 'normal_balance' => 'credit'],
            ['code' => '4300', 'name' => 'Other Income', 'parent_code' => '4000', 'type_id' => 4, 'normal_balance' => 'credit'],
            ['code' => '4400', 'name' => 'Sales Returns', 'parent_code' => '4000', 'type_id' => 4, 'normal_balance' => 'debit'],
            ['code' => '5000', 'name' => 'Cost of Goods Sold', 'parent_code' => null, 'type_id' => 5, 'normal_balance' => 'debit'],
            ['code' => '5100', 'name' => 'COGS - Products', 'parent_code' => '5000', 'type_id' => 5, 'normal_balance' => 'debit'],
            ['code' => '5200', 'name' => 'COGS - Services', 'parent_code' => '5000', 'type_id' => 5, 'normal_balance' => 'debit'],
            ['code' => '6000', 'name' => 'Expenses', 'parent_code' => null, 'type_id' => 5, 'normal_balance' => 'debit'],
            ['code' => '6100', 'name' => 'Operating Expenses', 'parent_code' => '6000', 'type_id' => 5, 'normal_balance' => 'debit'],
            ['code' => '6101', 'name' => 'Salaries & Wages', 'parent_code' => '6100', 'type_id' => 5, 'normal_balance' => 'debit'],
            ['code' => '6102', 'name' => 'Rent', 'parent_code' => '6100', 'type_id' => 5, 'normal_balance' => 'debit'],
            ['code' => '6103', 'name' => 'Utilities', 'parent_code' => '6100', 'type_id' => 5, 'normal_balance' => 'debit'],
            ['code' => '6104', 'name' => 'Office Supplies', 'parent_code' => '6100', 'type_id' => 5, 'normal_balance' => 'debit'],
            ['code' => '6105', 'name' => 'Transportation', 'parent_code' => '6100', 'type_id' => 5, 'normal_balance' => 'debit'],
            ['code' => '6106', 'name' => 'Repairs & Maintenance', 'parent_code' => '6100', 'type_id' => 5, 'normal_balance' => 'debit'],
            ['code' => '6107', 'name' => 'Insurance', 'parent_code' => '6100', 'type_id' => 5, 'normal_balance' => 'debit'],
            ['code' => '6200', 'name' => 'Administrative Expenses', 'parent_code' => '6000', 'type_id' => 5, 'normal_balance' => 'debit'],
            ['code' => '6201', 'name' => 'Bank Charges', 'parent_code' => '6200', 'type_id' => 5, 'normal_balance' => 'debit'],
            ['code' => '6202', 'name' => 'Professional Fees', 'parent_code' => '6200', 'type_id' => 5, 'normal_balance' => 'debit'],
            ['code' => '6300', 'name' => 'Depreciation Expense', 'parent_code' => '6000', 'type_id' => 5, 'normal_balance' => 'debit'],
            ['code' => '6400', 'name' => 'Interest Expense', 'parent_code' => '6000', 'type_id' => 5, 'normal_balance' => 'debit'],
            ['code' => '6500', 'name' => 'Tax Expense', 'parent_code' => '6000', 'type_id' => 5, 'normal_balance' => 'debit'],
        ];

        $inserted = [];
        foreach ($accounts as $account) {
            $parentId = null;
            if ($account['parent_code'] !== null) {
                $parentId = $inserted[$account['parent_code']] ?? null;
            }

            $model = ChartOfAccount::create([
                'business_id' => 1,
                'code' => $account['code'],
                'name' => $account['name'],
                'parent_id' => $parentId,
                'type_id' => $account['type_id'],
                'normal_balance' => $account['normal_balance'],
                'is_active' => true,
            ]);

            $inserted[$account['code']] = $model->id;
        }
    }
}
