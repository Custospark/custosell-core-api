<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PipelineBoardProgressConfig extends Model
{
    protected $fillable = [
        'business_id',
        'board_id',
        'user_id',
        'config_json',
    ];

    protected function casts(): array
    {
        return [
            'config_json' => 'array',
        ];
    }

    public function board(): BelongsTo
    {
        return $this->belongsTo(PipelineBoard::class, 'board_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
