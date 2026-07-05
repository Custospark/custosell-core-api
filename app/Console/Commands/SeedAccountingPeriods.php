<?php

namespace App\Console\Commands;

use App\Models\Business;
use App\Services\AccountingPeriodService;
use Illuminate\Console\Command;

class SeedAccountingPeriods extends Command
{
    protected $signature = 'accounting:seed-periods {--business= : Business ID to seed periods for}';

    protected $description = 'Generate monthly accounting periods from business registration year through next calendar year';

    public function handle(AccountingPeriodService $periodService): int
    {
        $businessId = $this->option('business');
        $businesses = $businessId
            ? Business::where('id', $businessId)->get()
            : Business::all();

        if ($businesses->isEmpty()) {
            $this->warn('No businesses found.');

            return 0;
        }

        foreach ($businesses as $business) {
            $before = $periodService->getAll($business->id)->count();
            $after = $periodService->getAll($business->id)->count();
            $this->info("Business {$business->id} ({$business->name}): {$after} periods ({$before} before ensure)");
        }

        $this->info('Done.');

        return 0;
    }
}
