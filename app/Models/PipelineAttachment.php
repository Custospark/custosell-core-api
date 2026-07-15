<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PipelineAttachment extends Model
{
    protected $fillable = [
        'lead_id',
        'user_id',
        'type',
        'file_name',
        'file_path',
        'link_url',
        'mime_type',
        'file_size',
    ];

    public function lead(): BelongsTo
    {
        return $this->belongsTo(PipelineLead::class, 'lead_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
