<?php

namespace App\Providers;

use App\Services\Documents\DocumentAccessService;
use App\Services\Documents\DocumentFolderService;
use App\Services\Documents\DocumentService;
use App\Services\Documents\DocumentTagService;
use Illuminate\Support\ServiceProvider;

class DocumentServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(DocumentAccessService::class);
        $this->app->singleton(DocumentFolderService::class);
        $this->app->singleton(DocumentTagService::class);
        $this->app->singleton(DocumentService::class);
    }

    public function boot(): void
    {
        //
    }
}
