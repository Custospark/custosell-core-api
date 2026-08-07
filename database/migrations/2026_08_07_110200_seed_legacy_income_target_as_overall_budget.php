<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Fold the legacy single-budget (Business.income_target) into the new
     * PersonalBudget model as an "Overall" budget so there is ONE source of
     * truth. Runs idempotently: only personal accounts, only if they don't
     * already have an "Overall" budget, and only when a legacy target exists.
     */
    public function up(): void
    {
        $rows = DB::table('businesses')
            ->join('users', 'users.business_id', '=', 'businesses.id')
            ->where('users.account_type', 'personal')
            ->where(function ($q) {
                $q->whereNotNull('businesses.income_target')
                    ->where('businesses.income_target', '>', 0);
            })
            ->select('businesses.id as business_id', 'businesses.income_target', 'users.id as user_id')
            ->get();

        foreach ($rows as $row) {
            $exists = DB::table('personal_budgets')
                ->where('business_id', $row->business_id)
                ->where('name', 'Overall')
                ->exists();
            if ($exists) {
                continue;
            }
            DB::table('personal_budgets')->insert([
                'business_id' => $row->business_id,
                'user_id' => $row->user_id,
                'name' => 'Overall',
                'description' => 'Your overall plan, carried over from before.',
                'planned_amount' => $row->income_target,
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('personal_budgets')->where('name', 'Overall')->delete();
    }
};