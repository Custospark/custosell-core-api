<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LinkedAccountCluster extends Model
{
    protected $fillable = [];

    public function members(): HasMany
    {
        return $this->hasMany(LinkedAccount::class, 'cluster_id');
    }
}
