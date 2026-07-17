<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('board_wall_posts', function (Blueprint $table) {
            $table->string('photo')->nullable()->after('content');
            $table->foreignId('staff_id')->nullable()->after('photo')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('board_wall_posts', function (Blueprint $table) {
            $table->dropForeign(['staff_id']);
            $table->dropColumn(['photo', 'staff_id']);
        });
    }
};
