<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PipelineBoardMessage extends Model
{
    protected $fillable = [
        'business_id',
        'board_id',
        'user_id',
        'parent_id',
        'body',
        'is_system',
        'edited_at',
        'is_pinned',
        'pinned_at',
        'pinned_by',
    ];

    protected function casts(): array
    {
        return [
            'edited_at' => 'datetime',
            'pinned_at' => 'datetime',
            'is_pinned' => 'boolean',
            'is_system' => 'boolean',
        ];
    }

    public function board(): BelongsTo
    {
        return $this->belongsTo(PipelineBoard::class, 'board_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function replies(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('created_at');
    }

    public function reactions(): HasMany
    {
        return $this->hasMany(PipelineBoardMessageReaction::class, 'message_id');
    }

    public function mentions(): HasMany
    {
        return $this->hasMany(PipelineBoardMessageMention::class, 'message_id');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(PipelineBoardMessageAttachment::class, 'message_id');
    }

    public function pinnedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'pinned_by');
    }
}
