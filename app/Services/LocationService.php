<?php

namespace App\Services;

use App\Models\Location;
use App\Models\Subscription;
use App\Repositories\Contracts\LocationRepositoryInterface;
use App\Services\Contracts\LocationServiceInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class LocationService implements LocationServiceInterface
{
    public function __construct(
        protected LocationRepositoryInterface $locationRepository,
    ) {}

    public function getAll(int $businessId): Collection
    {
        return $this->locationRepository->all($businessId);
    }

    public function getActive(int $businessId): Collection
    {
        return $this->locationRepository->active($businessId);
    }

    public function getById(int $id): ?Location
    {
        return $this->locationRepository->find($id);
    }

    public function getDefault(int $businessId): ?Location
    {
        return $this->locationRepository->default($businessId);
    }

    public function create(int $businessId, array $data): Location
    {
        $subscription = Subscription::where('business_id', $businessId)
            ->whereIn('status', ['active', 'trial'])
            ->latest()
            ->first();

        $max = $this->maxLocationsFor($subscription);
        if ($max !== null && $this->locationRepository->countForBusiness($businessId) >= $max) {
            throw new RuntimeException("Location limit reached for your plan (max {$max}). Upgrade to add more locations.");
        }

        return DB::transaction(function () use ($businessId, $data) {
            $data['business_id'] = $businessId;
            $data['is_active'] = $data['is_active'] ?? true;

            // First location becomes the default automatically.
            if ($this->locationRepository->countForBusiness($businessId) === 0) {
                $data['is_default'] = true;
            } else {
                $data['is_default'] = $data['is_default'] ?? false;
            }

            $location = $this->locationRepository->create($data);

            if ($location->is_default) {
                $this->clearOtherDefaults($location);
            }

            return $location;
        });
    }

    public function update(int $id, array $data): Location
    {
        $location = $this->locationRepository->find($id);
        if (!$location) {
            throw new RuntimeException('Location not found');
        }

        return DB::transaction(function () use ($location, $data) {
            // Setting a location as default clears the flag on every other location.
            if (!empty($data['is_default'])) {
                $data['is_default'] = true;
                $location = $this->locationRepository->update($location, $data);
                $this->clearOtherDefaults($location);
                return $location;
            }

            return $this->locationRepository->update($location, $data);
        });
    }

    public function delete(int $id): bool
    {
        $location = $this->locationRepository->find($id);
        if (!$location) {
            throw new RuntimeException('Location not found');
        }

        if ($location->is_default) {
            throw new RuntimeException('The default location cannot be deleted. Set another location as default first.');
        }

        return DB::transaction(function () use ($location) {
            $businessId = $location->business_id;

            // Re-point orphaned rows to the default location before deletion.
            $default = $this->locationRepository->default($businessId);

            if ($default) {
                DB::table('users')->where('location_id', $location->id)->update(['location_id' => $default->id]);
                DB::table('sales')->where('location_id', $location->id)->update(['location_id' => $default->id]);
                DB::table('shifts')->where('location_id', $location->id)->update(['location_id' => $default->id]);
                DB::table('stock_movements')->where('location_id', $location->id)->update(['location_id' => $default->id]);
                DB::table('location_product')->where('location_id', $location->id)->update(['location_id' => $default->id]);
            }

            DB::table('location_user')->where('location_id', $location->id)->delete();

            return $this->locationRepository->delete($location);
        });
    }

    public function setDefault(int $id): Location
    {
        $location = $this->locationRepository->find($id);
        if (!$location) {
            throw new RuntimeException('Location not found');
        }

        return DB::transaction(function () use ($location) {
            $updated = $this->locationRepository->update($location, ['is_default' => true, 'is_active' => true]);
            $this->clearOtherDefaults($updated);
            return $updated;
        });
    }

    public function assignUserToLocations(int $userId, array $locationIds): void
    {
        DB::table('location_user')->where('user_id', $userId)->delete();
        $locationIds = array_values(array_unique(array_filter($locationIds)));

        foreach ($locationIds as $locationId) {
            $location = $this->locationRepository->find((int) $locationId);
            if (!$location) {
                continue;
            }

            DB::table('location_user')->insert([
                'business_id' => $location->business_id,
                'location_id' => $location->id,
                'user_id' => $userId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function userLocationIds(int $userId): array
    {
        return DB::table('location_user')
            ->where('user_id', $userId)
            ->pluck('location_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    public function maxLocationsFor(?Subscription $subscription): ?int
    {
        $plan = $subscription?->plan;
        if (!$plan) {
            return 1;
        }

        return $plan->limits['max_locations'] ?? null;
    }

    private function clearOtherDefaults(Location $location): void
    {
        DB::table('locations')
            ->where('business_id', $location->business_id)
            ->where('id', '!=', $location->id)
            ->update(['is_default' => false]);
    }
}
