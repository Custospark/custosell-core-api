<?php

namespace App\Console\Commands;

use App\Services\RecurringExpenseService;
use Illuminate\Console\Command;

class ProcessRecurringExpenses extends Command
{
    protected $signature = 'expenses:process-recurring';

    protected $description = 'Create occurrences for recurring expenses that are due';

    public function handle(RecurringExpenseService $service): int
    {
        $created = $service->processDue();
        $this->info("Created {$created} recurring expense occurrence(s).");

        return self::SUCCESS;
    }
}