<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PipelineChecklistItem extends Model
{
    protected $fillable = [
        'checklist_id',
        'title',
        'is_done',
        'sort_order',
    ];

    protected function casts(): array
    {
        return ['is_done' => 'boolean'];
    }

    public function checklist(): BelongsTo
    {
        return $this->belongsTo(PipelineChecklist::class, 'checklist_id');
    }
}
