<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('locations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('business_id');
            $table->string('name', 150);
            $table->string('code', 30)->nullable();
            $table->string('address', 255)->nullable();
            $table->string('city', 120)->nullable();
            $table->string('state', 120)->nullable();
            $table->string('postal_code', 30)->nullable();
            $table->string('country', 10)->nullable();
            $table->string('phone', 50)->nullable();
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('business_id')->references('id')->on('businesses')->cascadeOnDelete();
            $table->index('business_id');
            $table->index(['business_id', 'is_active']);
        });

        Schema::create('location_user', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('business_id');
            $table->unsignedBigInteger('location_id');
            $table->unsignedBigInteger('user_id');
            $table->timestamps();

            $table->foreign('business_id')->references('id')->on('businesses')->cascadeOnDelete();
            $table->foreign('location_id')->references('id')->on('locations')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->unique(['location_id', 'user_id']);
            $table->index('business_id');
            $table->index('user_id');
        });

        Schema::create('location_product', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('business_id');
            $table->unsignedBigInteger('location_id');
            $table->unsignedBigInteger('product_id');
            $table->integer('stock_quantity')->default(0);
            $table->integer('low_stock_threshold')->default(0);
            $table->timestamps();

            $table->foreign('business_id')->references('id')->on('businesses')->cascadeOnDelete();
            $table->foreign('location_id')->references('id')->on('locations')->cascadeOnDelete();
            $table->foreign('product_id')->references('id')->on('products')->cascadeOnDelete();
            $table->unique(['location_id', 'product_id']);
            $table->index('business_id');
            $table->index('product_id');
        });

        // Add location_id columns to transaction/user tables (nullable; backfilled below)
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedBigInteger('location_id')->nullable()->after('business_id');
            $table->foreign('location_id')->references('id')->on('locations')->nullOnDelete();
            $table->index('location_id');
        });

        Schema::table('shifts', function (Blueprint $table) {
            $table->unsignedBigInteger('location_id')->nullable()->after('business_id');
            $table->foreign('location_id')->references('id')->on('locations')->nullOnDelete();
            $table->index('location_id');
        });

        Schema::table('sales', function (Blueprint $table) {
            $table->unsignedBigInteger('location_id')->nullable()->after('business_id');
            $table->foreign('location_id')->references('id')->on('locations')->nullOnDelete();
            $table->index('location_id');
        });

        Schema::table('stock_movements', function (Blueprint $table) {
            $table->unsignedBigInteger('location_id')->nullable()->after('business_id');
            $table->foreign('location_id')->references('id')->on('locations')->nullOnDelete();
            $table->index('location_id');
        });

        // Backfill: every business gets a default location, existing rows point to it,
        // and each existing product gets a per-location stock row.
        DB::transaction(function () {
            $businessIds = DB::table('businesses')->pluck('id');

            foreach ($businessIds as $businessId) {
                $now = now();

                $locationId = DB::table('locations')->insertGetId([
                    'business_id' => $businessId,
                    'name' => 'Main Branch',
                    'code' => 'MAIN',
                    'country' => DB::table('businesses')->where('id', $businessId)->value('country') ?? 'UG',
                    'is_default' => true,
                    'is_active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                DB::table('users')->where('business_id', $businessId)->update(['location_id' => $locationId]);
                DB::table('shifts')->where('business_id', $businessId)->update(['location_id' => $locationId]);
                DB::table('sales')->where('business_id', $businessId)->update(['location_id' => $locationId]);
                DB::table('stock_movements')->where('business_id', $businessId)->update(['location_id' => $locationId]);

                $productRows = DB::table('products')->where('business_id', $businessId)
                    ->get(['id', 'stock_quantity', 'low_stock_threshold']);

                foreach ($productRows as $product) {
                    DB::table('location_product')->insert([
                        'business_id' => $businessId,
                        'location_id' => $locationId,
                        'product_id' => $product->id,
                        'stock_quantity' => $product->stock_quantity,
                        'low_stock_threshold' => $product->low_stock_threshold,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }
            }
        });
    }

    public function down(): void
    {
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->dropForeign(['location_id']);
            $table->dropIndex(['location_id']);
            $table->dropColumn('location_id');
        });

        Schema::table('sales', function (Blueprint $table) {
            $table->dropForeign(['location_id']);
            $table->dropIndex(['location_id']);
            $table->dropColumn('location_id');
        });

        Schema::table('shifts', function (Blueprint $table) {
            $table->dropForeign(['location_id']);
            $table->dropIndex(['location_id']);
            $table->dropColumn('location_id');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['location_id']);
            $table->dropIndex(['location_id']);
            $table->dropColumn('location_id');
        });

        Schema::dropIfExists('location_product');
        Schema::dropIfExists('location_user');
        Schema::dropIfExists('locations');
    }
};
