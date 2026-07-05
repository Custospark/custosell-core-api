<?php

namespace App\Console\Commands;

use App\Models\JournalEntry;
use App\Services\InventoryCogsService;
use App\Services\JournalEntryService;
use Illuminate\Console\Command;

class FixInventoryLedger extends Command
{
    protected $signature = 'inventory:fix-negative-ledger
                            {business : Business ID}
                            {--reverse-sync : Reverse the last inventory_sync entry if present}';

    protected $description = 'Bring negative GL inventory balance up to zero with a balanced adjustment entry';

    public function handle(JournalEntryService $journalEntryService, InventoryCogsService $inventoryCogs): int
    {
        $businessId = (int) $this->argument('business');
        $codes = config('accounting.default_account_codes');

        if ($this->option('reverse-sync')) {
            $sync = JournalEntry::query()
                ->where('business_id', $businessId)
                ->where('reference_type', 'inventory_sync')
                ->orderByDesc('id')
                ->first();

            if ($sync) {
                $lines = [];
                foreach ($sync->lines()->with('chartOfAccount')->get() as $line) {
                    $lines[] = [
                        'account_code' => $line->chartOfAccount->code,
                        'debit' => (float) $line->credit_amount,
                        'credit' => (float) $line->debit_amount,
                        'description' => 'Reverse inventory sync',
                    ];
                }

                $journalEntryService->createAndPostEntry(
                    $businessId,
                    now()->toDateString(),
                    'Reverse erroneous inventory sync',
                    $lines,
                    'inventory_adjustment',
                    (int) now()->format('His'),
                    1,
                );
                $this->info('Reversed inventory_sync entry.');
            }
        }

        $balance = $inventoryCogs->getInventoryLedgerBalance($businessId);
        $this->line("Current GL inventory: {$balance}");

        if ($balance >= 0) {
            $this->info('Inventory ledger is not negative — nothing to fix.');
            return 0;
        }

        $amount = round(abs($balance), 2);
        $journalEntryService->createAndPostEntry(
            $businessId,
            now()->toDateString(),
            'Correct negative inventory ledger balance',
            [
                ['account_code' => $codes['inventory'], 'debit' => $amount, 'credit' => 0, 'description' => 'Inventory correction'],
                ['account_code' => $codes['retained_earnings'], 'debit' => 0, 'credit' => $amount, 'description' => 'Inventory correction offset'],
            ],
            'inventory_adjustment',
            (int) now()->format('YmdHis'),
            1,
        );

        $after = $inventoryCogs->getInventoryLedgerBalance($businessId);
        $this->info("Inventory ledger corrected to {$after}.");

        return 0;
    }
}
