<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ExpenseRequest;
use App\Http\Resources\ExpenseCollection;
use App\Http\Resources\ExpenseResource;
use App\Services\Contracts\ExpenseServiceInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class ExpenseController extends Controller
{
    public function __construct(
        protected ExpenseServiceInterface $expenseService,
    ) {}

    public function index(Request $request): ExpenseCollection
    {
        $businessId = $request->user()->business_id;
        $filters = $request->only(['category_id', 'date_from', 'date_to', 'shift_id']);
        return new ExpenseCollection(
            $this->expenseService->getAll($businessId, $filters)
        );
    }

    public function show(int $id): ExpenseResource
    {
        $expense = $this->expenseService->getById($id);
        if (!$expense) {
            abort(404, 'Expense not found');
        }
        return new ExpenseResource($expense);
    }

    public function byShift(Request $request, int $shiftId): ExpenseCollection
    {
        return new ExpenseCollection(
            $this->expenseService->getByShift($request->user()->business_id, $shiftId)
        );
    }

    public function store(ExpenseRequest $request): JsonResponse
    {
        $businessId = $request->user()->business_id;
        $data = $request->validated();
        $data['recorded_by'] = $request->user()->id;

        if ($request->hasFile('receipt')) {
            $data['receipt_path'] = $request->file('receipt')->store('expenses/receipts', 'public');
        }

        $expense = $this->expenseService->create($businessId, $data);
        return response()->json(new ExpenseResource($expense), 201);
    }

    public function update(ExpenseRequest $request, int $id): ExpenseResource
    {
        $data = $request->validated();

        if ($request->hasFile('receipt')) {
            $data['receipt_path'] = $request->file('receipt')->store('expenses/receipts', 'public');
        }

        $expense = $this->expenseService->update($id, $data);
        return new ExpenseResource($expense);
    }

    public function destroy(int $id): JsonResponse
    {
        $this->expenseService->delete($id);
        return response()->json(null, 204);
    }

    public function summary(Request $request): JsonResponse
    {
        $businessId = $request->user()->business_id;
        $filters = $request->only(['date_from', 'date_to', 'category_id', 'shift_id']);
        $summary = $this->expenseService->getSummary($businessId, $filters);
        return response()->json($summary);
    }

    public function export(Request $request)
    {
        $businessId = $request->user()->business_id;
        $filters = $request->only(['category_id', 'date_from', 'date_to', 'shift_id']);
        $expenses = $this->expenseService->getAll($businessId, $filters);
        $format = $request->query('format', 'csv');

        $headers = ['Date', 'Category', 'Description', 'Amount', 'Reference', 'Receipt', 'Recurring'];

        if ($format === 'xlsx') {
            $spreadsheet = new Spreadsheet;
            $sheet = $spreadsheet->getActiveSheet();
            $sheet->setTitle('Expenses');

            foreach ($headers as $i => $h) {
                $sheet->setCellValue(chr(65 + $i) . '1', $h);
                $sheet->getColumnDimension(chr(65 + $i))->setAutoSize(true);
            }

            foreach ($expenses as $r => $e) {
                $row = $r + 2;
                $vals = [
                    $e->expense_date, $e->expenseCategory?->name ?? '', $e->description,
                    $e->amount, $e->reference ?? '', $e->receipt_path ? 'Yes' : 'No',
                    $e->is_recurring ? ($e->recurrence_interval ?? 'Yes') : 'No',
                ];
                foreach ($vals as $i => $v) {
                    $sheet->setCellValue(chr(65 + $i) . $row, $v);
                }
            }

            $writer = new Xlsx($spreadsheet);
            $temp = tempnam(sys_get_temp_dir(), 'export');
            $writer->save($temp);

            return response()->download($temp, 'expenses-export.xlsx', [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ])->deleteFileAfterSend(true);
        }

        $rows = [$headers];
        foreach ($expenses as $e) {
            $rows[] = [
                $e->expense_date, $e->expenseCategory?->name ?? '', $e->description,
                $e->amount, $e->reference ?? '', $e->receipt_path ? 'Yes' : 'No',
                $e->is_recurring ? ($e->recurrence_interval ?? 'Yes') : 'No',
            ];
        }

        $csv = implode("\n", array_map(fn($r) => implode(',', array_map(fn($v) => '"' . str_replace('"', '""', $v) . '"', $r)), $rows));
        return response($csv, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="expenses-export.csv"',
        ]);
    }
}
