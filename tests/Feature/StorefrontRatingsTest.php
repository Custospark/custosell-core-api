<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;

class StorefrontRatingsTest extends StorefrontTestCase
{
    public function test_authenticated_buyer_can_rate_listed_product(): void
    {
        $buyer = User::factory()->create(['is_active' => true]);
        $buyerToken = $buyer->createToken('t')->plainTextToken;

        $res = $this->withHeader('Authorization', 'Bearer '.$buyerToken)
            ->postJson('/api/v1/storefront/devine-mercy-restaurant/products/'.$this->listed->id.'/ratings', [
                'rating' => 5,
            ]);

        $res->assertOk()
            ->assertJsonPath('data.rating_avg', 5)
            ->assertJsonPath('data.rating_count', 1)
            ->assertJsonPath('data.my_rating', 5);

        $this->assertDatabaseHas('product_storefront_ratings', [
            'product_id' => $this->listed->id,
            'user_id' => $buyer->id,
            'rating' => 5,
        ]);

        $this->withHeader('Authorization', 'Bearer '.$buyerToken)
            ->postJson('/api/v1/storefront/devine-mercy-restaurant/products/'.$this->listed->id.'/ratings', [
                'rating' => 4,
            ])
            ->assertOk()
            ->assertJsonPath('data.rating_avg', 4)
            ->assertJsonPath('data.rating_count', 1)
            ->assertJsonPath('data.my_rating', 4);
    }

    public function test_guest_cannot_rate_product(): void
    {
        $this->postJson('/api/v1/storefront/devine-mercy-restaurant/products/'.$this->listed->id.'/ratings', [
            'rating' => 5,
        ])->assertUnauthorized();
    }

    public function test_authenticated_buyer_can_rate_shop(): void
    {
        $buyer = User::factory()->create(['is_active' => true]);
        $buyerToken = $buyer->createToken('t')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$buyerToken)
            ->postJson('/api/v1/storefront/devine-mercy-restaurant/ratings', [
                'rating' => 5,
            ])
            ->assertOk()
            ->assertJsonPath('data.rating_avg', 5)
            ->assertJsonPath('data.rating_count', 1)
            ->assertJsonPath('data.my_rating', 5);

        $this->assertDatabaseHas('business_storefront_ratings', [
            'business_id' => $this->business->id,
            'user_id' => $buyer->id,
            'rating' => 5,
        ]);
    }
}
