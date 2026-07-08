<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PipelineBoardResourceMember extends Model
{
    protected $fillable = [
        'resource_id',
        'user_id',
    ];

    public function resource(): BelongsTo
    {
        return $this->belongsTo(PipelineBoardResource::class, 'resource_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
