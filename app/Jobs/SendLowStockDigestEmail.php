<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Mail\StandardEmail;
use App\Models\Business;
use App\Models\Product;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Daily low-stock digest for one business. Skips quietly when everything
 * is stocked or the owner has no email - no noise, only signal.
 */
class SendLowStockDigestEmail implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [300, 900, 3600];

    public function __construct(
        public readonly int $businessId,
    ) {}

    public function handle(): void
    {
        $business = Business::query()->with('owner')->find($this->businessId);
        if (! $business) {
            return;
        }

        // Once-daily guard: retries or manual re-runs never double-send.
        $marker = 'lowstock-digest:'.$business->id.':'.now()->toDateString();
        if (Cache::get($marker)) {
            return;
        }

        $lowStock = Product::query()
            ->where('business_id', $business->id)
            ->where('is_active', true)
            ->whereColumn('stock_quantity', '<=', 'low_stock_threshold')
            ->orderBy('stock_quantity')
            ->limit(20)
            ->get(['name', 'stock_quantity', 'low_stock_threshold']);

        if ($lowStock->isEmpty()) {
            return;
        }

        $owner = $business->owner;
        if (! $owner || ! $owner->email) {
            Log::info('Low-stock digest skipped - owner has no email', [
                'business_id' => $business->id,
            ]);

            return;
        }

        $base = rtrim((string) env('FRONTEND_URL', config('app.url')), '/');
        $rows = $lowStock->map(fn ($product) => sprintf(
            '<li><strong>%s</strong> - %s left (reorder at %s)</li>',
            e((string) $product->name),
            number_format((float) ($product->stock_quantity ?? 0), 0),
            number_format((float) ($product->low_stock_threshold ?? 0), 0),
        ))->implode('');

        $count = $lowStock->count();
        Mail::to($owner->email)->send(new StandardEmail(
            title: "Low stock alert - {$count} item".($count === 1 ? '' : 's')." need restocking at {$business->name}",
            mailBody: "<p>Hi ".e((string) ($owner->name ?? 'there')).", these items at <strong>".e((string) $business->name)."</strong> are at or below their reorder level:</p>"
                ."<ul>{$rows}</ul>",
            ctaUrl: $base.'/inventory/overview',
            ctaLabel: 'Review inventory',
        ));

        Cache::put($marker, true, now()->endOfDay());
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('SendLowStockDigestEmail exhausted retries', [
            'business_id' => $this->businessId,
            'error' => $exception?->getMessage(),
        ]);
    }
}
