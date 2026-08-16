<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Rebuild linked accounts as a cluster (membership) model so a user can
     * link many accounts (2, 3, 4+) and switch to ANY of them from any other.
     * Each linked set is a cluster; every member appears in every other
     * member's switcher.
     */
    public function up(): void
    {
        Schema::create('linked_account_clusters', function (Blueprint $table) {
            $table->id();
            $table->timestamps();
        });

        // linked_accounts was created moments earlier and is not deployed; safe
        // to rebuild as a membership table rather than pairwise edges.
        Schema::dropIfExists('linked_accounts');
        Schema::create('linked_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cluster_id')->constrained('linked_account_clusters')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->boolean('is_primary')->default(false);
            $table->timestamps();

            // One account can belong to exactly one cluster.
            $table->unique('user_id');
            $table->index('cluster_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('linked_accounts');
        Schema::dropIfExists('linked_account_clusters');
    }
};
