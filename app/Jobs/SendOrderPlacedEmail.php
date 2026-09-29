<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Mail\StandardEmail;
use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Owner alert for a freshly placed storefront order. Queued so buyer
 * checkout never waits on SMTP; safe to retry (re-reads the order).
 */
class SendOrderPlacedEmail implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [60, 300, 900];

    public function __construct(
        public readonly int $orderId,
    ) {}

    public function handle(): void
    {
        $order = Order::query()->with(['business.owner', 'items'])->find($this->orderId);
        if (! $order || ! $order->business) {
            return;
        }

        $owner = $order->business->owner;
        if (! $owner || ! $owner->email) {
            Log::info('Order placed email skipped - owner has no email', [
                'order_id' => $order->id,
                'business_id' => $order->business_id,
            ]);

            return;
        }

        $base = rtrim((string) env('FRONTEND_URL', config('app.url')), '/');
        $rows = $order->items->map(fn ($item) => sprintf(
            '<li>%s &times; %d - %s %s</li>',
            e((string) ($item->product_name ?? 'Item')),
            (int) ($item->quantity ?? 1),
            e((string) ($order->business->currency ?? '')),
            number_format((float) ($item->subtotal ?? 0), 2),
        ))->implode('');

        Mail::to($owner->email)->send(new StandardEmail(
            title: "New order {$order->order_number} - {$order->business->name}",
            mailBody: "<p>Hi ".e((string) ($owner->name ?? 'there')).", a new order just landed in <strong>".e((string) $order->business->name)."</strong>:</p>"
                ."<ul>{$rows}</ul>"
                .'<p><strong>Total: '.e((string) ($order->business->currency ?? '')).' '.number_format((float) ($order->total_amount ?? 0), 2).'</strong><br>'
                .'Customer: '.e((string) ($order->customer_name ?? '-')).' ('.e((string) ($order->customer_phone ?? '-')).')</p>',
            ctaUrl: $base.'/sales/orders',
            ctaLabel: 'View orders',
        ));
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('SendOrderPlacedEmail exhausted retries', [
            'order_id' => $this->orderId,
            'error' => $exception?->getMessage(),
        ]);
    }
}
