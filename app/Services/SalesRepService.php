<?php

namespace App\Services;

use App\Enums\Billing\DiscountType;
use App\Enums\Billing\ReferralCodeOwnerType;
use App\Enums\Billing\ReferralStatus;
use App\Models\Payout;
use App\Models\Referral;
use App\Models\SalesRep;
use App\Models\User;
use App\Repositories\Contracts\SalesRepRepositoryInterface;
use App\Services\Contracts\ReferralCodeServiceInterface;
use App\Services\Contracts\SalesRepServiceInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Validator;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;

class SalesRepService implements SalesRepServiceInterface
{
    public function __construct(
        protected SalesRepRepositoryInterface $salesRepRepository,
        protected ReferralCodeServiceInterface $referralCodeService,
    ) {}

    public function getAll(): Collection
    {
        return $this->salesRepRepository->all();
    }

    public function getById(int $id): ?SalesRep
    {
        return $this->salesRepRepository->find($id);
    }

    public function getByUser(int $userId): ?SalesRep
    {
        return $this->salesRepRepository->findByUser($userId);
    }

    public function create(array $data): SalesRep
    {
        return DB::transaction(function () use ($data) {
            $user = User::where('email', $data['email'])->first();

            if (!$user) {
                $user = User::create([
                    'name' => $data['name'] ?? explode('@', $data['email'])[0],
                    'email' => $data['email'],
                    'password' => bcrypt(Str::random(32)),
                    'is_active' => true,
                ]);
            }

            $existing = $this->salesRepRepository->findByUser($user->id);
            if ($existing) {
                throw new \RuntimeException('This user is already a sales representative');
            }

            $referralCode = $this->referralCodeService->create([
                'owner_type' => ReferralCodeOwnerType::SALES_REP,
                'owner_user_id' => $user->id,
                'discount_type' => DiscountType::PERCENTAGE,
                'discount_value' => $data['commission_rate'] ?? 0,
                'is_active' => true,
            ]);

            $data['user_id'] = $user->id;
            $data['referral_code_id'] = $referralCode->id;
            unset($data['email'], $data['name']);

            return $this->salesRepRepository->create($data);
        });
    }

    public function update(int $id, array $data): SalesRep
    {
        $salesRep = $this->salesRepRepository->find($id);
        if (!$salesRep) {
            throw new \RuntimeException('SalesRep not found');
        }
        return $this->salesRepRepository->update($salesRep, $data);
    }

    public function delete(int $id): bool
    {
        return DB::transaction(function () use ($id) {
            $salesRep = $this->salesRepRepository->find($id);
            if (!$salesRep) {
                throw new \RuntimeException('SalesRep not found');
            }

            if ($salesRep->referralCode) {
                $this->referralCodeService->update($salesRep->referralCode->id, [
                    'is_active' => false,
                ]);
            }

            return $this->salesRepRepository->delete($salesRep);
        });
    }

    public function getActive(): Collection
    {
        return $this->salesRepRepository->getActive();
    }

    public function getWithEarnings(): Collection
    {
        $salesReps = $this->salesRepRepository->all();
        $salesReps->load('user', 'referralCode', 'payouts');

        foreach ($salesReps as $rep) {
            $referrals = Referral::where('referral_code_id', $rep->referral_code_id)->get();
            $totalCommission = (float) $referrals->sum('commission_earned');
            $totalPaid = (float) $rep->payouts->sum('amount');
            $rep->total_commission = $totalCommission;
            $rep->pending_commission = round(max(0, $totalCommission - $totalPaid), 2);
            $rep->paid_commission = $totalPaid;
            $rep->total_referrals = $referrals->count();
            $rep->active_referrals = $referrals->where('status', ReferralStatus::ACTIVE)->count();
        }

        return $salesReps;
    }

    public function getEarnings(int $id): array
    {
        $salesRep = $this->salesRepRepository->find($id);
        if (!$salesRep) {
            throw new \RuntimeException('SalesRep not found');
        }

        $salesRep->load('user', 'referralCode', 'payouts');

        $referrals = Referral::where('referral_code_id', $salesRep->referral_code_id)
            ->with('referredBusiness', 'subscription')
            ->orderBy('created_at', 'desc')
            ->get();

        $totalCommission = (float) $referrals->sum('commission_earned');
        $totalPaid = (float) $salesRep->payouts->sum('amount');

        return [
            'sales_rep' => $salesRep,
            'total_commission' => $totalCommission,
            'pending_commission' => round(max(0, $totalCommission - $totalPaid), 2),
            'paid_commission' => $totalPaid,
            'total_referrals' => $referrals->count(),
            'referrals' => $referrals->toArray(),
            'payouts' => $salesRep->payouts->toArray(),
        ];
    }

