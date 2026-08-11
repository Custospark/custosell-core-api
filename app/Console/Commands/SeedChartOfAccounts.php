<?php

namespace App\Console\Commands;

use App\Models\Business;
use App\Services\ChartOfAccountService;
use Illuminate\Console\Command;

class SeedChartOfAccounts extends Command
{
    protected $signature = 'accounting:seed-chart-of-accounts {--business= : Business ID to seed chart of accounts for}';

    protected $description = 'Seed the default chart of accounts for every business missing one (idempotent; runs from db:seed and as a standalone legacy backfill)';

    public function handle(ChartOfAccountService $chartService): int
    {
        $businessId = $this->option('business');
        $businesses = $businessId
            ? Business::where('id', $businessId)->get()
            : Business::all();

        if ($businesses->isEmpty()) {
            $this->warn('No businesses found.');

            return 0;
        }

        $seeded = 0;
        $skipped = 0;
        $totalCreated = 0;

        foreach ($businesses as $business) {
            $created = $chartService->seedDefaultTemplate((int) $business->id);

            if ($created > 0) {
                $seeded++;
                $totalCreated += $created;
                $this->info("Business {$business->id} ({$business->name}): {$created} accounts added");
            } else {
                $skipped++;
            }
        }

        $this->info("Done. {$seeded} business(es) seeded ({$totalCreated} accounts); {$skipped} already had a chart of accounts.");

        return 0;
    }
}
