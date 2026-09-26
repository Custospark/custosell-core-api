<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Canonical module catalogs. Single source for business/public/platform
 * slugs - ModuleAccessService aliases these for backward compatibility.
 */
final class ModuleCatalog
{
    public const BUSINESS_MODULES = [
        'dashboard',
        'sales',
        'inventory',
        'customers',
        'expenses',
        'accounting',
        'pipeline',
        'estimates',
        'documents',
        'hr',
        'forecasting',
        'settings',
        'efris',
    ];

    public const PUBLIC_MODULES = [
        'account',
        'guide',
    ];

    public const PLATFORM_MODULES = [
        'platform',
        'guide_settings',
    ];

    /**
     * Modules shipped after the early core POS set - granted additively to
     * legacy owners without re-forcing intentional core opt-outs.
     *
     * @var list<string>
     */
    public const POST_CORE_CATALOG_MODULES = [
        'accounting',
        'pipeline',
        'estimates',
        'documents',
        'hr',
        'forecasting',
        'efris',
    ];

    /**
     * Modules always allowed regardless of plan features (like settings).
     * EFRIS rides here during the pilot; paid gating arrives in a later phase.
     *
     * @var list<string>
     */
    public const PLAN_EXEMPT_MODULES = [
        'settings',
        'efris',
    ];
}