    public function getPayouts(int $id): Collection
    {
        $salesRep = $this->salesRepRepository->find($id);
        if (!$salesRep) {
            throw new \RuntimeException('SalesRep not found');
        }
        return $salesRep->payouts()->with('paidByUser')->where('status', 'paid')->orderBy('paid_at', 'desc')->get();
    }

    public function recordPayout(int $id, array $data, int $paidByUserId): Payout
    {
        $salesRep = $this->salesRepRepository->find($id);
        if (!$salesRep) {
            throw new \RuntimeException('SalesRep not found');
        }

        $attachments = null;
        if (isset($data['attachments']) && is_array($data['attachments'])) {
            $attachments = [];
            foreach ($data['attachments'] as $file) {
                if ($file instanceof UploadedFile) {
                    $path = $file->store('payout-attachments', 'public');
                    $attachments[] = [
                        'path' => $path,
                        'original_name' => $file->getClientOriginalName(),
                        'mime_type' => $file->getMimeType(),
                        'size' => $file->getSize(),
                    ];
                }
            }
        }

        return $salesRep->payouts()->create([
            'amount' => $data['amount'],
            'currency' => 'USD',
            'status' => 'paid',
            'payment_method' => $data['payment_method'] ?? null,
            'notes' => $data['notes'] ?? null,
            'attachments' => $attachments,
            'scheduled_at' => null,
            'paid_at' => $data['paid_at'] ?? now(),
            'paid_by' => $paidByUserId,
        ]);
    }

    public function import(array $rows): array
    {
        $imported = 0;
        $errors = [];
        $totalRows = count($rows);

        $validRegions = ['Central', 'Eastern', 'Northern', 'Western', 'Kampala'];
        $validCommissionTypes = ['percentage', 'flat'];
        $regionMap = [];
        foreach ($validRegions as $r) {
            $regionMap[mb_strtolower(trim($r))] = $r;
        }

        $rowEntries = [];
        foreach ($rows as $index => $row) {
            $excelRow = $index + 2;

            $mapped = [];
            $mapped['name'] = isset($row['Name']) ? trim((string) $row['Name']) : '';
            $mapped['email'] = isset($row['Email']) ? trim((string) $row['Email']) : '';
            $mapped['phone'] = isset($row['Phone']) ? trim((string) $row['Phone']) : '';
            $mapped['region'] = isset($row['Region']) ? trim((string) $row['Region']) : '';
            $mapped['commission_rate'] = isset($row['Commission Rate']) ? trim((string) $row['Commission Rate']) : '';
            $mapped['commission_type'] = isset($row['Commission Type']) ? trim((string) $row['Commission Type']) : '';

            if (empty(array_filter($mapped, fn ($v) => $v !== ''))) {
                continue;
            }

            $validator = Validator::make($mapped, [
                'email' => ['required', 'email'],
                'name' => ['required', 'string', 'max:255'],
                'phone' => ['nullable', 'string', 'max:50'],
                'region' => ['nullable', 'string', 'max:100'],
                'commission_rate' => ['required', 'numeric', 'min:0'],
                'commission_type' => ['nullable', 'in:percentage,flat'],
            ]);

            if ($validator->fails()) {
                $errors[] = ['row' => $excelRow, 'errors' => $validator->errors()->toArray()];
                continue;
            }

            $data = $validator->validated();

            $normalized = [
                'email' => mb_strtolower(trim($data['email'])),
                'name' => trim($data['name']),
                'phone' => !empty($data['phone']) ? trim($data['phone']) : null,
                'region' => null,
                'commission_rate' => (float) $data['commission_rate'],
                'commission_type' => !empty($data['commission_type']) ? mb_strtolower(trim($data['commission_type'])) : 'percentage',
            ];

            $regionKey = !empty($data['region']) ? mb_strtolower(trim($data['region'])) : null;
            if ($regionKey) {
                $normalized['region'] = $regionMap[$regionKey] ?? ucfirst($regionKey);
            }

            if (!in_array($normalized['commission_type'], $validCommissionTypes, true)) {
                $normalized['commission_type'] = 'percentage';
            }

            $rowEntries[] = ['row' => $excelRow, 'data' => $normalized];
        }

        foreach ($rowEntries as $entry) {
            try {
                $this->create($entry['data']);
                $imported++;
            } catch (\Exception $e) {
                $errors[] = ['row' => $entry['row'], 'errors' => ['email' => [$e->getMessage()]]];
            }
        }

        return [
            'imported' => $imported,
            'errors' => $errors,
            'total_rows' => $totalRows,
        ];
    }

