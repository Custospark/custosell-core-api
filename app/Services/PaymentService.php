<?php

namespace App\Services;

use App\Events\PaymentRecordedForAccounting;
use App\Models\Business;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Sale;
use App\Models\Shift;
use Illuminate\Support\Facades\DB;

class PaymentService
{
    public function getByShift(int $businessId, int $shiftId): \Illuminate\Database\Eloquent\Collection
    {
        return Payment::query()
            ->where('business_id', $businessId)
            ->where('shift_id', $shiftId)
            ->orderBy('paid_at')
            ->get();
    }

    public function recordForInvoice(
        Invoice $invoice,
        float $amount,
        string $paymentMethod,
        int $userId,
        ?string $notes = null,
        ?float $amountTendered = null,
        ?float $changeGiven = null,
        ?string $attachmentPath = null,
        ?int $shiftId = null,
    ): Payment {
        if (!in_array($invoice->status, ['sent', 'partially_paid'], true)) {
            throw new \RuntimeException('Payments can only be recorded on sent or partially paid invoices');
        }

        return DB::transaction(function () use ($invoice, $amount, $paymentMethod, $userId, $notes, $amountTendered, $changeGiven, $attachmentPath, $shiftId) {
            $total = $this->netBillAmount($invoice);
            $currentPaid = (float) $invoice->amount_paid;
            $newAmountPaid = $currentPaid + $amount;

            if ($newAmountPaid > $total + 0.001) {
                throw new \RuntimeException('Payment amount exceeds invoice balance');
            }

            $balanceAfter = max(0, $total - $newAmountPaid);
            $status = $balanceAfter < 0.01 ? 'paid' : 'partially_paid';
            $resolvedShiftId = $this->resolvePaymentShiftId($invoice->business_id, $userId, $shiftId);

            $payment = $this->createPayment(
                businessId: $invoice->business_id,
                payable: $invoice,
                amount: $amount,
                paymentMethod: $paymentMethod,
                balanceAfter: $balanceAfter,
                userId: $userId,
                notes: $notes,
                amountTendered: $amountTendered ?? $amount,
                changeGiven: $changeGiven,
                attachmentPath: $attachmentPath,
                shiftId: $resolvedShiftId,
                dispatchAccounting: false,
            );

            $invoice->update([
                'amount_paid' => $newAmountPaid,
                'status' => $status,
            ]);

            if ($invoice->sale_id) {
                $this->syncLinkedSaleAfterInvoicePayment($invoice, $payment);
            }

            event(new PaymentRecordedForAccounting($payment));

            return $payment->load(['recordedBy']);
        });
    }

    public function recordForSale(
        Sale $sale,
        float $amount,
        string $paymentMethod,
        int $userId,
        ?string $notes = null,
        ?float $amountTendered = null,
        ?float $changeGiven = null,
        ?string $attachmentPath = null,
        ?int $shiftId = null,
    ): Payment {
        if (in_array($sale->payment_status, ['refunded', 'partially_refunded'], true)) {
            throw new \RuntimeException('Cannot record payment on a refunded sale');
        }

        if ($sale->payment_status === 'paid') {
            throw new \RuntimeException('This sale is already fully paid');
        }

        return DB::transaction(function () use ($sale, $amount, $paymentMethod, $userId, $notes, $amountTendered, $changeGiven, $attachmentPath, $shiftId) {
            $total = $this->netBillAmount($sale);
            $currentPaid = (float) $sale->amount_paid;
            $newAmountPaid = $currentPaid + $amount;

            if ($newAmountPaid > $total + 0.001) {
                throw new \RuntimeException('Payment amount exceeds sale balance');
            }

            $balanceAfter = max(0, $total - $newAmountPaid);
            $paymentStatus = $balanceAfter < 0.01 ? 'paid' : 'partially_paid';
            $resolvedShiftId = $this->resolvePaymentShiftId($sale->business_id, $userId, $shiftId);

            $payment = $this->createPayment(
                businessId: $sale->business_id,
                payable: $sale,
                amount: $amount,
                paymentMethod: $paymentMethod,
                balanceAfter: $balanceAfter,
                userId: $userId,
                notes: $notes,
                amountTendered: $amountTendered ?? $amount,
                changeGiven: $changeGiven,
                attachmentPath: $attachmentPath,
                shiftId: $resolvedShiftId,
                dispatchAccounting: false,
            );

            $sale->update([
                'amount_paid' => $newAmountPaid,
                'payment_status' => $paymentStatus,
            ]);

            $this->syncLinkedInvoiceAfterSalePayment($sale);

            event(new PaymentRecordedForAccounting($payment));

            return $payment->load(['recordedBy', 'payable']);
        });
    }

    public function createInitialSalePayment(
        Sale $sale,
        float $amount,
        string $paymentMethod,
        int $userId,
        ?float $amountTendered = null,
        ?float $changeGiven = null,
    ): ?Payment {
        if ($amount <= 0) {
            return null;
        }

        $balanceAfter = max(0, $this->netBillAmount($sale) - $amount);
        $shiftId = $sale->shift_id
            ? (int) $sale->shift_id
            : $this->resolvePaymentShiftId($sale->business_id, $userId);

        $payment = $this->createPayment(
            businessId: $sale->business_id,
            payable: $sale,
            amount: $amount,
            paymentMethod: $paymentMethod,
            balanceAfter: $balanceAfter,
            userId: $userId,
            amountTendered: $amountTendered ?? $amount,
            changeGiven: $changeGiven,
            shiftId: $shiftId,
            dispatchAccounting: false,
        );

        event(new PaymentRecordedForAccounting($payment));

        return $payment;
    }

