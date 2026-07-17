<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class PipelineLeadMeeting extends Model
{
    protected $fillable = [
        'lead_id',
        'status',
        'start_date',
        'end_date',
        'meeting_link',
        'notes',
        'reference_code',
        'rejection_reason',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'datetime',
            'end_date' => 'datetime',
        ];
    }

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (self $meeting) {
            if (!$meeting->reference_code) {
                $meeting->reference_code = strtoupper(Str::random(6));
            }
        });
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(PipelineLead::class, 'lead_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}