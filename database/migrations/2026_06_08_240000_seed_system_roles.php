<?php

use Database\Seeders\SystemRoleSeeder;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        (new SystemRoleSeeder())->run();
    }

    public function down(): void
    {
        \App\Models\Role::query()->whereNull('business_id')->delete();
    }
};
