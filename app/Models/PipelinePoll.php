<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PipelinePoll extends Model
{
    protected $fillable = [
        'business_id',
        'board_id',
        'lead_id',
        'created_by',
        'question',
        'closes_at',
        'allow_multiple',
        'results_visibility',
    ];

    protected function casts(): array
    {
        return [
            'closes_at' => 'datetime',
            'allow_multiple' => 'boolean',
        ];
    }

    public function board(): BelongsTo
    {
        return $this->belongsTo(PipelineBoard::class, 'board_id');
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(PipelineLead::class, 'lead_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function options(): HasMany
    {
        return $this->hasMany(PipelinePollOption::class, 'poll_id')->orderBy('sort_order');
    }

    public function votes(): HasMany
    {
        return $this->hasMany(PipelinePollVote::class, 'poll_id');
    }
}
