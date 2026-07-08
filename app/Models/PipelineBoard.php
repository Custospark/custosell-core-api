<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PipelineBoard extends Model
{
    protected $fillable = [
        'business_id',
        'created_by',
        'name',
        'description',
        'visibility',
        'cover_color',
        'is_default',
        'is_archived',
        'project_id',
        'workspace',
        'background_type',
        'background_value',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'is_archived' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function members(): HasMany
    {
        return $this->hasMany(PipelineBoardMember::class, 'board_id');
    }

    public function stages(): HasMany
    {
        return $this->hasMany(PipelineStage::class, 'board_id')->orderBy('sort_order');
    }

    public function leads(): HasMany
    {
        return $this->hasMany(PipelineLead::class, 'board_id');
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function resources(): HasMany
    {
        return $this->hasMany(PipelineBoardResource::class, 'board_id')->orderByDesc('created_at');
    }
}
