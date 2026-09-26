<?php

namespace App\Providers;

use App\Services\Assistant\Tools\AssistantToolExecutor;
use App\Services\Assistant\Tools\AccountingSnapshotTool;
use App\Services\Assistant\Tools\DocumentsRecentTool;
use App\Services\Assistant\Tools\ExpenseSummaryTool;
use App\Services\Assistant\Tools\FiscalStatusTool;
use App\Services\Assistant\Tools\ForecastCashTool;
use App\Services\Assistant\Tools\HrTodayTool;
use App\Services\Assistant\Tools\LowStockTool;
use App\Services\Assistant\Tools\OutstandingInvoicesTool;
use App\Services\Assistant\Tools\PipelineDealsTool;
use App\Services\Assistant\Tools\ProductSearchTool;
use App\Services\Assistant\Tools\ProjectsOverviewTool;
use App\Services\Assistant\Tools\SalesHistoryTool;
use App\Services\Assistant\Tools\SalesTodayTool;
use App\Services\Assistant\Tools\TopCustomersTool;
use App\Services\ChartOfAccountService;
use App\Services\Contracts\CustomerServiceInterface;
use App\Services\Contracts\EstimateServiceInterface;
use App\Services\Contracts\ExpenseServiceInterface;
use App\Services\Contracts\InvoiceServiceInterface;
use App\Services\Contracts\ProductServiceInterface;
use App\Services\Contracts\ProjectServiceInterface;
use App\Services\Contracts\SaleServiceInterface;
use App\Services\Forecasting\ForecastBudgetService;
use App\Services\Forecasting\ForecastScenarioService;
use App\Services\Hr\HrEmployeeService;
use App\Services\Hr\HrLeaveService;
use App\Services\Hr\HrOrgService;
use App\Services\ModuleAccessService;
use Illuminate\Support\ServiceProvider;

class AssistantServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(AssistantToolExecutor::class, function ($app) {
            $access = $app->make(ModuleAccessService::class);

            return new AssistantToolExecutor([
                new SalesTodayTool($access, $app->make(SaleServiceInterface::class)),
                new SalesHistoryTool($access, $app->make(SaleServiceInterface::class)),
                new LowStockTool($access, $app->make(ProductServiceInterface::class)),
                new ProductSearchTool($access, $app->make(ProductServiceInterface::class)),
                new TopCustomersTool($access, $app->make(CustomerServiceInterface::class)),
                new OutstandingInvoicesTool($access, $app->make(InvoiceServiceInterface::class)),
                new ExpenseSummaryTool($access, $app->make(ExpenseServiceInterface::class)),
                new FiscalStatusTool($access),
                new PipelineDealsTool($access),
                new ProjectsOverviewTool(
                    $access,
                    $app->make(ProjectServiceInterface::class),
                    $app->make(EstimateServiceInterface::class),
                ),
                new AccountingSnapshotTool($access, $app->make(ChartOfAccountService::class)),
                new ForecastCashTool(
                    $access,
                    $app->make(ForecastBudgetService::class),
                    $app->make(ForecastScenarioService::class),
                ),
                new DocumentsRecentTool($access),
                new HrTodayTool(
                    $access,
                    $app->make(HrEmployeeService::class),
                    $app->make(HrOrgService::class),
                    $app->make(HrLeaveService::class),
                ),
            ], $access);
        });
    }

    public function boot(): void
    {
        //
    }
}