    public function generateTemplate(): string
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Sales Reps');

        $headers = ['Name', 'Email', 'Phone', 'Region', 'Commission Rate', 'Commission Type'];
        $lastCol = chr(65 + count($headers) - 1);

        $bold = [
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '2563EB']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
        ];
        $sheet->getStyle("A1:{$lastCol}1")->applyFromArray($bold);

        foreach ($headers as $i => $header) {
            $col = chr(65 + $i);
            $sheet->setCellValue($col . '1', $header);
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        $example = ['John Doe', 'john@example.com', '+256 700 000 000', 'Central', '10', 'percentage'];
        foreach ($example as $i => $val) {
            $col = chr(65 + $i);
            if ($i === 4) {
                $sheet->setCellValueExplicit($col.'2', (string) $val, DataType::TYPE_STRING);
            } else {
                $sheet->setCellValue($col.'2', $val);
            }
        }

        $sheet->getStyle("A2:{$lastCol}2")->applyFromArray([
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'DCFCE7']],
        ]);
        $sheet->getStyle("A3:{$lastCol}3")->applyFromArray([
            'borders' => ['bottom' => ['borderStyle' => Border::BORDER_MEDIUM, 'color' => ['rgb' => 'E5E7EB']]],
        ]);
        $sheet->freezePane('A2');

        $hint = 'Commission Rate: flat value (e.g. 50000) or percentage number without % sign (e.g. 10). '
            . 'Region: Central | Eastern | Northern | Western | Kampala (case-insensitive). '
            . 'Commission Type: percentage | flat. '
            . 'Delete the green example row before importing.';
        $sheet->setCellValue("A4", $hint);
        $sheet->mergeCells("A4:{$lastCol}4");
        $sheet->getStyle('A4')->getFont()->setItalic(true)->setSize(10)->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('6B7280'));

        // --- Reference Sheet ---

        $refSheet = $spreadsheet->createSheet();
        $refSheet->setTitle('Reference');

        $refHeaders = ['Field', 'Valid Values', 'Notes'];
        $refLastCol = chr(65 + count($refHeaders) - 1);
        $refSheet->getStyle("A1:{$refLastCol}1")->applyFromArray($bold);

        foreach ($refHeaders as $i => $header) {
            $col = chr(65 + $i);
            $refSheet->setCellValue($col . '1', $header);
            $refSheet->getColumnDimension($col)->setAutoSize(true);
        }

        $refData = [
            ['Region', 'Central, Eastern, Northern, Western, Kampala', 'Case-insensitive; will be normalized on import.'],
            ['Commission Type', 'percentage, flat', 'percentage = % of referral value; flat = fixed amount. Defaults to percentage.'],
            ['Commission Rate', 'Up to you', 'For percentage: 0–100 (e.g. 10). For flat: any positive number (e.g. 50000).'],
            ['Name', 'Any text', 'Required.'],
            ['Email', 'Valid email', 'Required. If no user with this email exists, a new login will be auto-created.'],
            ['Phone', 'Any text', 'Optional. Stored as-is.'],
        ];

        foreach ($refData as $rowIdx => $row) {
            $r = $rowIdx + 3;
            foreach ($row as $colIdx => $value) {
                $c = chr(65 + $colIdx);
                $refSheet->setCellValue("{$c}{$r}", $value);
            }
        }

        $refSheet->getStyle('A3')->getFont()->setItalic(true)->setSize(10)->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('6B7280'));
        $refSheet->mergeCells('A3:C3');

        $spreadsheet->setActiveSheetIndex(0);

        $writer = IOFactory::createWriter($spreadsheet, 'Xlsx');
        $filePath = tempnam(sys_get_temp_dir(), 'sales-rep-import-template');
        $writer->save($filePath);
        return $filePath;
    }
}
