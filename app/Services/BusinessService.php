<?php

namespace App\Services;

use App\Models\Business;
use App\Repositories\Contracts\BusinessRepositoryInterface;
use App\Repositories\Contracts\UserRepositoryInterface;
use App\Services\Contracts\BusinessServiceInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class BusinessService implements BusinessServiceInterface
{
    public function __construct(
        protected BusinessRepositoryInterface $businessRepository,
        protected UserRepositoryInterface $userRepository,
    ) {}

    public function getById(int $id): ?Business
    {
        return $this->businessRepository->find($id);
    }

    public function getByOwner(int $ownerId): ?Business
    {
        return $this->businessRepository->findByOwner($ownerId);
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
            $user->save();

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
}
