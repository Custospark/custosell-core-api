<?php

namespace App\Services;

use App\Enums\Billing\DiscountType;
use App\Enums\Billing\ReferralCodeOwnerType;
use App\Enums\Billing\ReferralStatus;
use App\Models\Referral;
use App\Models\SalesRep;
use App\Models\SalesRepPayout;
use App\Models\User;
use App\Repositories\Contracts\SalesRepRepositoryInterface;
use App\Services\Contracts\ReferralCodeServiceInterface;
use App\Services\Contracts\SalesRepServiceInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
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
        $salesRep = $this->salesRepRepository->find($id);
        if (!$salesRep) {
            throw new \RuntimeException('SalesRep not found');
        }
        return $this->salesRepRepository->delete($salesRep);
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
        return $salesRep->payouts()->with('paidByUser')->orderBy('paid_at', 'desc')->get();
    }

    public function recordPayout(int $id, array $data, int $paidByUserId): SalesRepPayout
    {
        $salesRep = $this->salesRepRepository->find($id);
        if (!$salesRep) {
            throw new \RuntimeException('SalesRep not found');
        }

        return SalesRepPayout::create([
            'sales_rep_id' => $id,
            'amount' => $data['amount'],
            'payment_method' => $data['payment_method'] ?? null,
            'notes' => $data['notes'] ?? null,
            'paid_at' => $data['paid_at'] ?? now(),
            'paid_by' => $paidByUserId,
        ]);
    }

    public function import(array $rows): array
    {
        $imported = 0;
        $errors = [];
        $totalRows = count($rows);

        $batch = [];
        foreach ($rows as $index => $row) {
            $rowNum = $index + 2;
            $rowErrors = [];

            $email = trim($row['Email'] ?? '');
            $name = trim($row['Name'] ?? '');
            $phone = trim($row['Phone'] ?? '');
            $region = trim($row['Region'] ?? '');
            $commissionRate = trim($row['Commission Rate'] ?? '');
            $commissionType = trim($row['Commission Type'] ?? 'percentage');

            if (empty($email)) {
                $errors[] = ['row' => $rowNum, 'errors' => ['Email is required']];
                continue;
            }

            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $errors[] = ['row' => $rowNum, 'errors' => ['Invalid email format']];
                continue;
            }

            if (empty($name)) {
                $errors[] = ['row' => $rowNum, 'errors' => ['Name is required']];
                continue;
            }

            if (!is_numeric($commissionRate) || (float) $commissionRate < 0) {
                $errors[] = ['row' => $rowNum, 'errors' => ['Commission Rate must be a positive number']];
                continue;
            }

            $batch[] = [
                'email' => $email,
                'name' => $name,
                'phone' => $phone ?: null,
                'region' => $region ?: null,
                'commission_rate' => (float) $commissionRate,
                'commission_type' => in_array($commissionType, ['percentage', 'flat']) ? $commissionType : 'percentage',
            ];
        }

        foreach ($batch as $row) {
            try {
                $this->create($row);
                $imported++;
            } catch (\Exception $e) {
                $errors[] = ['row' => 0, 'errors' => [$e->getMessage()]];
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

        $headers = ['Name*', 'Email*', 'Phone', 'Region', 'Commission Rate*', 'Commission Type'];
        $col = 'A';
        foreach ($headers as $header) {
            $sheet->setCellValue($col . '1', $header);
            $col++;
        }

        $headerStyle = $sheet->getStyle('A1:' . chr(ord('A') + count($headers) - 1) . '1');
        $headerStyle->getFont()->setBold(true)->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('FFFFFFFF'));
        $headerStyle->getFill()->setFillType(Fill::FILL_SOLID)->setStartColor(new \PhpOffice\PhpSpreadsheet\Style\Color('FF2563EB'));
        $headerStyle->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

        foreach (range('A', chr(ord('A') + count($headers) - 1)) as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        $sheet->setCellValue('A2', 'John Doe');
        $sheet->setCellValue('B2', 'john@example.com');
        $sheet->setCellValue('C2', '+256 700 000 000');
        $sheet->setCellValue('D2', 'Central');
        $sheet->setCellValue('E2', '10');
        $sheet->setCellValue('F2', 'percentage');

        $exampleStyle = $sheet->getStyle('A2:F2');
        $exampleStyle->getFill()->setFillType(Fill::FILL_SOLID)->setStartColor(new \PhpOffice\PhpSpreadsheet\Style\Color('FFDCFCE7'));

        $sheet->freezePane('A2');

        $writer = IOFactory::createWriter($spreadsheet, 'Xlsx');
        $filePath = tempnam(sys_get_temp_dir(), 'sales-rep-import-template');
        $writer->save($filePath);
        return $filePath;
    }
}
