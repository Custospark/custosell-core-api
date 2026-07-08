<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pipeline_board_resources', function (Blueprint $table) {
            $table->string('group_name', 100)->nullable()->after('visibility');
        });
    }

    public function down(): void
    {
        Schema::table('pipeline_board_resources', function (Blueprint $table) {
            $table->dropColumn('group_name');
        });
    }
};
