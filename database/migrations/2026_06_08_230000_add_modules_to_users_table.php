<?php

use App\Services\ModuleAccessService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->json('modules')->nullable()->after('role_id');
        });

        $fullModules = json_encode(ModuleAccessService::businessModuleSlugs());

        DB::table('users')
            ->whereNotNull('business_id')
            ->update(['modules' => $fullModules]);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('modules');
        });
    }
};
