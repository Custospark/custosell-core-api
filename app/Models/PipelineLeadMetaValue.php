<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PipelineLeadMetaValue extends Model
{
    protected $table = 'pipeline_lead_meta_values';

    protected $fillable = [
        'lead_id',
        'meta_field_id',
        'value',
    ];

    public function lead(): BelongsTo
    {
        return $this->belongsTo(PipelineLead::class, 'lead_id');
    }

    public function metaField(): BelongsTo
    {
        return $this->belongsTo(PipelineBoardMetaField::class, 'meta_field_id');
    }
}
