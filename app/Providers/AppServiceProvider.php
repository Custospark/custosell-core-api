<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
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

        RateLimiter::for('storefront-orders', function (Request $request) {
            $phone = preg_replace('/\s+/', '', (string) $request->input('customer_phone', ''));

            return [
                Limit::perMinute(8)->by($request->ip()),
                Limit::perMinute(5)->by($request->ip().'|'.$phone),
            ];
        });
    }
}
