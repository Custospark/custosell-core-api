<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PipelineBoardTargetEvent extends Model
{
    protected $fillable = [
        'business_id',
        'target_id',
        'user_id',
        'event_type',
        'payload',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
        ];
    }

    public function target(): BelongsTo
    {
        return $this->belongsTo(PipelineBoardTarget::class, 'target_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
