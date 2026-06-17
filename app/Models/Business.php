<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Business extends Model
{
    use HasFactory, SoftDeletes;

    protected $attributes = [
        'currency' => 'UGX',
        'status' => 'active',
    ];

    protected static function booted(): void
    {
        static::creating(function (Business $business): void {
            if ($business->status_changed_at === null) {
                $business->status_changed_at = now();
            }
        });
    }

    protected $fillable = [
        'owner_id',
        'name',
        'slug',
        'email',
        'phone',
        'address',
        'website',
        'city',
        'state',
        'postal_code',
        'country',
        'tax_id',
        'tax_regime',
        'jurisdiction',
        'default_vat_rate',
        'prices_include_tax',
        'timezone',
        'business_type',
        'description',
        'business_email',
        'business_phone',
        'currency',
        'receipt_footer',
        'logo_path',
        'status',
        'status_changed_at',
        'trial_ends_at',
    ];

    protected function casts(): array
    {
        return [
            'trial_ends_at' => 'datetime',
            'status_changed_at' => 'datetime',
            'default_vat_rate' => 'decimal:2',
            'prices_include_tax' => 'boolean',
        ];
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function subscription(): HasOne
    {
        return $this->hasOne(Subscription::class);
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function roles(): HasMany
    {
        return $this->hasMany(Role::class);
    }

    public function categories(): HasMany
    {
        return $this->hasMany(Category::class);
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    public function customers(): HasMany
    {
        return $this->hasMany(Customer::class);
    }

    public function sales(): HasMany
    {
        return $this->hasMany(Sale::class);
    }

    public function stockMovements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }

    public function shifts(): HasMany
    {
        return $this->hasMany(Shift::class);
    }

    public function expenseCategories(): HasMany
    {
        return $this->hasMany(ExpenseCategory::class);
    }

    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class);
    }
}
