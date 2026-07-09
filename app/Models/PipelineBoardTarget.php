<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PipelineBoardTarget extends Model
{
    protected $fillable = [
        'business_id',
        'board_id',
        'stage_id',
        'parent_id',
        'type',
        'goal_tag',
        'title',
        'description',
        'metric_key',
        'target_value',
        'unit',
        'period_type',
        'planning_level',
        'anchor_start',
        'anchor_end',
        'period_start',
        'period_end',
        'scope',
        'member_user_id',
        'weight',
        'status',
        'decomposition_mode',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'target_value' => 'decimal:4',
            'period_start' => 'date',
            'period_end' => 'date',
            'anchor_start' => 'date',
            'anchor_end' => 'date',
            'weight' => 'integer',
        ];
    }

    public function board(): BelongsTo
    {
        return $this->belongsTo(PipelineBoard::class, 'board_id');
    }

    public function stage(): BelongsTo
    {
        return $this->belongsTo(PipelineStage::class, 'stage_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function keyResults(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->where('type', 'key_result');
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(User::class, 'member_user_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function allocations(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(PipelineBoardTargetAllocation::class, 'target_id');
    }

    public function events(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(PipelineBoardTargetEvent::class, 'target_id');
    }
}
