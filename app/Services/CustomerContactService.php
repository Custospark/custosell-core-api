<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Customer;
use App\Services\Contracts\CustomerServiceInterface;
use Illuminate\Support\Str;

class CustomerContactService
{
    public function __construct(
        protected CustomerServiceInterface $customerService,
    ) {}

    /**
     * Find or create a customer without losing contact details.
     * Merges new email/name/phone onto an existing match when provided.
     *
     * @param  array{customer_id?: int|null, name?: string|null, email?: string|null, phone?: string|null}  $input
     */
    public function resolve(int $businessId, array $input): Customer
    {
        $customerId = isset($input['customer_id']) ? (int) $input['customer_id'] : null;
        $name = $this->normalize($input['name'] ?? null);
        $email = $this->normalizeEmail($input['email'] ?? null);
        $phone = $this->normalize($input['phone'] ?? null);

        if ($customerId) {
            $existing = $this->customerService->getById($customerId);
            if ($existing && (int) $existing->business_id === $businessId) {
                return $this->mergeContact($existing, $name, $email, $phone);
            }
        }

        if ($email) {
            $byEmail = Customer::query()
                ->where('business_id', $businessId)
                ->whereRaw('LOWER(email) = ?', [strtolower($email)])
                ->first();
            if ($byEmail) {
                return $this->mergeContact($byEmail, $name, $email, $phone);
            }
        }

        if ($phone) {
            $byPhone = Customer::query()
                ->where('business_id', $businessId)
                ->where('phone', $phone)
                ->first();
            if ($byPhone) {
                return $this->mergeContact($byPhone, $name, $email, $phone);
            }
        }

        $resolvedName = $name ?: ($email ? $this->nameFromEmail($email) : 'Customer');
        $resolvedPhone = $phone ?: $this->phoneFromEmail($email);

        return $this->customerService->create($businessId, [
            'name' => $resolvedName,
            'phone' => $resolvedPhone,
            'email' => $email,
        ]);
    }

    private function mergeContact(Customer $customer, ?string $name, ?string $email, ?string $phone): Customer
    {
        $updates = [];

        if ($name && $name !== $customer->name) {
            $updates['name'] = $name;
        }

        if ($email && strcasecmp((string) ($customer->email ?? ''), $email) !== 0) {
            $updates['email'] = $email;
        }

        if ($phone && $phone !== $customer->phone) {
            $currentIsSynthetic = str_starts_with((string) $customer->phone, 'em-')
                || str_starts_with((string) $customer->phone, 'walkin-');
            if ($currentIsSynthetic || $phone !== $customer->phone) {
                $updates['phone'] = $phone;
            }
        }

        if ($updates === []) {
            return $customer;
        }

        return $this->customerService->update($customer->id, $updates);
    }

    private function normalize(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $trimmed = trim($value);

        return $trimmed !== '' ? $trimmed : null;
    }

    private function normalizeEmail(?string $value): ?string
    {
        $normalized = $this->normalize($value);
        if ($normalized === null) {
            return null;
        }

        return filter_var($normalized, FILTER_VALIDATE_EMAIL) ? strtolower($normalized) : null;
    }

    private function nameFromEmail(string $email): string
    {
        $local = Str::before($email, '@');
        $local = str_replace(['.', '_', '-'], ' ', $local);

        return Str::title(trim($local)) ?: 'Customer';
    }

    private function phoneFromEmail(?string $email): string
    {
        if ($email) {
            return 'em-' . substr(hash('sha256', strtolower($email)), 0, 20);
        }

        return 'walkin-' . substr((string) Str::uuid(), 0, 12);
    }
}
