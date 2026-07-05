<?php

namespace Tests\Support;

use App\Models\AccountingPeriod;
use App\Models\Business;
use Database\Seeders\DefaultAccountingTemplateSeeder;

trait SeedsAccounting
{
    protected function seedAccountingForBusiness(Business $business): AccountingPeriod
    {
        (new DefaultAccountingTemplateSeeder())->run();

        return AccountingPeriod::create([
            'business_id' => $business->id,
            'name' => 'Test Period',
            'start_date' => now()->startOfMonth()->toDateString(),
            'end_date' => now()->endOfMonth()->toDateString(),
            'is_closed' => false,
        ]);
    }
}
