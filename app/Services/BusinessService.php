<?php

namespace App\Services;

use App\Enums\Billing\DiscountType;
use App\Enums\Billing\ReferralCodeOwnerType;
use App\Enums\Billing\RewardType;
use App\Events\UserRegistered;
use App\Models\Business;
use App\Models\User;
use App\Repositories\Contracts\BusinessRepositoryInterface;
use App\Repositories\Contracts\ReferralCodeRepositoryInterface;
use App\Repositories\Contracts\UserRepositoryInterface;
use App\Services\Contracts\BusinessServiceInterface;
use App\Services\Contracts\ReferralCodeServiceInterface;
use App\Services\Contracts\ReferralServiceInterface;
use App\Services\Contracts\SubscriptionServiceInterface;
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
        protected SubscriptionServiceInterface $subscriptionService,
        protected ReferralCodeServiceInterface $referralCodeService,
        protected ReferralCodeRepositoryInterface $referralCodeRepository,
        protected ReferralServiceInterface $referralService,
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

    public function register(array $userData, array $businessData, ?string $referralCode = null): Business
    {
        $business = DB::transaction(function () use ($userData, $businessData, $referralCode) {
            // Strip referral_code so it's not passed to the business model
            unset($businessData['referral_code']);

            $userData['password'] = Hash::make($userData['password']);
            $user = $this->userRepository->create($userData);

            // Auto-create a referral code for the new user
            $hasCode = $this->referralCodeRepository->findByOwnerUser($user->id);
            if (!$hasCode) {
                $code = $this->referralCodeService->generateCodeForUser($user->name);
                $this->referralCodeService->create([
                    'code' => $code,
                    'owner_type' => ReferralCodeOwnerType::BUSINESS,
                    'owner_user_id' => $user->id,
                    'discount_type' => DiscountType::PERCENTAGE,
                    'discount_value' => 10,
                    'reward_type' => RewardType::PERCENTAGE,
                    'reward_value' => 15,
                ]);
            }

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
            $user->account_type = 'business';
            $user->modules = [
                ...$this->moduleAccess->fullBusinessModulesForOwner(),
                ModuleAccessService::ESTIMATES_FULL_SLUG,
                ModuleAccessService::HR_FULL_SLUG,
            ];
            $user->save();

            $this->platformAdminService->assignIfEligible($user);

            if (isset($businessData['plan_id'])) {
                $planId = (int) $businessData['plan_id'];
                $billingCycle = $businessData['billing_cycle'] ?? 'monthly';
                $subscription = $this->subscriptionService->subscribe(
                    $business->id,
                    $planId,
                    $billingCycle,
                    null,
                    true
                );

                if ($referralCode) {
                    try {
                        $this->referralService->processReferral(
                            $referralCode,
                            $subscription->id,
                            $business->id
                        );
                    } catch (\RuntimeException) {
                        // Invalid, expired, or duplicate — registration proceeds without referral
                    }
                }
            }

            return $business;
        });

        UserRegistered::dispatch($business->owner, $business);

        return $business;
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
        $business = $this->businessRepository->find($id);
        if (! $business) {
            abort(404, 'Business not found');
        }

        $enabled = (bool) ($data['storefront_enabled'] ?? false);
        $payload = [
            'storefront_enabled' => $enabled,
        ];

        $incomingSlug = array_key_exists('slug', $data) && $data['slug'] !== null
            ? trim((string) $data['slug'])
            : '';

        if ($incomingSlug !== '') {
            $check = $this->checkSlugAvailability($incomingSlug, $id);
            if (! $check['available']) {
                throw ValidationException::withMessages([
                    'slug' => [$check['reason'] ?? 'This shop username is not available.'],
                ]);
            }
            $payload['slug'] = $check['slug'];
        }

        if ($enabled) {
            $finalSlug = $payload['slug'] ?? $business->slug;
            $finalSlug = is_string($finalSlug) ? trim($finalSlug) : '';
            if ($finalSlug === '') {
                throw ValidationException::withMessages([
                    'slug' => ['Choose a shop username before enabling your public shop.'],
                ]);
            }
            $check = $this->checkSlugAvailability($finalSlug, $id);
            if (! $check['available']) {
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
