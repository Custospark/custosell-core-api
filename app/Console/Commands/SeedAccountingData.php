<?php

namespace App\Console\Commands;

use App\Models\AccountingPeriod;
use App\Models\ChartOfAccount;
use App\Services\JournalEntryService;
use App\Services\LedgerService;
use Illuminate\Console\Command;

class SeedAccountingData extends Command
{
    protected $signature = 'accounting:seed-data {business=2}';
    protected $description = 'Seed 100 journal entries across periods for ratio testing';

    public function handle(JournalEntryService $journalEntryService, LedgerService $ledgerService): int
    {
        $businessId = (int) $this->argument('business');

        $cashId = ChartOfAccount::where('business_id', $businessId)->where('code', '1101')->value('id');
        $invId = ChartOfAccount::where('business_id', $businessId)->where('code', '1104')->value('id');
        $apId = ChartOfAccount::where('business_id', $businessId)->where('code', '2101')->value('id');
        $revId = ChartOfAccount::where('business_id', $businessId)->where('code', '4100')->value('id');
        $cogsId = ChartOfAccount::where('business_id', $businessId)->where('code', '5100')->value('id');
        $salId = ChartOfAccount::where('business_id', $businessId)->where('code', '6101')->value('id');

        $periods = AccountingPeriod::where('business_id', $businessId)->orderBy('start_date')->get();

        if ($periods->isEmpty()) {
            $this->error('No accounting periods found.');
            return 1;
        }

        $this->info("Seeding 100 entries for business {$businessId}...");
        $bar = $this->output->createProgressBar(100);
        $bar->start();

        $count = 0;
        $seed = range(1, 100);

        foreach ($seed as $i) {
            $period = $periods[$i % count($periods)];
            $month = (int) $period->start_date->format('n');
            $year = (int) $period->start_date->format('Y');

            // Seasonal patterns
            $seasonFactor = 1 + 0.3 * sin(($month - 1) * pi() / 6);
            $growthFactor = 1 + ($year - 2025) * 0.05 + ($month - 7) * 0.01;
            $noiseFactor = 0.8 + mt_rand(0, 4000) / 10000;

            $baseRevenue = 50000 * $seasonFactor * $growthFactor * $noiseFactor;
            $baseCogs = $baseRevenue * (0.35 + mt_rand(0, 1000) / 10000);
            $baseSalaries = 20000 + mt_rand(-3000, 3000);

            $revenue = round($baseRevenue, -2);
            $cogs = round($baseCogs, -2);
            $salaries = round($baseSalaries, -2);
            $date = $period->start_date->copy()->addDays(mt_rand(1, 25))->toDateString();

            // Revenue entry: Dr Cash, Cr Revenue
            try {
                $e1 = $journalEntryService->createEntry($businessId, $date, "Sales revenue - Period {$period->name}", [
                    ['account_id' => $cashId, 'debit' => $revenue, 'credit' => 0],
                    ['account_id' => $revId, 'debit' => 0, 'credit' => $revenue],
                ]);
                $journalEntryService->postEntry($e1->id);
                $count++;
            } catch (\Throwable $e) {
                $this->warn("Entry {$i}a failed: {$e->getMessage()}");
            }

            // COGS entry: Dr COGS, Cr Inventory
            try {
                $e2 = $journalEntryService->createEntry($businessId, $date, "COGS - Period {$period->name}", [
                    ['account_id' => $cogsId, 'debit' => $cogs, 'credit' => 0],
                    ['account_id' => $invId, 'debit' => 0, 'credit' => $cogs],
                ]);
                $journalEntryService->postEntry($e2->id);
                $count++;
            } catch (\Throwable $e) {
                $this->warn("Entry {$i}b failed: {$e->getMessage()}");
            }

            // Salaries entry: Dr Salaries, Cr Cash
            try {
                $e3 = $journalEntryService->createEntry($businessId, $date, "Salaries - Period {$period->name}", [
                    ['account_id' => $salId, 'debit' => $salaries, 'credit' => 0],
                    ['account_id' => $cashId, 'debit' => 0, 'credit' => $salaries],
                ]);
                $journalEntryService->postEntry($e3->id);
                $count++;
            } catch (\Throwable $e) {
                $this->warn("Entry {$i}c failed: {$e->getMessage()}");
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine();
        $this->info("Done. Created {$count} entries (≈ " . round($count / 3) . " transaction sets).");

        return 0;
    }
}
