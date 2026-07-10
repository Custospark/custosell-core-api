<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Business;
use App\Services\Hr\HrStaffMirrorService;
use Illuminate\Console\Command;

class HrBackfillStaffEmployees extends Command
{
    protected $signature = 'hr:backfill-staff-employees {--business= : Limit to a single business id}';

    protected $description = 'Create linked HR employees for staff users that do not have one yet';

    public function handle(HrStaffMirrorService $mirror): int
    {
        $businessId = $this->option('business');

        $query = Business::query()->orderBy('id');
        if ($businessId !== null && $businessId !== '') {
            $query->whereKey((int) $businessId);
        }

        $total = 0;
        $query->each(function (Business $business) use ($mirror, &$total): void {
            $created = $mirror->backfillBusiness((int) $business->id);
            $total += $created;
            if ($created > 0) {
                $this->line("Business #{$business->id}: created {$created} employee(s)");
            }
        });

        $this->info("Backfill complete. Created {$total} HR employee(s).");

        return self::SUCCESS;
    }
}
