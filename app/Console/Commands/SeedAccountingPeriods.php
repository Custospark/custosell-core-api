<?php

namespace App\Console\Commands;

use App\Models\Business;
use App\Repositories\Contracts\AccountingPeriodRepositoryInterface;
use Illuminate\Console\Command;

class SeedAccountingPeriods extends Command
{
    protected $signature = 'accounting:seed-periods {--business= : Business ID to seed periods for}';
    protected $description = 'Generate monthly accounting periods for the current and past year';

    public function handle(AccountingPeriodRepositoryInterface $periodRepo): int
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
            $existing = $periodRepo->all($business->id)->count();
            $this->line("Business {$business->id} ({$business->name}): {$existing} existing periods");

            // Seed current year + 1 year back
            for ($month = -12; $month <= 12; $month++) {
                $date = now()->addMonths($month)->toDateString();
                $periodRepo->findOrCreatePeriod($business->id, $date);
            }

            $total = $periodRepo->all($business->id)->count();
            $this->info("  → {$total} periods now");
        }

        $this->info('Done.');
        return 0;
    }
}
