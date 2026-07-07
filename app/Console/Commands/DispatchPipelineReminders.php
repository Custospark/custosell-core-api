<?php

namespace App\Console\Commands;

use App\Services\Pipeline\PipelineCollaborationService;
use Illuminate\Console\Command;

class DispatchPipelineReminders extends Command
{
    protected $signature = 'pipeline:dispatch-reminders';

    protected $description = 'Send due pipeline card reminders to users';

    public function handle(PipelineCollaborationService $collaboration): int
    {
        $sent = $collaboration->dispatchDueReminders();
        $this->info("Dispatched {$sent} pipeline reminder(s).");

        return self::SUCCESS;
    }
}
