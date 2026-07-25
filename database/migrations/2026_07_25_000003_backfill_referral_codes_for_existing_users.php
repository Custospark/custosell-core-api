<?php

use App\Enums\Billing\DiscountType;
use App\Enums\Billing\ReferralCodeOwnerType;
use App\Enums\Billing\RewardType;
use App\Models\ReferralCode;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        $users = User::whereDoesntHave('referralCode')->get();

        $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        foreach ($users as $user) {
            $code = '';
            for ($i = 0; $i < 6; $i++) {
                $code .= $chars[random_int(0, strlen($chars) - 1)];
            }
            ReferralCode::create([
                'owner_type' => ReferralCodeOwnerType::BUSINESS,
                'owner_user_id' => $user->id,
                'code' => $code,
                'discount_type' => DiscountType::PERCENTAGE,
                'discount_value' => 10,
                'reward_type' => RewardType::FREE_MONTH,
                'is_active' => true,
            ]);
        }
    }

    public function down(): void
    {
        ReferralCode::where('owner_type', ReferralCodeOwnerType::BUSINESS)
            ->whereNull('owner_business_id')
            ->delete();
    }
};
