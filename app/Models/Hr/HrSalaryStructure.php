<?php

declare(strict_types=1);

namespace App\Models\Hr;

use App\Models\Business;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class HrSalaryStructure extends Model
{
    use SoftDeletes;

    protected $table = 'hr_salary_structures';

    protected $fillable = [
        'business_id',
        'name',
        'currency',
    ];

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function compensations(): HasMany
    {
        return $this->hasMany(HrEmployeeCompensation::class, 'structure_id');
    }
}
