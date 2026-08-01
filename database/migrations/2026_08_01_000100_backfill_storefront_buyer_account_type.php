<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        // Legacy storefront buyers were registered with account_type 'storefront_buyer'
        // but flattened to 'personal' on save. A personal account always gets a workspace
        // (business_id set) in the same transaction, so `personal + NULL business_id`
        // reliably identifies a storefront buyer. Restore the distinct type so the
        // frontend can render the Discover-only shopping experience.
        User::query()
            ->where('account_type', 'personal')
            ->whereNull('business_id')
            ->update(['account_type' => 'storefront_buyer']);
    }

    public function down(): void
    {
        User::query()
            ->where('account_type', 'storefront_buyer')
            ->whereNull('business_id')
            ->update(['account_type' => 'personal']);
    }
};
