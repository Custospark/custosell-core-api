<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProjectTask extends Model
{
    protected $fillable = [
        'project_id',
        'name',
        'description',
        'status',
        'sort_order',
        'estimated_hours',
        'actual_hours',
        'budget_cost',
        'due_date',
        'assigned_to',
    ];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'estimated_hours' => 'decimal:2',
            'actual_hours' => 'decimal:2',
            'budget_cost' => 'decimal:2',
            'due_date' => 'date',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function timesheetEntries(): HasMany
    {
        return $this->hasMany(TimesheetEntry::class);
    }
}
