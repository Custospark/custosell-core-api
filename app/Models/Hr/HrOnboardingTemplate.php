<?php

declare(strict_types=1);

namespace App\Models\Hr;

use App\Models\Business;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class HrOnboardingTemplate extends Model
{
    use SoftDeletes;

    protected $table = 'hr_onboarding_templates';

    protected $fillable = [
        'business_id',
        'name',
        'tasks_json',
    ];

    protected function casts(): array
    {
        return [
            'tasks_json' => 'array',
        ];
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(HrOnboardingTask::class, 'template_id');
    }
}
