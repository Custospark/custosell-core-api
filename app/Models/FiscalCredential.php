<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FiscalCredential extends Model
{
    use HasFactory;

    protected $fillable = [
        'business_id',
        'location_id',
        'country',
        'tin',
        'device_no',
        'branch_id',
        'api_username',
        'api_password',
        'private_key_path',
        'public_key_path',
        'environment',
        'is_active',
    ];

    /** Never serialize the encrypted API secret. */
    protected $hidden = [
        'api_password',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    /** Branch-scoped rows win; a null location_id row is the business default. */
    public function isBranchScoped(): bool
    {
        return $this->location_id !== null;
    }
}
