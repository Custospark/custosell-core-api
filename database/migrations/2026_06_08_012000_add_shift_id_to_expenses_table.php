<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->unsignedBigInteger('shift_id')->nullable()->after('recorded_by');
            $table->foreign('shift_id')->references('id')->on('shifts')->nullOnDelete();
            $table->index(['business_id', 'shift_id']);
        });
    }

    public function down(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->dropForeign(['shift_id']);
            $table->dropIndex(['business_id', 'shift_id']);
            $table->dropColumn('shift_id');
        });
    }
};
