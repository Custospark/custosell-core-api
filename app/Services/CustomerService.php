<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Sale;
use App\Repositories\Contracts\CustomerRepositoryInterface;
use App\Services\Contracts\CustomerServiceInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class CustomerService implements CustomerServiceInterface
{
    public function __construct(
        protected CustomerRepositoryInterface $customerRepository,
    ) {}

    public function getAll(int $businessId): Collection
    {
        return $this->customerRepository->all($businessId);
    }

    public function getOverview(int $businessId): array
    {
        $totalCustomers = Customer::where('business_id', $businessId)->count();

        $activeCustomers = Sale::where('business_id', $businessId)
            ->whereNotNull('customer_id')
            ->distinct('customer_id')
            ->count('customer_id');

        $repeatCustomers = Sale::where('business_id', $businessId)
            ->whereNotNull('customer_id')
            ->select('customer_id')
            ->selectRaw('COUNT(*) as purchases')
            ->groupBy('customer_id')
            ->havingRaw('COUNT(*) >= 2')
            ->get()
            ->count();

        $totalRevenue = (float) Customer::where('business_id', $businessId)
            ->sum('total_purchases');

        $repeatRate = $activeCustomers > 0
            ? round(($repeatCustomers / $activeCustomers) * 100, 1)
            : 0;

        $averageValue = $totalCustomers > 0
            ? round($totalRevenue / $totalCustomers, 2)
            : 0;

        $segments = $this->buildSegments($businessId);
        $frequency = $this->buildFrequency($businessId);
        $trends = $this->buildTrends($businessId);
        $topCustomers = $this->buildTopCustomers($businessId);

        return [
            'total_customers' => $totalCustomers,
            'active_customers' => $activeCustomers,
            'repeat_customers' => $repeatCustomers,
            'repeat_rate' => $repeatRate,
            'total_revenue' => $totalRevenue,
            'average_value' => $averageValue,
            'segments' => $segments,
            'frequency' => $frequency,
            'new_customers_by_month' => $trends['new_customers'],
            'revenue_by_month' => $trends['revenue'],
            'top_customers' => $topCustomers,
        ];
    }

    public function getById(int $id): ?Customer
    {
        return $this->customerRepository->find($id);
    }

    public function create(int $businessId, array $data): Customer
    {
        $data['business_id'] = $businessId;
        return $this->customerRepository->create($data);
    }

    public function update(int $id, array $data): Customer
    {
        $customer = $this->customerRepository->find($id);
        if (!$customer) {
            throw new \RuntimeException('Customer not found');
        }
        return $this->customerRepository->update($customer, $data);
    }

    public function delete(int $id): bool
    {
        $customer = $this->customerRepository->find($id);
        if (!$customer) {
            throw new \RuntimeException('Customer not found');
        }
        return $this->customerRepository->delete($customer);
    }

    protected function buildSegments(int $businessId): array
    {
        $today = now();
        $customers = Customer::where('business_id', $businessId)
            ->whereNotNull('last_purchase_at')
            ->get(['id', 'last_purchase_at']);

        $active = $atRisk = $lapsed = 0;
        foreach ($customers as $customer) {
            $days = $customer->last_purchase_at->diffInDays($today);
            if ($days < 30) {
                $active++;
            } elseif ($days < 90) {
                $atRisk++;
            } else {
                $lapsed++;
            }
        }

        $never = Customer::where('business_id', $businessId)
            ->whereNull('last_purchase_at')
            ->count();

        return [
            ['key' => 'active', 'label' => 'Active (<30 days)', 'count' => $active],
            ['key' => 'at_risk', 'label' => 'At risk (30-90 days)', 'count' => $atRisk],
            ['key' => 'lapsed', 'label' => 'Lapsed (>90 days)', 'count' => $lapsed],
            ['key' => 'never', 'label' => 'Never purchased', 'count' => $never],
        ];
    }

    protected function buildFrequency(int $businessId): array
    {
        $counts = Sale::where('business_id', $businessId)
            ->whereNotNull('customer_id')
            ->select('customer_id')
            ->selectRaw('COUNT(*) as purchases')
            ->groupBy('customer_id')
            ->get()
            ->pluck('purchases');

        $one = $twoThree = $fourSix = $sevenPlus = 0;
        foreach ($counts as $count) {
            if ($count === 1) {
                $one++;
            } elseif ($count <= 3) {
                $twoThree++;
            } elseif ($count <= 6) {
                $fourSix++;
            } else {
                $sevenPlus++;
            }
        }

        return [
            ['bucket' => '1 purchase', 'count' => $one],
            ['bucket' => '2-3 purchases', 'count' => $twoThree],
            ['bucket' => '4-6 purchases', 'count' => $fourSix],
            ['bucket' => '7+ purchases', 'count' => $sevenPlus],
        ];
    }

    protected function buildTrends(int $businessId): array
    {
        $newCustomers = Customer::where('business_id', $businessId)
            ->selectRaw("DATE_FORMAT(created_at, '%Y-%m') as month, COUNT(*) as total")
            ->groupBy('month')
            ->orderBy('month')
            ->get()
            ->map(fn ($row) => [
                'month' => $row->month,
                'count' => (int) $row->total,
            ])
            ->values()
            ->toArray();

        $revenue = Sale::where('business_id', $businessId)
            ->whereNotNull('customer_id')
            ->selectRaw("DATE_FORMAT(sale_date, '%Y-%m') as month, SUM(total_amount) as total")
            ->groupBy('month')
            ->orderBy('month')
            ->get()
            ->map(fn ($row) => [
                'month' => $row->month,
                'revenue' => (float) $row->total,
            ])
            ->values()
            ->toArray();

        return [
            'new_customers' => $newCustomers,
            'revenue' => $revenue,
        ];
    }

    protected function buildTopCustomers(int $businessId): array
    {
        return Customer::where('business_id', $businessId)
            ->orderByDesc('total_purchases')
            ->take(5)
            ->get()
            ->map(function (Customer $customer) {
                $purchaseCount = Sale::where('business_id', $customer->business_id)
                    ->where('customer_id', $customer->id)
                    ->count();

                return [
                    'id' => $customer->id,
                    'name' => $customer->name,
                    'total_purchases' => (float) $customer->total_purchases,
                    'purchase_count' => $purchaseCount,
                    'last_purchase_at' => $customer->last_purchase_at?->toISOString(),
                ];
            })
            ->values()
            ->toArray();
    }
}
