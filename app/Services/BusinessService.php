<?php

namespace App\Services;

use App\Models\Business;
use App\Models\User;
use App\Repositories\Contracts\BusinessRepositoryInterface;
use App\Repositories\Contracts\UserRepositoryInterface;
use App\Services\Contracts\BusinessServiceInterface;
use App\Services\ModuleAccessService;
use App\Services\Platform\PlatformAdminService;
use App\Support\StorefrontSlug;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class BusinessService implements BusinessServiceInterface
{
    public function __construct(
        protected BusinessRepositoryInterface $businessRepository,
        protected UserRepositoryInterface $userRepository,
        protected PlatformAdminService $platformAdminService,
        protected ModuleAccessService $moduleAccess,
    ) {}

    public function getById(int $id): ?Business
    {
        return $this->businessRepository->find($id);
    }

    public function getByOwner(int $ownerId): ?Business
    {
        return $this->businessRepository->findByOwner($ownerId);
    }

    public function getForUser(User $user): ?Business
    {
        if ($user->business_id) {
            $business = $this->businessRepository->find($user->business_id);
            if ($business) {
                return $business;
            }
        }

        $owned = $this->businessRepository->findByOwner($user->id);
        if ($owned) {
            return $owned;
        }

        if ($user->email) {
            $byEmail = Business::query()
                ->where('email', $user->email)
                ->first();

            if ($byEmail) {
                return $byEmail;
            }
        }

        return null;
    }

    public function register(array $userData, array $businessData): Business
    {
        return DB::transaction(function () use ($userData, $businessData) {
            $userData['password'] = Hash::make($userData['password']);
            $user = $this->userRepository->create($userData);

            $businessData['owner_id'] = $user->id;
            $baseSlug = $businessData['slug'] ?? Str::slug($businessData['name']);
            $slug = $baseSlug;
            $counter = 1;
            while (\App\Models\Business::where('slug', $slug)->exists()) {
                $slug = $baseSlug . '-' . $counter++;
            }
            $businessData['slug'] = $slug;
            $business = $this->businessRepository->create($businessData);

            $user->business_id = $business->id;
            $user->modules = [
                ...$this->moduleAccess->fullBusinessModulesForOwner(),
                ModuleAccessService::ESTIMATES_FULL_SLUG,
                ModuleAccessService::HR_FULL_SLUG,
            ];
            $user->save();

            $this->platformAdminService->assignIfEligible($user);

            return $business;
        });
    }

    public function update(int $id, array $data): Business
    {
        $business = $this->businessRepository->find($id);
        if (!$business) {
            throw new \RuntimeException('Business not found');
        }
        return $this->businessRepository->update($business, $data);
    }

    public function updateSettings(int $id, array $data): Business
    {
        return $this->update($id, $data);
    }

    public function suspend(int $id): Business
    {
        return $this->update($id, ['status' => 'suspended']);
    }

    public function updateSupplyProfile(int $id, array $data): Business
    {
        return $this->update($id, [
            'is_open_for_supply' => (bool) ($data['is_open_for_supply'] ?? false),
            'supply_headline' => $data['supply_headline'] ?? null,
        ]);
    }

    public function updateStorefrontProfile(int $id, array $data): Business
    {
        $payload = [
            'storefront_enabled' => (bool) ($data['storefront_enabled'] ?? false),
        ];

        if (array_key_exists('slug', $data) && $data['slug'] !== null && trim((string) $data['slug']) !== '') {
            $check = $this->checkSlugAvailability((string) $data['slug'], $id);
            if (!$check['available']) {
                throw ValidationException::withMessages([
                    'slug' => [$check['reason'] ?? 'This shop username is not available.'],
                ]);
            }
            $payload['slug'] = $check['slug'];
        }

        return $this->update($id, $payload);
    }

    public function checkSlugAvailability(string $slug, ?int $ignoreBusinessId = null): array
    {
        $normalized = StorefrontSlug::normalize($slug);
        if ($normalized === '' || !StorefrontSlug::isValidFormat($normalized)) {
            return [
                'available' => false,
                'slug' => $normalized,
                'reason' => 'Use lowercase letters, numbers, and hyphens (2–80 characters).',
            ];
        }
        if (StorefrontSlug::isReserved($normalized)) {
            return [
                'available' => false,
                'slug' => $normalized,
                'reason' => 'This username is reserved.',
            ];
        }

        $exists = Business::query()
            ->where('slug', $normalized)
            ->when($ignoreBusinessId, fn ($q) => $q->where('id', '!=', $ignoreBusinessId))
            ->exists();

        if ($exists) {
            return [
                'available' => false,
                'slug' => $normalized,
                'reason' => 'This shop username is already taken.',
            ];
        }

        return ['available' => true, 'slug' => $normalized];
    }
}
