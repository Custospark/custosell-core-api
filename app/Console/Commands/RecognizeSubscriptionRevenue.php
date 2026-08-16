<?php

namespace App\Console\Commands;

use App\Services\SubscriptionRevenueRecognitionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class RecognizeSubscriptionRevenue extends Command
{
    protected $signature = 'accounting:recognize-revenue {--as-of= : Optional Y-m-d recognition date (defaults to today)}';

    protected $description = 'Recognize earned deferred subscription revenue into software revenue';

    public function handle(SubscriptionRevenueRecognitionService $service): int
    {
        $asOf = $this->option('as-of')
            ? \Illuminate\Support\Carbon::parse($this->option('as-of'))
            : null;

        $result = $service->recognizeDue($asOf);

        $this->info(sprintf(
            'Revenue recognition: %d/%d payments recognized, total %s',
            $result['recognized'],
            $result['payments'],
            number_format($result['total_amount'], 2),
        ));

        Log::info('[RevenueRecognition] run complete', $result);

        return self::SUCCESS;
    }
}
