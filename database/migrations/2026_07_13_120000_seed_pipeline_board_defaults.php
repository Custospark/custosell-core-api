<?php

use App\Models\Business;
use App\Models\PipelineBoard;
use App\Models\User;
use App\Services\Pipeline\PipelineBoardSeedService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('pipeline_boards')) {
            return;
        }

        /** @var PipelineBoardSeedService $seed */
        $seed = app(PipelineBoardSeedService::class);

        PipelineBoard::query()
            ->where('is_archived', false)
            ->orderBy('id')
            ->each(function (PipelineBoard $board) use ($seed): void {
                $backgroundValue = is_string($board->background_value) ? trim($board->background_value) : '';
                if ($backgroundValue === '') {
                    $seed->applyDefaultAppearance($board, (int) $board->id);
                } elseif (empty($board->cover_color)) {
                    $appearance = $seed->defaultAppearance((int) $board->id);
                    $board->cover_color = $appearance['cover_color'];
                    $board->save();
                }

                $createdBy = $board->created_by
                    ?: Business::query()->whereKey($board->business_id)->value('owner_id');

                if (! $createdBy) {
                    return;
                }

                if (! User::query()->whereKey($createdBy)->exists()) {
                    return;
                }

                $seed->seedGuidingCards($board, (int) $createdBy);
            });
    }

    public function down(): void
    {
        // Keep gallery backgrounds and guiding cards — removing them would blank customized boards.
    }
};
