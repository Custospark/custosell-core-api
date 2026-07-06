<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Estimate extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'business_id',
        'customer_id',
        'pipeline_lead_id',
        'project_id',
        'invoice_id',
        'parent_estimate_id',
        'estimate_number',
        'version',
        'title',
        'status',
        'currency',
        'subtotal',
        'discount_type',
        'discount_value',
        'discount_amount',
        'tax_rate',
        'tax_total',
        'total',
        'cost_subtotal',
        'gross_profit',
        'margin_percent',
        'valid_until',
        'notes',
        'terms',
        'internal_notes',
        'sent_at',
        'approved_at',
        'approved_by_name',
        'rejection_reason',
        'email_sent_count',
        'last_emailed_at',
        'created_by',
        'assigned_to',
    ];

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'subtotal' => 'decimal:2',
            'discount_value' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'tax_rate' => 'decimal:4',
            'tax_total' => 'decimal:2',
            'total' => 'decimal:2',
            'cost_subtotal' => 'decimal:2',
            'gross_profit' => 'decimal:2',
            'margin_percent' => 'decimal:2',
            'valid_until' => 'date',
            'sent_at' => 'datetime',
            'approved_at' => 'datetime',
            'email_sent_count' => 'integer',
            'last_emailed_at' => 'datetime',
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

    public function pipelineLead(): BelongsTo
    {
        return $this->belongsTo(PipelineLead::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function parentEstimate(): BelongsTo
    {
        return $this->belongsTo(Estimate::class, 'parent_estimate_id');
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(Estimate::class, 'parent_estimate_id');
    }

    public function lineItems(): HasMany
    {
        return $this->hasMany(EstimateLineItem::class)->orderBy('sort_order');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(EstimateVersion::class)->orderByDesc('version');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }
}
