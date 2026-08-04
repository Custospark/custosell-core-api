<?php

namespace App\Services\Contracts;

use App\Models\BusinessSocialLink;
use Illuminate\Database\Eloquent\Collection;

interface BusinessSocialLinkServiceInterface
{
    public function getAll(int $businessId): Collection;

    public function getById(int $id): ?BusinessSocialLink;

    public function create(int $businessId, array $data): BusinessSocialLink;

    public function update(int $id, array $data): BusinessSocialLink;

    public function delete(int $id): bool;
}