<?php

namespace Database\Factories;

use App\Models\Business;
use App\Models\BusinessSocialLink;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BusinessSocialLink>
 */
class BusinessSocialLinkFactory extends Factory
{
    protected $model = BusinessSocialLink::class;

    public function definition(): array
    {
        return [
            'business_id' => Business::factory(),
            'platform' => fake()->randomElement(['facebook', 'youtube', 'instagram', 'twitter', 'tiktok', 'linkedin', 'whatsapp']),
            'url' => fake()->url(),
            'sort_order' => fake()->numberBetween(0, 100),
        ];
    }
}