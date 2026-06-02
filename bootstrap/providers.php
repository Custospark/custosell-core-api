<?php

use App\Providers\AppServiceProvider;
use App\Providers\BusinessServiceProvider;
use App\Providers\PlanServiceProvider;
use App\Providers\RoleServiceProvider;
use App\Providers\UserServiceProvider;

return [
    AppServiceProvider::class,
    PlanServiceProvider::class,
    UserServiceProvider::class,
    BusinessServiceProvider::class,
    RoleServiceProvider::class,
];
