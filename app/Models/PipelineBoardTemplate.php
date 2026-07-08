<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PipelineBoardTemplate extends Model
{
    protected $fillable = [
        'business_id',
        'created_by',
        'name',
        'description',
        'workspace',
        'stages',
        'labels',
        'resources',
        'automations',
        'is_system',
    ];

    protected function casts(): array
    {
        return [
            'stages' => 'array',
            'labels' => 'array',
            'resources' => 'array',
            'automations' => 'array',
            'is_system' => 'boolean',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
