<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Customer extends Model
{
    use HasFactory;
    protected $fillable = [
        'business_id',
        'user_id',
        'name',
        'phone',
        'email',
        'total_purchases',
        'last_purchase_at',
    ];

    protected function casts(): array
    {
        return [
            'total_purchases' => 'decimal:2',
            'last_purchase_at' => 'datetime',
        ];
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function sales(): HasMany
    {
        return $this->hasMany(Sale::class);
    }

    public static function isSyntheticPhone(?string $phone): bool
    {
        if ($phone === null || $phone === '') {
            return false;
        }

        return str_starts_with($phone, 'em-') || str_starts_with($phone, 'walkin-');
    }

    public function displayPhone(): ?string
    {
        return self::isSyntheticPhone($this->phone) ? null : $this->phone;
    }
}
