<?php

namespace App\Repositories\Eloquent;

use App\Models\User;
use App\Repositories\Contracts\UserRepositoryInterface;
use Illuminate\Database\Eloquent\Collection;

class UserRepository implements UserRepositoryInterface
{
    public function all(int $businessId, array $filters = []): Collection
    {
        return User::with(['role', 'location', 'locations'])
            ->where('business_id', $businessId)
            ->when(!empty($filters['location_id']), fn ($q) => $q->where('location_id', (int) $filters['location_id']))
            ->when(!empty($filters['role_id']), fn ($q) => $q->where('role_id', (int) $filters['role_id']))
            ->when(!empty($filters['q']), function ($q) use ($filters) {
                $term = '%' . trim((string) $filters['q']) . '%';
                $q->where(function ($inner) use ($term) {
                    $inner->where('name', 'like', $term)
                        ->orWhere('email', 'like', $term)
                        ->orWhere('phone', 'like', $term);
                });
            })
            ->get();
    }

    public function find(int $id): ?User
    {
        return User::find($id);
    }

    public function findForBusiness(int $id, int $businessId): ?User
    {
        return User::with(['role', 'location', 'locations'])
            ->where('business_id', $businessId)
            ->whereKey($id)
            ->first();
    }

    public function findByEmail(string $email): ?User
    {
        return User::where('email', $email)->first();
    }

    public function create(array $data): User
    {
        return User::create($data);
    }

    public function update(User $user, array $data): User
    {
        $user->update($data);
        return $user->fresh(['role']);
    }

    public function delete(User $user): bool
    {
        return $user->delete();
    }

    public function countByBusiness(int $businessId): int
    {
        return User::where('business_id', $businessId)->count();
    }
}
