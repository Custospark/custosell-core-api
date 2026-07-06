<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EstimateVersion extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'estimate_id',
        'version',
        'snapshot',
        'created_by',
        'change_summary',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'snapshot' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function estimate(): BelongsTo
    {
        return $this->belongsTo(Estimate::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
