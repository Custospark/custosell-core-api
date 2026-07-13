<?php

use App\Models\Business;
use App\Models\User;
use App\Services\ModuleAccessService;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        /** @var ModuleAccessService $moduleAccess */
        $moduleAccess = app(ModuleAccessService::class);

        $ownerIds = Business::query()->pluck('owner_id')->filter()->unique();

        User::query()
            ->whereIn('id', $ownerIds)
            ->each(function (User $user) use ($moduleAccess): void {
                $user->loadMissing('business');
                $moduleAccess->grantMissingCatalogModulesToOwner($user);
            });
    }

    public function down(): void
    {
        // Non-destructive: keep explicit grants after rollback.
    }
};
