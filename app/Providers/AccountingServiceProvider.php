<?php

namespace App\Providers;

use App\Repositories\Contracts\AccountingPeriodRepositoryInterface;
use App\Repositories\Contracts\AccountTypeRepositoryInterface;
use App\Repositories\Contracts\ChartOfAccountRepositoryInterface;
use App\Repositories\Contracts\FixedAssetRepositoryInterface;
use App\Repositories\Contracts\GeneralLedgerRepositoryInterface;
use App\Repositories\Contracts\JournalEntryRepositoryInterface;
use App\Repositories\Eloquent\AccountingPeriodRepository;
use App\Repositories\Eloquent\AccountTypeRepository;
use App\Repositories\Eloquent\ChartOfAccountRepository;
use App\Repositories\Eloquent\FixedAssetRepository;
use App\Repositories\Eloquent\GeneralLedgerRepository;
use App\Repositories\Eloquent\JournalEntryRepository;
use Illuminate\Support\ServiceProvider;

class AccountingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(
            JournalEntryRepositoryInterface::class,
            JournalEntryRepository::class,
        );

        $this->app->bind(
            FixedAssetRepositoryInterface::class,
            FixedAssetRepository::class,
        );

        $this->app->bind(
            GeneralLedgerRepositoryInterface::class,
            GeneralLedgerRepository::class,
        );

        $this->app->bind(
            AccountingPeriodRepositoryInterface::class,
            AccountingPeriodRepository::class,
        );

        $this->app->bind(
            ChartOfAccountRepositoryInterface::class,
            ChartOfAccountRepository::class,
        );

        $this->app->bind(
            AccountTypeRepositoryInterface::class,
            AccountTypeRepository::class,
        );
    }

    public function boot(): void
    {
        //
    }
}
