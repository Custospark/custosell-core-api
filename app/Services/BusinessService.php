<?php

namespace App\Services;

use App\Models\Business;
use App\Models\User;
use App\Repositories\Contracts\BusinessRepositoryInterface;
use App\Repositories\Contracts\UserRepositoryInterface;
use App\Services\Contracts\BusinessServiceInterface;
use App\Services\ModuleAccessService;
use App\Services\Platform\PlatformAdminService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

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
}
