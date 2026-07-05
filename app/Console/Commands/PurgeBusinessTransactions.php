<?php

namespace App\Console\Commands;

use App\Models\Business;
use App\Services\BusinessTransactionPurgeService;
use Illuminate\Console\Command;

class PurgeBusinessTransactions extends Command
{
    protected $signature = 'business:purge-transactions
                            {business : Business ID to purge}
                            {--keep-customers : Retain customer records}
                            {--force : Skip confirmation prompt}';

    protected $description = 'Delete sales, expenses, invoices, payments, journal entries, and ledger data for one business (keeps products and COA)';

    public function handle(BusinessTransactionPurgeService $purgeService): int
    {
        $businessId = (int) $this->argument('business');
        $business = Business::find($businessId);

        if (!$business) {
            $this->error("Business {$businessId} not found.");
            return 1;
        }

        $this->warn("This will permanently delete transactional data for: {$business->name} (id={$businessId})");
        $this->line('Kept: products, categories, chart of accounts, users, business settings, accounting periods.');
        $this->line('Removed: sales, invoices, payments, expenses, shifts, stock movements, journal entries, GL, fixed assets.');

        if (!$this->option('force') && !$this->confirm('Continue?')) {
            $this->info('Aborted.');
            return 0;
        }

        $counts = $purgeService->purge($business, !$this->option('keep-customers'));

        $this->newLine();
        $this->info("Purge complete for business {$businessId} ({$business->name}).");
        foreach ($counts as $key => $count) {
            $this->line(sprintf('  %-28s %s', str_replace('_', ' ', $key) . ':', $count));
        }

        return 0;
    }
}
