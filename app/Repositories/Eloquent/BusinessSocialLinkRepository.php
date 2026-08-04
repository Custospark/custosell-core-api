<?php

namespace App\Repositories\Eloquent;

use App\Models\BusinessSocialLink;
use App\Repositories\Contracts\BusinessSocialLinkRepositoryInterface;
use Illuminate\Database\Eloquent\Collection;

class BusinessSocialLinkRepository implements BusinessSocialLinkRepositoryInterface
{
    public function allForBusiness(int $businessId): Collection
    {
        return BusinessSocialLink::where('business_id', $businessId)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
    }

    public function find(int $id): ?BusinessSocialLink
    {
        return BusinessSocialLink::find($id);
    }

    public function findByPlatform(int $businessId, string $platform): ?BusinessSocialLink
    {
        return BusinessSocialLink::where('business_id', $businessId)
            ->where('platform', $platform)
            ->first();
    }

    public function create(array $data): BusinessSocialLink
    {
        return BusinessSocialLink::create($data);
    }

    public function update(BusinessSocialLink $link, array $data): BusinessSocialLink
    {
        $link->update($data);
        return $link->fresh();
    }

    public function delete(BusinessSocialLink $link): bool
    {
        return $link->delete();
    }
}