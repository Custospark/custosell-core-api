<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Location extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'business_id',
        'name',
        'code',
        'address',
        'city',
        'state',
        'postal_code',
        'country',
        'phone',
        'is_default',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'location_user')
            ->withPivot('business_id')
            ->withTimestamps();
    }

    public function productStock(): HasMany
    {
        return $this->hasMany(LocationProduct::class);
    }

    public function sales(): HasMany
    {
        return $this->hasMany(Sale::class);
    }

    public function shifts(): HasMany
    {
        return $this->hasMany(Shift::class);
    }

    public function stockMovements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }

    /** @param  \Illuminate\Database\Eloquent\Builder<static>  $query */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /** @param  \Illuminate\Database\Eloquent\Builder<static>  $query */
    public function scopeForBusiness($query, int $businessId)
    {
        return $query->where('business_id', $businessId);
    }
}
