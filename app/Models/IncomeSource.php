<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class IncomeSource extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'business_id',
        'user_id',
        'budget_id',
        'amount',
        'source_name',
        'description',
        'income_date',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'income_date' => 'date',
        ];
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function personalBudget(): BelongsTo
    {
        return $this->belongsTo(PersonalBudget::class, 'budget_id');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(IncomeSourceAttachment::class, 'income_source_id')->orderByDesc('created_at');
    }
}
