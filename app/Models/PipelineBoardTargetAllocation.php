<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PipelineBoardTargetAllocation extends Model
{
    protected $fillable = [
        'business_id',
        'target_id',
        'stage_id',
        'planning_level',
        'period_start',
        'period_end',
        'expected_value',
        'actual_value',
        'member_user_id',
        'weight',
        'is_override',
    ];

    protected function casts(): array
    {
        return [
            'period_start' => 'date',
            'period_end' => 'date',
            'expected_value' => 'decimal:4',
            'actual_value' => 'decimal:4',
            'weight' => 'integer',
            'is_override' => 'boolean',
        ];
    }

    public function target(): BelongsTo
    {
        return $this->belongsTo(PipelineBoardTarget::class, 'target_id');
    }

    public function stage(): BelongsTo
    {
        return $this->belongsTo(PipelineStage::class, 'stage_id');
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(User::class, 'member_user_id');
    }
}
