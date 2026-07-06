<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TimesheetEntry extends Model
{
    protected $fillable = [
        'business_id',
        'project_id',
        'project_task_id',
        'user_id',
        'entry_date',
        'hours',
        'hourly_rate',
        'total_cost',
        'notes',
        'is_billable',
        'status',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'entry_date' => 'date',
            'hours' => 'decimal:2',
            'hourly_rate' => 'decimal:2',
            'total_cost' => 'decimal:2',
            'is_billable' => 'boolean',
        ];
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function projectTask(): BelongsTo
    {
        return $this->belongsTo(ProjectTask::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
