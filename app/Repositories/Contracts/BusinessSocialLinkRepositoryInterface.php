<?php

namespace App\Repositories\Contracts;

use App\Models\BusinessSocialLink;
use Illuminate\Database\Eloquent\Collection;

interface BusinessSocialLinkRepositoryInterface
{
    public function allForBusiness(int $businessId): Collection;

    public function find(int $id): ?BusinessSocialLink;

    public function findByPlatform(int $businessId, string $platform): ?BusinessSocialLink;

    public function create(array $data): BusinessSocialLink;

    public function update(BusinessSocialLink $link, array $data): BusinessSocialLink;

    public function delete(BusinessSocialLink $link): bool;
}