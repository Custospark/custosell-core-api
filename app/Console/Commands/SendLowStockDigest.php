<?php

namespace App\Console\Commands;

use App\Jobs\SendLowStockDigestEmail;
use App\Models\Business;
use Illuminate\Console\Command;

class SendLowStockDigest extends Command
{
    protected $signature = 'inventory:notify-low-stock';

    protected $description = 'Queue a low-stock digest email per active business (owners only, stocked businesses skipped)';

    public function handle(): int
    {
        $queued = 0;

        Business::query()
            ->where('status', 'active')
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->chunkById(100, function ($businesses) use (&$queued) {
                foreach ($businesses as $business) {
                    SendLowStockDigestEmail::dispatch($business->id);
                    $queued++;
                }
            });

        $this->info("Queued low-stock digests for {$queued} businesses.");

        return self::SUCCESS;
    }
}
