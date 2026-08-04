<?php

namespace App\Services;

use App\Models\BusinessSocialLink;
use App\Repositories\Contracts\BusinessSocialLinkRepositoryInterface;
use App\Services\Contracts\BusinessSocialLinkServiceInterface;
use Illuminate\Database\Eloquent\Collection;

class BusinessSocialLinkService implements BusinessSocialLinkServiceInterface
{
    public function __construct(
        protected BusinessSocialLinkRepositoryInterface $linkRepository,
    ) {}

    public function getAll(int $businessId): Collection
    {
        return $this->linkRepository->allForBusiness($businessId);
    }

    public function getById(int $id): ?BusinessSocialLink
    {
        return $this->linkRepository->find($id);
    }

    public function create(int $businessId, array $data): BusinessSocialLink
    {
        $data['business_id'] = $businessId;
        $data['platform'] = mb_strtolower(trim($data['platform']));
        $data['sort_order'] = $data['sort_order'] ?? 0;

        $existing = $this->linkRepository->findByPlatform($businessId, $data['platform']);
        if ($existing) {
            return $this->linkRepository->update($existing, $data);
        }

        return $this->linkRepository->create($data);
    }

    public function update(int $id, array $data): BusinessSocialLink
    {
        $link = $this->linkRepository->find($id);
        if (!$link) {
            throw new \RuntimeException('Social link not found');
        }

        $data['platform'] = mb_strtolower(trim($data['platform'] ?? $link->platform));

        $existing = $this->linkRepository->findByPlatform((int) $link->business_id, $data['platform']);
        if ($existing && (int) $existing->id !== (int) $id) {
            throw new \RuntimeException('You already have a social link for this platform.');
        }

        return $this->linkRepository->update($link, $data);
    }

    public function delete(int $id): bool
    {
        $link = $this->linkRepository->find($id);
        if (!$link) {
            throw new \RuntimeException('Social link not found');
        }
        return $this->linkRepository->delete($link);
    }
}