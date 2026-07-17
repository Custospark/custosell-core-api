<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pipeline_leads', function (Blueprint $table) {
            $table->dateTime('start_date')->nullable()->change();
            $table->dateTime('due_date')->nullable()->change();
            $table->dateTime('expected_close_date')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('pipeline_leads', function (Blueprint $table) {
            $table->date('start_date')->nullable()->change();
            $table->date('due_date')->nullable()->change();
            $table->date('expected_close_date')->nullable()->change();
        });
    }
};
