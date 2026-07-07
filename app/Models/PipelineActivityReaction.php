<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PipelineActivityReaction extends Model
{
    protected $fillable = [
        'activity_id',
        'user_id',
        'reaction',
    ];

    public function activity(): BelongsTo
    {
        return $this->belongsTo(PipelineLeadActivity::class, 'activity_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
