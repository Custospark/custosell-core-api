<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CustomerRequest;
use App\Http\Requests\ResolveCustomerContactRequest;
use App\Http\Resources\CustomerCollection;
use App\Http\Resources\CustomerResource;
use App\Http\Resources\SaleCollection;
use App\Services\Contracts\CustomerServiceInterface;
use App\Services\Contracts\SaleServiceInterface;
use App\Services\CustomerContactService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CustomerController extends Controller
{
    public function __construct(
        protected CustomerServiceInterface $customerService,
        protected SaleServiceInterface $saleService,
        protected CustomerContactService $customerContactService,
    ) {}

    public function index(Request $request): CustomerCollection
    {
        $businessId = $request->user()->business_id;
        return new CustomerCollection($this->customerService->getAll($businessId));
    }

    public function show(int $id): CustomerResource
    {
        $customer = $this->customerService->getById($id);
        if (!$customer) {
            abort(404, 'Customer not found');
        }
        return new CustomerResource($customer);
    }

    public function store(CustomerRequest $request): CustomerResource
    {
        $businessId = $request->user()->business_id;
        $customer = $this->customerService->create($businessId, $request->validated());
        return new CustomerResource($customer);
    }

    public function resolve(ResolveCustomerContactRequest $request): JsonResponse
    {
        $businessId = (int) $request->user()->business_id;
        $customer = $this->customerContactService->resolve($businessId, $request->validated());
        $isNew = $customer->wasRecentlyCreated;

        return (new CustomerResource($customer))
            ->response()
            ->setStatusCode($isNew ? 201 : 200);
    }

    public function update(CustomerRequest $request, int $id): CustomerResource
    {
        $customer = $this->customerService->update($id, $request->validated());
        return new CustomerResource($customer);
    }

    public function destroy(int $id): JsonResponse
    {
        $this->customerService->delete($id);
        return response()->json(null, 204);
    }

    public function purchases(int $id, Request $request): SaleCollection
    {
        $businessId = $request->user()->business_id;
        return new SaleCollection($this->saleService->getByCustomer($businessId, $id));
    }
}
