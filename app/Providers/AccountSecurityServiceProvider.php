<?php

namespace App\Providers;

use App\Services\AccountAuditLogService;
use App\Services\AccountVerificationService;
use App\Services\Contracts\AccountAuditLogServiceInterface;
use App\Services\Contracts\AccountVerificationServiceInterface;
use Illuminate\Support\ServiceProvider;

class AccountSecurityServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(AccountVerificationServiceInterface::class, AccountVerificationService::class);
        $this->app->bind(AccountAuditLogServiceInterface::class, AccountAuditLogService::class);
    }

    public function boot(): void
    {
        //
    }
}