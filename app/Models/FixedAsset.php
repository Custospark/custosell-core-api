<?php

namespace App\Models;

use App\Models\Hr\HrEmployee;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class FixedAsset extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'business_id',
        'account_id',
        'name',
        'cost',
        'salvage_value',
        'useful_life_months',
        'purchase_date',
        'book_value',
        'status',
        'notes',
        'asset_tag',
        'serial_number',
        'category',
        'location',
        'condition',
        'assigned_employee_id',
        'assigned_at',
        'returned_at',
    ];

    protected function casts(): array
    {
        return [
            'cost' => 'decimal:2',
            'salvage_value' => 'decimal:2',
            'book_value' => 'decimal:2',
            'purchase_date' => 'date',
            'assigned_at' => 'datetime',
            'returned_at' => 'datetime',
        ];
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function chartOfAccount(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'account_id');
    }

    public function depreciationEntries(): HasMany
    {
        return $this->hasMany(DepreciationEntry::class, 'asset_id');
    }

    public function assignedEmployee(): BelongsTo
    {
        return $this->belongsTo(HrEmployee::class, 'assigned_employee_id');
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(FixedAssetAssignment::class, 'asset_id')->orderByDesc('occurred_at');
    }

    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class, 'fixed_asset_id');
    }
}
