<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PipelinePollOption extends Model
{
    protected $fillable = [
        'poll_id',
        'label',
        'sort_order',
    ];

    public function poll(): BelongsTo
    {
        return $this->belongsTo(PipelinePoll::class, 'poll_id');
    }

    public function votes(): HasMany
    {
        return $this->hasMany(PipelinePollVote::class, 'option_id');
    }
}
