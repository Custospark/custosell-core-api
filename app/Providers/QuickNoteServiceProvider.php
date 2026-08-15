<?php

namespace App\Providers;

use App\Repositories\Contracts\QuickNoteRepositoryInterface;
use App\Repositories\Eloquent\QuickNoteRepository;
use App\Services\Contracts\QuickNoteServiceInterface;
use App\Services\QuickNoteService;
use Illuminate\Support\ServiceProvider;

class QuickNoteServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(
            QuickNoteRepositoryInterface::class,
            QuickNoteRepository::class,
        );

        $this->app->bind(
            QuickNoteServiceInterface::class,
            QuickNoteService::class,
        );
    }

    public function boot(): void
    {
        //
    }
}
