<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Expense extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'business_id',
        'expense_category_id',
        'recorded_by',
        'shift_id',
        'project_id',
        'fixed_asset_id',
        'amount',
        'description',
        'reference',
        'supplier_tin',
        'supplier_invoice_no',
        'vat_amount',
        'vat_claimable',
        'receipt_path',
        'is_recurring',
        'recurrence_interval',
        'recurrence_end_date',
        'next_due_date',
        'expense_date',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'vat_amount' => 'decimal:2',
            'vat_claimable' => 'boolean',
            'expense_date' => 'datetime',
            'is_recurring' => 'boolean',
            'recurrence_end_date' => 'date',
            'next_due_date' => 'date',
        ];
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function expenseCategory(): BelongsTo
    {
        return $this->belongsTo(ExpenseCategory::class);
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function fixedAsset(): BelongsTo
    {
        return $this->belongsTo(FixedAsset::class);
    }
}
