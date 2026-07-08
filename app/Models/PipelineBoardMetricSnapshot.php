<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PipelineBoardMetricSnapshot extends Model
{
    protected $fillable = [
        'business_id',
        'board_id',
        'snapshot_date',
        'metric_key',
        'scope',
        'member_user_id',
        'actual_value',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'snapshot_date' => 'date',
            'actual_value' => 'decimal:4',
            'metadata' => 'array',
        ];
    }

    public function board(): BelongsTo
    {
        return $this->belongsTo(PipelineBoard::class, 'board_id');
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(User::class, 'member_user_id');
    }
}
