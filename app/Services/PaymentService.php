<?php

namespace App\Services;

use App\Events\PaymentRecordedForAccounting;
use App\Models\Business;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Sale;
use Illuminate\Support\Facades\DB;

class PaymentService
{
    public function recordForInvoice(
        Invoice $invoice,
        float $amount,
        string $paymentMethod,
        int $userId,
        ?string $notes = null,
        ?float $amountTendered = null,
        ?float $changeGiven = null,
        ?string $attachmentPath = null,
    ): Payment {
        if (!in_array($invoice->status, ['sent', 'partially_paid'], true)) {
            throw new \RuntimeException('Payments can only be recorded on sent or partially paid invoices');
        }

        return DB::transaction(function () use ($invoice, $amount, $paymentMethod, $userId, $notes, $amountTendered, $changeGiven, $attachmentPath) {
            $total = $this->netBillAmount($invoice);
            $currentPaid = (float) $invoice->amount_paid;
            $newAmountPaid = $currentPaid + $amount;

            if ($newAmountPaid > $total + 0.001) {
                throw new \RuntimeException('Payment amount exceeds invoice balance');
            }

            $balanceAfter = max(0, $total - $newAmountPaid);
            $status = $balanceAfter < 0.01 ? 'paid' : 'partially_paid';

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
            );

            $invoice->update([
                'amount_paid' => $newAmountPaid,
                'status' => $status,
            ]);

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
    ): Payment {
        if (in_array($sale->payment_status, ['refunded', 'partially_refunded'], true)) {
            throw new \RuntimeException('Cannot record payment on a refunded sale');
        }

        if ($sale->payment_status === 'paid') {
            throw new \RuntimeException('This sale is already fully paid');
        }

        return DB::transaction(function () use ($sale, $amount, $paymentMethod, $userId, $notes, $amountTendered, $changeGiven, $attachmentPath) {
            $total = $this->netBillAmount($sale);
            $currentPaid = (float) $sale->amount_paid;
            $newAmountPaid = $currentPaid + $amount;

            if ($newAmountPaid > $total + 0.001) {
                throw new \RuntimeException('Payment amount exceeds sale balance');
            }

            $balanceAfter = max(0, $total - $newAmountPaid);
            $paymentStatus = $balanceAfter < 0.01 ? 'paid' : 'partially_paid';

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
            );

            $sale->update([
                'amount_paid' => $newAmountPaid,
                'payment_status' => $paymentStatus,
            ]);

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

        $payment = $this->createPayment(
            businessId: $sale->business_id,
            payable: $sale,
            amount: $amount,
            paymentMethod: $paymentMethod,
            balanceAfter: $balanceAfter,
            userId: $userId,
            amountTendered: $amountTendered ?? $amount,
            changeGiven: $changeGiven,
        );

        event(new PaymentRecordedForAccounting($payment));

        return $payment;
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
    ): Payment {
        $business = Business::findOrFail($businessId);

        return Payment::create([
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
            'paid_at' => now(),
            'notes' => $notes,
            'attachment_path' => $attachmentPath,
        ]);
    }

    protected function generateReceiptNumber(Business $business): string
    {
        return DocumentNumberGenerator::paymentReceiptNumber($business, Payment::class, 'receipt_number');
    }
}
