<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PipelineChecklist extends Model
{
    protected $fillable = [
        'lead_id',
        'title',
        'description',
        'sort_order',
    ];

    public function lead(): BelongsTo
    {
        return $this->belongsTo(PipelineLead::class, 'lead_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(PipelineChecklistItem::class, 'checklist_id')->orderBy('sort_order');
    }
}
