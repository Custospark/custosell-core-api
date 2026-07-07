<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PipelineBoardAnnouncement extends Model
{
    protected $fillable = [
        'business_id',
        'board_id',
        'created_by',
        'title',
        'body',
        'is_pinned',
    ];

    protected function casts(): array
    {
        return [
            'is_pinned' => 'boolean',
        ];
    }

    public function board(): BelongsTo
    {
        return $this->belongsTo(PipelineBoard::class, 'board_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function reads(): HasMany
    {
        return $this->hasMany(PipelineBoardAnnouncementRead::class, 'announcement_id');
    }
}
