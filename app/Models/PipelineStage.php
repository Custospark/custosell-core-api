<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PipelineStage extends Model
{
    protected $fillable = [
        'business_id',
        'board_id',
        'name',
        'sort_order',
        'color',
        'is_won',
        'is_lost',
        'rotting_days',
    ];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'is_won' => 'boolean',
            'is_lost' => 'boolean',
            'rotting_days' => 'integer',
        ];
    }

    public function board(): BelongsTo
    {
        return $this->belongsTo(PipelineBoard::class, 'board_id');
    }

    public function leads(): HasMany
    {
        return $this->hasMany(PipelineLead::class, 'stage_id')->orderBy('position');
    }
}
