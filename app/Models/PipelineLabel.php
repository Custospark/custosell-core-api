<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class PipelineLabel extends Model
{
    protected $fillable = [
        'business_id',
        'board_id',
        'name',
        'color',
        'sort_order',
    ];

    public function board(): BelongsTo
    {
        return $this->belongsTo(PipelineBoard::class, 'board_id');
    }

    public function leads(): BelongsToMany
    {
        return $this->belongsToMany(PipelineLead::class, 'pipeline_lead_labels', 'label_id', 'lead_id');
    }
}
