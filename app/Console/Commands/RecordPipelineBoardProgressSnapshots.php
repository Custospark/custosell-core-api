<?php

namespace App\Console\Commands;

use App\Models\PipelineBoard;
use App\Services\Pipeline\PipelineBoardProgressService;
use Illuminate\Console\Command;

class RecordPipelineBoardProgressSnapshots extends Command
{
    protected $signature = 'pipeline:record-progress-snapshots {--board= : Limit to a single board id}';

    protected $description = 'Record daily pipeline board progress metric snapshots for historical charts';

    public function handle(PipelineBoardProgressService $progress): int
    {
        $boardId = $this->option('board');
        $query = PipelineBoard::query()->where('is_archived', false);

        if ($boardId) {
            $query->whereKey((int) $boardId);
        }

        $count = 0;
        $query->orderBy('id')->chunkById(50, function ($boards) use ($progress, &$count): void {
            foreach ($boards as $board) {
                $progress->recordDailySnapshots((int) $board->business_id, (int) $board->id);
                $count++;
            }
        });

        $this->info("Recorded progress snapshots for {$count} board(s).");

        return self::SUCCESS;
    }
}
