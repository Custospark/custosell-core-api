<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PipelineLeadLink extends Model
{
    protected $table = 'pipeline_lead_links';

    protected $fillable = [
        'lead_id',
        'linked_lead_id',
        'linked_board_id',
        'label',
        'created_by',
    ];

    public function lead(): BelongsTo
    {
        return $this->belongsTo(PipelineLead::class, 'lead_id');
    }

    public function linkedLead(): BelongsTo
    {
        return $this->belongsTo(PipelineLead::class, 'linked_lead_id');
    }

    public function linkedBoard(): BelongsTo
    {
        return $this->belongsTo(PipelineBoard::class, 'linked_board_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
