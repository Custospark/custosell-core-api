<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BusinessCategory extends Model
{
    protected $fillable = [
        'slug',
        'name',
        'sort_order',
    ];

    public function businesses(): HasMany
    {
        return $this->hasMany(Business::class);
    }
}