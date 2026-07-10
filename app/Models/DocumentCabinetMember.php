<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DocumentCabinetMember extends Model
{
    protected $fillable = [
        'cabinet_id',
        'user_id',
        'role',
    ];

    public function cabinet(): BelongsTo
    {
        return $this->belongsTo(DocumentCabinet::class, 'cabinet_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
