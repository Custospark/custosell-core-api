<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pipeline_boards', function (Blueprint $table) {
            $table->string('background_type', 20)->default('color')->after('is_archived');
            $table->string('background_value', 500)->nullable()->after('background_type');
        });

        Schema::table('pipeline_leads', function (Blueprint $table) {
            $table->string('background_color', 20)->nullable()->after('priority');
        });
    }

    public function down(): void
    {
        Schema::table('pipeline_leads', function (Blueprint $table) {
            $table->dropColumn('background_color');
        });

        Schema::table('pipeline_boards', function (Blueprint $table) {
            $table->dropColumn(['background_type', 'background_value']);
        });
    }
};
