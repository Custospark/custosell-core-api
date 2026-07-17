<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use App\Models\PipelineLeadMetaValue;

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
        'booking_status',
        'meeting_link',
        'reference_code',
        'rejection_reason',
        'approved_at',
        'rejected_at',
        'is_pinned',
    ];

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (self $lead) {
            if (!$lead->reference_code) {
                $lead->reference_code = strtoupper(Str::random(6));
            }
        });
    }

    protected function casts(): array
    {
        return [
            'estimated_value' => 'decimal:2',
            'position' => 'decimal:4',
            'expected_close_date' => 'datetime',
            'due_date' => 'datetime',
            'start_date' => 'datetime',
            'won_at' => 'datetime',
            'lost_at' => 'datetime',
            'converted_at' => 'datetime',
            'approved_at' => 'datetime',
            'rejected_at' => 'datetime',
            'is_pinned' => 'boolean',
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

    public function assignees(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'pipeline_lead_assignees', 'lead_id', 'user_id')
            ->withTimestamps()
            ->withPivot('assigned_by');
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

    public function links(): HasMany
    {
        return $this->hasMany(PipelineLeadLink::class, 'lead_id');
    }

    public function linkedFrom(): HasMany
    {
        return $this->hasMany(PipelineLeadLink::class, 'linked_lead_id');
    }

    public function metaValues(): HasMany
    {
        return $this->hasMany(PipelineLeadMetaValue::class, 'lead_id');
    }

    public function meetings(): HasMany
    {
        return $this->hasMany(PipelineLeadMeeting::class, 'lead_id')->orderBy('start_date');
    }
}
