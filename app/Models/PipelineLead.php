<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class PipelineLead extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'business_id',
        'board_id',
        'stage_id',
        'created_by',
        'assigned_to',
        'customer_id',
        'converted_customer_id',
        'estimate_id',
        'project_task_id',
        'source_id',
        'title',
        'card_type',
        'description',
        'contact_name',
        'contact_email',
        'contact_phone',
        'estimated_value',
        'currency',
        'status',
        'position',
        'expected_close_date',
        'due_date',
        'start_date',
        'priority',
        'background_color',
        'won_at',
        'lost_at',
        'converted_at',
        'lost_reason',
    ];

    protected function casts(): array
    {
        return [
            'estimated_value' => 'decimal:2',
            'position' => 'decimal:4',
            'expected_close_date' => 'date',
            'due_date' => 'date',
            'start_date' => 'date',
            'won_at' => 'datetime',
            'lost_at' => 'datetime',
            'converted_at' => 'datetime',
        ];
    }

    public function board(): BelongsTo
    {
        return $this->belongsTo(PipelineBoard::class, 'board_id');
    }

    public function stage(): BelongsTo
    {
        return $this->belongsTo(PipelineStage::class, 'stage_id');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function convertedCustomer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'converted_customer_id');
    }

    public function estimate(): BelongsTo
    {
        return $this->belongsTo(Estimate::class);
    }

    public function projectTask(): BelongsTo
    {
        return $this->belongsTo(ProjectTask::class);
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(PipelineSource::class, 'source_id');
    }

    public function activities(): HasMany
    {
        return $this->hasMany(PipelineLeadActivity::class, 'lead_id')->orderByDesc('created_at');
    }

    public function labels(): BelongsToMany
    {
        return $this->belongsToMany(PipelineLabel::class, 'pipeline_lead_labels', 'lead_id', 'label_id');
    }

    public function checklists(): HasMany
    {
        return $this->hasMany(PipelineChecklist::class, 'lead_id')->orderBy('sort_order');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(PipelineAttachment::class, 'lead_id')->orderByDesc('created_at');
    }
}
