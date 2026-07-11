<?php

use App\Providers\AppServiceProvider;
use App\Providers\BusinessServiceProvider;
use App\Providers\CategoryServiceProvider;
use App\Providers\CustomerServiceProvider;
use App\Providers\ExpenseCategoryServiceProvider;
use App\Providers\ExpenseServiceProvider;
use App\Providers\InvoiceServiceProvider;
use App\Providers\OrderServiceProvider;
use App\Providers\PlanServiceProvider;
use App\Providers\AccountingServiceProvider;
use App\Providers\EstimateServiceProvider;
use App\Providers\EventServiceProvider;
use App\Providers\ProductServiceProvider;
use App\Providers\RoleServiceProvider;
use App\Providers\SaleItemServiceProvider;
use App\Providers\SaleServiceProvider;
use App\Providers\ShiftServiceProvider;
use App\Providers\StockMovementServiceProvider;
use App\Providers\SubscriptionServiceProvider;
use App\Providers\DocumentServiceProvider;
use App\Providers\PipelineServiceProvider;
use App\Providers\SyncServiceProvider;
use App\Providers\UserServiceProvider;

return [
    AppServiceProvider::class,
    PlanServiceProvider::class,
    UserServiceProvider::class,
    BusinessServiceProvider::class,
    RoleServiceProvider::class,
    CategoryServiceProvider::class,
    ProductServiceProvider::class,
    CustomerServiceProvider::class,
    ShiftServiceProvider::class,
    SaleServiceProvider::class,
    OrderServiceProvider::class,
    SaleItemServiceProvider::class,
    StockMovementServiceProvider::class,
    SubscriptionServiceProvider::class,
    ExpenseCategoryServiceProvider::class,
    ExpenseServiceProvider::class,
    InvoiceServiceProvider::class,
    SyncServiceProvider::class,
    AccountingServiceProvider::class,
    PipelineServiceProvider::class,
    DocumentServiceProvider::class,
    EstimateServiceProvider::class,
    EventServiceProvider::class,
];
