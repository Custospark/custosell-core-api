<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A single priced line in a PersonalBudget plan / shopping list. A budget's
 * planned_amount auto-totals from its lines; a purchased line can be converted
 * into a real Expense (recorded via budget_id).
 */
class BudgetLine extends Model
{
    protected $fillable = [
        'personal_budget_id',
        'item_name',
        'quantity',
        'unit_price',
        'line_total',
        'purchased',
        'expense_id',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
            'unit_price' => 'decimal:2',
            'line_total' => 'decimal:2',
            'purchased' => 'boolean',
        ];
    }

    public function budget(): BelongsTo
    {
        return $this->belongsTo(PersonalBudget::class, 'personal_budget_id');
    }

    public function expense(): BelongsTo
    {
        return $this->belongsTo(Expense::class);
    }
}