<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SalesRepPayoutRequest;
use App\Http\Requests\SalesRepRequest;
use App\Http\Resources\SalesRepCollection;
use App\Http\Resources\SalesRepResource;
use App\Services\Contracts\SalesRepServiceInterface;
use App\Services\Contracts\ReferralServiceInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use PhpOffice\PhpSpreadsheet\IOFactory;
use RuntimeException;

class SalesRepController extends Controller
{
    public function __construct(
        protected SalesRepServiceInterface $salesRepService,
        protected ReferralServiceInterface $referralService,
    ) {}

    public function index(): SalesRepCollection
    {
        return new SalesRepCollection($this->salesRepService->getAll());
    }

    public function show(int $id): SalesRepResource
    {
        $salesRep = $this->salesRepService->getById($id);
        if (!$salesRep) {
            abort(404, 'Sales rep not found');
        }
        return new SalesRepResource($salesRep);
    }

    public function store(SalesRepRequest $request): JsonResponse
    {
        $salesRep = $this->salesRepService->create($request->validated());
        return response()->json(new SalesRepResource($salesRep), 201);
    }

    public function update(SalesRepRequest $request, int $id): SalesRepResource
    {
        try {
            $salesRep = $this->salesRepService->update($id, $request->validated());
            return new SalesRepResource($salesRep);
        } catch (RuntimeException $e) {
            abort(404, $e->getMessage());
        }
    }

    public function destroy(int $id): JsonResponse
    {
        try {
            $this->salesRepService->delete($id);
            return response()->json(['message' => 'Deleted'], 200);
        } catch (RuntimeException $e) {
            abort(404, $e->getMessage());
        }
    }

    public function earningsIndex(): JsonResponse
    {
        try {
            $salesReps = $this->salesRepService->getWithEarnings();
            return response()->json(['data' => $salesReps]);
        } catch (RuntimeException $e) {
            abort(422, $e->getMessage());
        }
    }

    public function earnings(int $id): JsonResponse
    {
        try {
            $earnings = $this->salesRepService->getEarnings($id);
            return response()->json($earnings);
        } catch (RuntimeException $e) {
            abort(404, $e->getMessage());
        }
    }

    public function myEarnings(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            abort(401, 'Unauthenticated');
        }

        try {
            $salesRep = $this->salesRepService->getByUser($user->id);
            if (!$salesRep) {
                return response()->json([
                    'message' => 'You are not a sales representative',
                    'is_sales_rep' => false,
                ]);
            }

            $earnings = $this->salesRepService->getEarnings($salesRep->id);
            $earnings['is_sales_rep'] = true;
            return response()->json($earnings);
        } catch (RuntimeException $e) {
            abort(422, $e->getMessage());
        }
    }

    public function payouts(int $id): JsonResponse
    {
        try {
            $payouts = $this->salesRepService->getPayouts($id);
            return response()->json(['data' => $payouts]);
        } catch (RuntimeException $e) {
            abort(404, $e->getMessage());
        }
    }

    public function recordPayout(SalesRepPayoutRequest $request, int $id): JsonResponse
    {
        try {
            $payout = $this->salesRepService->recordPayout(
                $id,
                $request->validated(),
                $request->user()->id
            );
            return response()->json($payout, 201);
        } catch (RuntimeException $e) {
            abort(422, $e->getMessage());
        }
    }

    public function downloadTemplate(): JsonResponse
    {
        try {
            $filePath = $this->salesRepService->generateTemplate();
            return response()->download($filePath, 'sales-rep-import-template.xlsx')->deleteFileAfterSend(true);
        } catch (\Exception $e) {
            abort(500, 'Failed to generate template');
        }
    }

    public function import(Request $request): JsonResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'mimes:xlsx,xls,csv', 'max:20480'],
        ]);

        $file = $request->file('file');
        set_time_limit(600);
        ini_set('memory_limit', '512M');

        try {
            $rows = $this->parseSpreadsheet($file->getPathname());
            $result = $this->salesRepService->import($rows);
            return response()->json($result);
        } catch (\Exception $e) {
            abort(422, 'Failed to process file: ' . $e->getMessage());
        }
    }

    protected function parseSpreadsheet(string $path): array
    {
        $reader = IOFactory::createReaderForFile($path);
        $reader->setReadDataOnly(true);
        $spreadsheet = $reader->load($path);
        $sheet = $spreadsheet->getActiveSheet();
        $data = $sheet->toArray();

        if (empty($data) || count($data) < 2) {
            return [];
        }

        $headers = array_map('trim', $data[0]);
        $rows = [];
        for ($i = 1; $i < count($data); $i++) {
            $row = $data[$i];
            if (empty(array_filter($row))) continue;
            $mapped = [];
            foreach ($headers as $colIndex => $header) {
                $mapped[$header] = $row[$colIndex] ?? '';
            }
            $rows[] = $mapped;
        }

        return $rows;
    }
}
