<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_cabinets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('visibility', 32)->default('all_staff');
            $table->string('cover_color', 7)->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['business_id', 'sort_order']);
        });

        Schema::create('document_cabinet_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cabinet_id')->constrained('document_cabinets')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('role', 32)->default('viewer');
            $table->timestamps();

            $table->unique(['cabinet_id', 'user_id']);
        });

        Schema::table('document_folders', function (Blueprint $table) {
            $table->foreignId('cabinet_id')->nullable()->after('business_id')->constrained('document_cabinets')->cascadeOnDelete();
        });

        Schema::table('documents', function (Blueprint $table) {
            $table->foreignId('cabinet_id')->nullable()->after('business_id')->constrained('document_cabinets')->cascadeOnDelete();
        });

        $this->migrateExistingData();
    }

    protected function migrateExistingData(): void
    {
        if (! Schema::hasTable('businesses')) {
            return;
        }

        $businessIds = DB::table('businesses')->pluck('id');

        foreach ($businessIds as $businessId) {
            $ownerId = DB::table('businesses')->where('id', $businessId)->value('owner_id');
            if ($ownerId !== null && ! DB::table('users')->where('id', $ownerId)->exists()) {
                $ownerId = null;
            }

            $cabinetId = DB::table('document_cabinets')->insertGetId([
                'business_id' => $businessId,
                'name' => 'General',
                'description' => 'Default document cabinet',
                'visibility' => 'all_staff',
                'sort_order' => 0,
                'created_by' => $ownerId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('document_folders')->where('business_id', $businessId)->update(['cabinet_id' => $cabinetId]);
            DB::table('documents')->where('business_id', $businessId)->update(['cabinet_id' => $cabinetId]);
        }
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->dropConstrainedForeignId('cabinet_id');
        });

        Schema::table('document_folders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('cabinet_id');
        });

        Schema::dropIfExists('document_cabinet_members');
        Schema::dropIfExists('document_cabinets');
    }
};
