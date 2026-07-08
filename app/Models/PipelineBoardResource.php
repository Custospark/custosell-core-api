<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PipelineBoardResource extends Model
{
    protected $fillable = [
        'board_id',
        'user_id',
        'type',
        'title',
        'description',
        'visibility',
        'group_name',
        'url',
        'file_name',
        'file_path',
        'mime_type',
        'file_size',
        'views_count',
        'downloads_count',
    ];

    protected function casts(): array
    {
        return [
            'file_size' => 'integer',
            'views_count' => 'integer',
            'downloads_count' => 'integer',
        ];
    }

    public function board(): BelongsTo
    {
        return $this->belongsTo(PipelineBoard::class, 'board_id');
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function memberLinks(): HasMany
    {
        return $this->hasMany(PipelineBoardResourceMember::class, 'resource_id');
    }

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'pipeline_board_resource_members', 'resource_id', 'user_id');
    }
}
