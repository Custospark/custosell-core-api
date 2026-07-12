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

        static::created(function (Business $business): void {
            app(\App\Services\Documents\DocumentCabinetService::class)
                ->seedDefaultCabinets((int) $business->id, $business->owner_id ? (int) $business->owner_id : null);
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
        'payment_bank_name',
        'payment_bank_account_name',
        'payment_bank_account_number',
        'payment_bank_branch',
        'payment_mobile_money_provider',
        'payment_mobile_money_account_name',
        'payment_mobile_money_number',
        'payment_instructions',
        'logo_path',
        'documents_cover_color',
        'documents_background_type',
        'documents_background_value',
        'status',
        'status_changed_at',
        'trial_ends_at',
        'is_open_for_supply',
        'supply_headline',
        'storefront_enabled',
        'primary_intent',
        'secondary_intent',
        'intent_completed_at',
        'intent_skipped_at',
    ];

    protected function casts(): array
    {
        return [
            'trial_ends_at' => 'datetime',
            'status_changed_at' => 'datetime',
            'intent_completed_at' => 'datetime',
            'intent_skipped_at' => 'datetime',
            'default_vat_rate' => 'decimal:2',
            'prices_include_tax' => 'boolean',
            'is_open_for_supply' => 'boolean',
            'storefront_enabled' => 'boolean',
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

    /** Purchase orders where this business is the buyer. */
    public function purchaseOrders(): HasMany
    {
        return $this->hasMany(PurchaseOrder::class, 'buyer_business_id');
    }

    /** Purchase orders where this business is the seller (incoming supply orders). */
    public function incomingPurchaseOrders(): HasMany
    {
        return $this->hasMany(PurchaseOrder::class, 'seller_business_id');
    }

    public function isOpenForSupply(): bool
    {
        return (bool) $this->is_open_for_supply;
    }

    public function storefrontRatings(): HasMany
    {
        return $this->hasMany(BusinessStorefrontRating::class);
    }

    /** Eager-load with a user_id constraint for the signed-in viewer. */
    public function myStorefrontRating(): HasOne
    {
        return $this->hasOne(BusinessStorefrontRating::class);
    }
}
