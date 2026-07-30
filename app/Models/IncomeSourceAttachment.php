<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IncomeSourceAttachment extends Model
{
    protected $fillable = [
        'income_source_id',
        'user_id',
        'type',
        'file_name',
        'file_path',
        'link_url',
        'mime_type',
        'file_size',
    ];

    public function incomeSource(): BelongsTo
    {
        return $this->belongsTo(IncomeSource::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
