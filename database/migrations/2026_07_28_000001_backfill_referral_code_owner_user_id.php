<?php

use App\Models\ReferralCode;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        ReferralCode::whereNull('owner_user_id')
            ->whereNotNull('owner_business_id')
            ->each(function (ReferralCode $code) {
                $business = $code->ownerBusiness;
                if ($business && $business->owner_id) {
                    $code->owner_user_id = $business->owner_id;
                    $code->save();
                }
            });
    }

    public function down(): void
    {
        // Irreversible — we don't know which were auto-set
    }
};
