<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PipelineBoardAutomation extends Model
{
    protected $fillable = [
        'business_id',
        'board_id',
        'created_by',
        'name',
        'trigger_type',
        'trigger_stage_id',
        'action_type',
        'action_body',
        'is_active',
    ];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function board(): BelongsTo
    {
        return $this->belongsTo(PipelineBoard::class, 'board_id');
    }

    public function triggerStage(): BelongsTo
    {
        return $this->belongsTo(PipelineStage::class, 'trigger_stage_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
