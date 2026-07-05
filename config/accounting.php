<?php

return [
    'default_account_codes' => [
        'cash' => '1101',
        'bank' => '1102',
        'accounts_receivable' => '1103',
        'inventory' => '1104',
        'accounts_payable' => '2101',
        'vat_payable' => '2102',
        'sales_revenue' => '4100',
        'sales_returns' => '4400',
        'service_revenue' => '4200',
        'cogs' => '5100',
        'retained_earnings' => '3200',
        'depreciation_expense' => '6300',
        'accumulated_depreciation' => '1205',
        'operating_expense' => '6101',
    ],

    'inventory' => [
        'max_stock_per_sku' => 100_000,
        'max_unit_cost' => 1_000_000,
        'max_line_value' => 10_000_000,
        'auto_sync_max_adjustment' => 500_000,
        'opening_tracked_products_only' => true,
    ],
];
