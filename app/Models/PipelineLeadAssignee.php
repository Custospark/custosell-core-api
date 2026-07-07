<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PipelineLeadAssignee extends Model
{
    protected $fillable = [
        'lead_id',
        'user_id',
        'assigned_by',
    ];

    public function lead(): BelongsTo
    {
        return $this->belongsTo(PipelineLead::class, 'lead_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function assignedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }
}
