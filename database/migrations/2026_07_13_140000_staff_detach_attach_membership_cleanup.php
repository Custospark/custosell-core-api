<?php

use App\Models\Business;
use App\Models\Hr\HrEmployee;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        $ownerIds = Business::query()->pluck('owner_id')->filter()->unique()->all();

        // Soft-deleted non-owner staff → restore as detached free accounts.
        User::onlyTrashed()
            ->whereNotNull('business_id')
            ->when($ownerIds !== [], fn ($q) => $q->whereNotIn('id', $ownerIds))
            ->each(function (User $user): void {
                $user->restore();
                $user->business_id = null;
                $user->role_id = null;
                $user->modules = [];
                $user->is_active = true;
                $user->save();
            });

        // Inactive non-owner staff still on a business were wrongly "deactivated" for org removal.
        User::query()
            ->where('is_active', false)
            ->whereNotNull('business_id')
            ->when($ownerIds !== [], fn ($q) => $q->whereNotIn('id', $ownerIds))
            ->update(['is_active' => true]);

        // Clear HR links to users no longer on this business (or missing).
        HrEmployee::query()
            ->whereNotNull('user_id')
            ->orderBy('id')
            ->each(function (HrEmployee $employee): void {
                $user = User::withTrashed()->find($employee->user_id);
                if (
                    ! $user
                    || $user->trashed()
                    || $user->business_id === null
                    || (int) $user->business_id !== (int) $employee->business_id
                ) {
                    $employee->user_id = null;
                    $employee->save();
                }
            });
    }

    public function down(): void
    {
        // Non-destructive: membership restores and reactivations stay.
    }
};
