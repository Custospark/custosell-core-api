<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PipelineBoardActivityEvent extends Model
{
    protected $fillable = [
        'business_id',
        'board_id',
        'user_id',
        'event_type',
        'title',
        'body',
        'entity_type',
        'entity_id',
        'metadata',
    ];

    protected function casts(): array
    {
        return ['metadata' => 'array'];
    }

    public function board(): BelongsTo
    {
        return $this->belongsTo(PipelineBoard::class, 'board_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
