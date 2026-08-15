<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quick_notes', function (Blueprint $table) {
            $table->unsignedBigInteger('sort_order')->default(0)->after('is_pinned');
        });
    }

    public function down(): void
    {
        Schema::table('quick_notes', function (Blueprint $table) {
            $table->dropColumn('sort_order');
        });
    }
};
