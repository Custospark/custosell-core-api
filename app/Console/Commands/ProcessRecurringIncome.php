<?php

namespace App\Console\Commands;

use App\Services\RecurringIncomeService;
use Illuminate\Console\Command;

class ProcessRecurringIncome extends Command
{
    protected $signature = 'income:process-recurring';

    protected $description = 'Create occurrences for recurring income that is due';

    public function handle(RecurringIncomeService $service): int
    {
        $created = $service->processDue();
        $this->info("Created {$created} recurring income occurrence(s).");

        return self::SUCCESS;
    }
}