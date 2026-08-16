<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LinkedAccount extends Model
{
    protected $fillable = [
        'owner_user_id',
        'linked_user_id',
        'relation',
    ];

    protected function casts(): array
    {
        return [
            'relation' => 'string',
        ];
    }

    public function ownerUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function linkedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'linked_user_id');
    }
}
