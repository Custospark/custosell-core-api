<?php

use App\Providers\AppServiceProvider;
use App\Providers\BusinessServiceProvider;
use App\Providers\BusinessSocialLinkServiceProvider;
use App\Providers\CategoryServiceProvider;
use App\Providers\CustomerServiceProvider;
use App\Providers\ExpenseCategoryServiceProvider;
use App\Providers\ExpenseServiceProvider;
use App\Providers\IncomeSourceServiceProvider;
use App\Providers\InvoiceServiceProvider;
use App\Providers\LocationServiceProvider;
use App\Providers\MarketplaceServiceProvider;
use App\Providers\SupplierListServiceProvider;
use App\Providers\OrderServiceProvider;
use App\Providers\PlanServiceProvider;
use App\Providers\PurchaseOrderServiceProvider;
use App\Providers\AccountingServiceProvider;
use App\Providers\AccountSecurityServiceProvider;
use App\Providers\BillingServiceProvider;
use App\Providers\PaymentGatewayServiceProvider;
use App\Providers\EstimateServiceProvider;
use App\Providers\EventServiceProvider;
use App\Providers\EfrisServiceProvider;
use App\Providers\StorefrontServiceProvider;
use App\Providers\ReferralServiceProvider;
use App\Providers\ProductServiceProvider;
use App\Providers\RoleServiceProvider;
use App\Providers\SaleItemServiceProvider;
use App\Providers\SaleServiceProvider;
use App\Providers\ShiftServiceProvider;
use App\Providers\StockMovementServiceProvider;
use App\Providers\SubscriptionServiceProvider;
use App\Providers\DocumentServiceProvider;
use App\Providers\CurrencyServiceProvider;
use App\Providers\PipelineServiceProvider;
use App\Providers\StaffTransferServiceProvider;
use App\Providers\SyncServiceProvider;
use App\Providers\UserServiceProvider;

return [
    AppServiceProvider::class,
    PlanServiceProvider::class,
    UserServiceProvider::class,
    BusinessServiceProvider::class,
    BusinessSocialLinkServiceProvider::class,
    RoleServiceProvider::class,
    CategoryServiceProvider::class,
    ProductServiceProvider::class,
    CustomerServiceProvider::class,
    LocationServiceProvider::class,
    ShiftServiceProvider::class,
    SaleServiceProvider::class,
    OrderServiceProvider::class,
    MarketplaceServiceProvider::class,
    SupplierListServiceProvider::class,
    PurchaseOrderServiceProvider::class,
    SaleItemServiceProvider::class,
    StockMovementServiceProvider::class,
    SubscriptionServiceProvider::class,
    ExpenseCategoryServiceProvider::class,
    ExpenseServiceProvider::class,
    IncomeSourceServiceProvider::class,
    InvoiceServiceProvider::class,
    StaffTransferServiceProvider::class,
    SyncServiceProvider::class,
    AccountingServiceProvider::class,
    AccountSecurityServiceProvider::class,
    BillingServiceProvider::class,
    PaymentGatewayServiceProvider::class,
    PipelineServiceProvider::class,
    ReferralServiceProvider::class,
    CurrencyServiceProvider::class,
    DocumentServiceProvider::class,
    EstimateServiceProvider::class,
    EventServiceProvider::class,
    EfrisServiceProvider::class,
    StorefrontServiceProvider::class,
];
