<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('account_types', function (Blueprint $table) {
            $table->id();
            $table->string('name', 50);
            $table->string('normal_balance', 6);
            $table->timestamps();
        });

        DB::table('account_types')->insert([
            ['name' => 'Asset', 'normal_balance' => 'debit'],
            ['name' => 'Liability', 'normal_balance' => 'credit'],
            ['name' => 'Equity', 'normal_balance' => 'credit'],
            ['name' => 'Revenue', 'normal_balance' => 'credit'],
            ['name' => 'Expense', 'normal_balance' => 'debit'],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('account_types');
    }
};
