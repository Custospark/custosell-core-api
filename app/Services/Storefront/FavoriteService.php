<?php

declare(strict_types=1);

namespace App\Services\Storefront;

use App\Models\Business;
use App\Models\BusinessFavorite;
use Illuminate\Support\Collection;

class FavoriteService
{
    public function __construct(
        private readonly StorefrontService $storefront,
    ) {}

    /**
     * List a buyer's favorite businesses (enabled public storefronts only), newest first.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function list(int $userId, ?int $viewerUserId = null): Collection
    {
        $favorites = BusinessFavorite::query()
            ->where('user_id', $userId)
            ->with(['business' => function ($q) {
                $q->publicStorefront();
            }])
            ->orderByDesc('id')
            ->get();

        return $favorites
            ->filter(fn (BusinessFavorite $f) => $f->business !== null)
            ->map(fn (BusinessFavorite $f) => $this->payload($f->business, $viewerUserId));
    }

    /**
     * Add a business to the buyer's favorites (auto-deduplicates).
     *
     * @return array<string, mixed>
     */
    public function add(int $userId, int $businessId, ?int $viewerUserId = null): array
    {
        $favorite = BusinessFavorite::query()->firstOrCreate(
            ['user_id' => $userId, 'business_id' => $businessId],
        );

        $business = Business::query()->publicStorefront()->find($businessId);

        return [
            'id' => $favorite->id,
            'business' => $business ? $this->shopPayload($business, $viewerUserId) : null,
        ];
    }

    /**
     * Remove a business from favorites. Returns true if deleted, false if not found.
     */
    public function remove(int $userId, int $businessId): bool
    {
        return BusinessFavorite::query()
            ->where('user_id', $userId)
            ->where('business_id', $businessId)
            ->delete() > 0;
    }

    /**
     * Check if a business is in the user's favorites.
     */
    public function isFavorited(int $userId, int $businessId): bool
    {
        return BusinessFavorite::query()
            ->where('user_id', $userId)
            ->where('business_id', $businessId)
            ->exists();
    }

    /**
     * Count favorite businesses for a user.
     */
    public function count(int $userId): int
    {
        return BusinessFavorite::query()
            ->where('user_id', $userId)
            ->count();
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Business $business, ?int $viewerUserId): array
    {
        return [
            'id' => $business->id,
            'business' => $this->shopPayload($business, $viewerUserId),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function shopPayload(Business $business, ?int $viewerUserId): array
    {
        $shop = $this->storefront->shopWithRatings((int) $business->id, $viewerUserId);

        return $this->storefront->publicShopPayload($shop, $viewerUserId);
    }
}