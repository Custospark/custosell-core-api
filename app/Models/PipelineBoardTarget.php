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
        'parent_id',
        'type',
        'title',
        'description',
        'metric_key',
        'target_value',
        'unit',
        'period_type',
        'period_start',
        'period_end',
        'scope',
        'member_user_id',
        'weight',
        'status',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'target_value' => 'decimal:4',
            'period_start' => 'date',
            'period_end' => 'date',
            'weight' => 'integer',
        ];
    }

    public function board(): BelongsTo
    {
        return $this->belongsTo(PipelineBoard::class, 'board_id');
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
}
