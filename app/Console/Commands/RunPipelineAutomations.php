<?php

namespace App\Console\Commands;

use App\Services\Pipeline\PipelineAutomationSchedulerService;
use Illuminate\Console\Command;

class RunPipelineAutomations extends Command
{
    protected $signature = 'pipeline:run-automations';

    protected $description = 'Scan and fire scheduled pipeline automation rules (time/state triggers)';

    public function handle(PipelineAutomationSchedulerService $scheduler): int
    {
        $result = $scheduler->runDue();

        $this->info(sprintf(
            'Automations: checked %d lead(s), fired %d rule(s), executed %d action(s).',
            $result['checked'],
            $result['fired'],
            $result['executed'],
        ));

        return self::SUCCESS;
    }
}