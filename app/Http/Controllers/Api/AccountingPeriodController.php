<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreAccountingPeriodRequest;
use App\Http\Resources\AccountingPeriodCollection;
use App\Http\Resources\AccountingPeriodResource;
use App\Services\AccountingPeriodService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AccountingPeriodController extends Controller
{
    public function __construct(
        protected AccountingPeriodService $accountingPeriodService,
    ) {}

    public function index(Request $request): AccountingPeriodCollection
    {
        $businessId = $request->user()->business_id;
        return new AccountingPeriodCollection(
            $this->accountingPeriodService->getAll($businessId)
        );
    }

    public function current(Request $request): AccountingPeriodResource
    {
        $businessId = $request->user()->business_id;
        return new AccountingPeriodResource(
            $this->accountingPeriodService->getCurrentPeriod($businessId)
        );
    }

    public function store(StoreAccountingPeriodRequest $request): JsonResponse
    {
        $businessId = $request->user()->business_id;
        $data = array_merge($request->validated(), ['business_id' => $businessId]);
        $period = $this->accountingPeriodService->create($data);
        return response()->json(new AccountingPeriodResource($period), 201);
    }

    public function close(Request $request, int $id): JsonResponse
    {
        $userId = $request->user()->id;
        $period = $this->accountingPeriodService->close($id, $userId);
        return response()->json(new AccountingPeriodResource($period));
    }

    public function reopen(Request $request, int $id): JsonResponse
    {
        $userId = $request->user()->id;
        $period = $this->accountingPeriodService->reopen($id, $userId);
        return response()->json(new AccountingPeriodResource($period));
    }
}
