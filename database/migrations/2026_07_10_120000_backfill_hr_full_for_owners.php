<?php

use App\Models\Business;
use App\Models\User;
use App\Services\ModuleAccessService;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        $ownerIds = Business::query()->pluck('owner_id')->filter()->unique();

        User::query()
            ->whereIn('id', $ownerIds)
            ->whereNotNull('modules')
            ->each(function (User $user): void {
                $modules = is_array($user->modules) ? $user->modules : [];
                if ($modules === []) {
                    $user->modules = [
                        ...ModuleAccessService::businessModuleSlugs(),
                        ModuleAccessService::ESTIMATES_FULL_SLUG,
                        ModuleAccessService::HR_FULL_SLUG,
                    ];
                    $user->save();

                    return;
                }

                if (! in_array('hr', $modules, true)) {
                    return;
                }

                if (in_array(ModuleAccessService::HR_FULL_SLUG, $modules, true)) {
                    return;
                }

                $modules[] = ModuleAccessService::HR_FULL_SLUG;
                $user->modules = array_values(array_unique($modules));
                $user->save();
            });
    }

    public function down(): void
    {
        // Non-destructive: keep explicit grants after rollback.
    }
};
