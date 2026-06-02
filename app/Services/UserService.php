<?php

namespace App\Services;

use App\Models\User;
use App\Repositories\Contracts\UserRepositoryInterface;
use App\Services\Contracts\UserServiceInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

class UserService implements UserServiceInterface
{
    public function __construct(
        protected UserRepositoryInterface $userRepository,
    ) {}

    public function getAll(int $businessId): Collection
    {
        return $this->userRepository->all($businessId);
    }

    public function getById(int $id): ?User
    {
        return $this->userRepository->find($id);
    }

    public function register(array $data): User
    {
        $data['password'] = Hash::make($data['password']);
        return $this->userRepository->create($data);
    }

    public function login(string $email, string $password): ?User
    {
        $user = $this->userRepository->findByEmail($email);
        if (!$user || !Hash::check($password, $user->password)) {
            return null;
        }
        return $user;
    }

    public function createStaff(int $businessId, array $data): User
    {
        $data['business_id'] = $businessId;
        $data['password'] = Hash::make($data['password']);
        $data['created_by'] = Auth::id();
        return $this->userRepository->create($data);
    }

    public function update(int $id, array $data): User
    {
        $user = $this->userRepository->find($id);
        if (!$user) {
            throw new \RuntimeException('User not found');
        }
        if (isset($data['password'])) {
            $data['password'] = Hash::make($data['password']);
        }
        return $this->userRepository->update($user, $data);
    }

    public function delete(int $id): bool
    {
        $user = $this->userRepository->find($id);
        if (!$user) {
            throw new \RuntimeException('User not found');
        }
        return $this->userRepository->delete($user);
    }

    public function countByBusiness(int $businessId): int
    {
        return $this->userRepository->countByBusiness($businessId);
    }
}
