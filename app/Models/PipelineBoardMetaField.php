<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PipelineBoardMetaField extends Model
{
    protected $table = 'pipeline_board_meta_fields';

    protected $fillable = [
        'board_id',
        'name',
        'type',
        'options',
        'sort_order',
        'required',
    ];

    protected $casts = [
        'options' => 'array',
        'required' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function board(): BelongsTo
    {
        return $this->belongsTo(PipelineBoard::class, 'board_id');
    }

    public function values(): HasMany
    {
        return $this->hasMany(PipelineLeadMetaValue::class, 'meta_field_id');
    }
}
