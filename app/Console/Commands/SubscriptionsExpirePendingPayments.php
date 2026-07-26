<?php

namespace App\Console\Commands;

use App\Enums\Billing\PaymentStatus;
use App\Models\BillingPayment;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SubscriptionsExpirePendingPayments extends Command
{
    protected $signature = 'subscriptions:expire-pending-payments
                            {--hours=24 : Number of hours after which a pending payment expires}';

    protected $description = 'Expire pending payments that have been stuck for too long';

    public function handle(): int
    {
        $hours = (int) $this->option('hours');
        $cutoff = now()->subHours($hours);

        $expired = BillingPayment::where('status', PaymentStatus::PENDING->value)
            ->where('created_at', '<', $cutoff)
            ->get();

        $count = 0;

        foreach ($expired as $payment) {
            try {
                $payment->update([
                    'status' => PaymentStatus::FAILED->value,
                    'rejection_reason' => "Payment expired after {$hours} hours without completion.",
                ]);
                $count++;
            } catch (\Exception $e) {
                Log::warning('[SubscriptionsExpirePendingPayments] Failed to expire payment', [
                    'payment_id' => $payment->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->info("Expired {$count} pending payment(s).");

        return Command::SUCCESS;
    }
}
