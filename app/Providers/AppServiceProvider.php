<?php

namespace App\Providers;

use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // Alias payment payables only — do not use enforceMorphMap (breaks Spatie User roles, etc.)
        Relation::morphMap([
            'invoice' => \App\Models\Invoice::class,
            'sale' => \App\Models\Sale::class,
        ]);
    }
}