    /**
     * Operational mirror on the linked sale — no duplicate GL event.
     */
    public function mirrorPaymentOnSale(
        Sale $sale,
        float $amount,
        string $paymentMethod,
        float $balanceAfter,
        int $userId,
        ?string $notes = null,
        ?float $amountTendered = null,
        ?float $changeGiven = null,
        ?int $shiftId = null,
    ): Payment {
        return $this->createPayment(
            businessId: $sale->business_id,
            payable: $sale,
            amount: $amount,
            paymentMethod: $paymentMethod,
            balanceAfter: $balanceAfter,
            userId: $userId,
            notes: $notes,
            amountTendered: $amountTendered ?? $amount,
            changeGiven: $changeGiven,
            shiftId: $shiftId,
            dispatchAccounting: false,
        );
    }

    protected function syncLinkedSaleAfterInvoicePayment(Invoice $invoice, Payment $invoicePayment): void
    {
        $sale = Sale::query()->with('saleItems')->find($invoice->sale_id);
        if (!$sale) {
            return;
        }

        if (in_array($sale->payment_status, ['refunded', 'partially_refunded'], true)) {
            throw new \RuntimeException('Cannot apply invoice payment to a refunded sale');
        }

        $saleNet = $this->netBillAmount($sale);
        $newSalePaid = min((float) $sale->amount_paid + (float) $invoicePayment->amount, $saleNet);
        $saleBalance = max(0, $saleNet - $newSalePaid);
        $saleStatus = $saleBalance < 0.01 ? 'paid' : 'partially_paid';

        $this->mirrorPaymentOnSale(
            sale: $sale,
            amount: (float) $invoicePayment->amount,
            paymentMethod: (string) $invoicePayment->payment_method,
            balanceAfter: $saleBalance,
            userId: (int) $invoicePayment->recorded_by,
            notes: $invoicePayment->notes
                ? "From invoice {$invoice->invoice_number}: {$invoicePayment->notes}"
                : "From invoice {$invoice->invoice_number} ({$invoicePayment->receipt_number})",
            amountTendered: $invoicePayment->amount_tendered !== null ? (float) $invoicePayment->amount_tendered : null,
            changeGiven: $invoicePayment->change_given !== null ? (float) $invoicePayment->change_given : null,
            shiftId: $invoicePayment->shift_id ? (int) $invoicePayment->shift_id : null,
        );

        $sale->update([
            'amount_paid' => $newSalePaid,
            'payment_status' => $saleStatus,
        ]);
    }

    protected function syncLinkedInvoiceAfterSalePayment(Sale $sale): void
    {
        $invoice = Invoice::query()
            ->where('sale_id', $sale->id)
            ->whereIn('status', ['sent', 'partially_paid', 'paid'])
            ->orderByDesc('id')
            ->first();

        if (!$invoice) {
            return;
        }

        $invoiceTotal = (float) $invoice->total_amount;
        $syncedPaid = min((float) $sale->amount_paid, $invoiceTotal);
        $balance = max(0, $invoiceTotal - $syncedPaid);
        $status = $balance < 0.01 ? 'paid' : ($syncedPaid > 0 ? 'partially_paid' : $invoice->status);

        $invoice->update([
            'amount_paid' => $syncedPaid,
            'status' => $status,
        ]);
    }

    public function netBillAmount(Invoice|Sale $payable): float
    {
        if ($payable instanceof Sale) {
            $payable->loadMissing('saleItems');
            $refunded = $payable->saleItems->sum(fn ($item) => (float) $item->refunded_amount);

            return max(0, (float) $payable->total_amount - $refunded);
        }

        return (float) $payable->total_amount;
    }

    protected function resolvePaymentShiftId(int $businessId, int $userId, ?int $shiftId = null): ?int
    {
        if ($shiftId) {
            return $shiftId;
        }

        return Shift::query()
            ->where('business_id', $businessId)
            ->where('user_id', $userId)
            ->whereNull('clock_out')
            ->where('status', 'active')
            ->value('id');
    }

    protected function createPayment(
        int $businessId,
        Invoice|Sale $payable,
        float $amount,
        string $paymentMethod,
        float $balanceAfter,
        int $userId,
        ?string $notes = null,
        ?float $amountTendered = null,
        ?float $changeGiven = null,
        ?string $attachmentPath = null,
        ?int $shiftId = null,
        bool $dispatchAccounting = true,
    ): Payment {
        $business = Business::findOrFail($businessId);

        $payment = Payment::create([
            'business_id' => $businessId,
            'payable_type' => $payable instanceof Invoice ? 'invoice' : 'sale',
            'payable_id' => $payable->id,
            'receipt_number' => $this->generateReceiptNumber($business),
            'amount' => $amount,
            'amount_tendered' => $amountTendered ?? $amount,
            'change_given' => $changeGiven,
            'payment_method' => $paymentMethod,
            'balance_after' => $balanceAfter,
            'recorded_by' => $userId,
            'shift_id' => $shiftId,
            'paid_at' => now(),
            'notes' => $notes,
            'attachment_path' => $attachmentPath,
        ]);

        if ($dispatchAccounting) {
            event(new PaymentRecordedForAccounting($payment));
        }

        return $payment;
    }

    protected function generateReceiptNumber(Business $business): string
    {
        return DocumentNumberGenerator::paymentReceiptNumber($business, Payment::class, 'receipt_number');
    }
}
