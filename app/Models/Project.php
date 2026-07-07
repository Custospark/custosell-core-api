<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Project extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'business_id',
        'customer_id',
        'estimate_id',
        'pipeline_lead_id',
        'project_number',
        'name',
        'status',
        'currency',
        'budget_revenue',
        'budget_cost',
        'actual_cost',
        'actual_revenue',
        'start_date',
        'due_date',
        'completed_at',
        'description',
        'manager_id',
        'created_by',
        'is_personal',
    ];

    protected function casts(): array
    {
        return [
            'budget_revenue' => 'decimal:2',
            'budget_cost' => 'decimal:2',
            'actual_cost' => 'decimal:2',
            'actual_revenue' => 'decimal:2',
            'start_date' => 'date',
            'due_date' => 'date',
            'completed_at' => 'date',
            'is_personal' => 'boolean',
        ];
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function estimate(): BelongsTo
    {
        return $this->belongsTo(Estimate::class);
    }

    public function pipelineLead(): BelongsTo
    {
        return $this->belongsTo(PipelineLead::class);
    }

    public function manager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'manager_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(ProjectTask::class)->orderBy('sort_order');
    }

    public function timesheetEntries(): HasMany
    {
        return $this->hasMany(TimesheetEntry::class);
    }

    public function costAllocations(): HasMany
    {
        return $this->hasMany(ProjectCostAllocation::class);
    }

    public function members(): HasMany
    {
        return $this->hasMany(ProjectMember::class);
    }

    public function board(): HasOne
    {
        return $this->hasOne(PipelineBoard::class, 'project_id');
    }
}
