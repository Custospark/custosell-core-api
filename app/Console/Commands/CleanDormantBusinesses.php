<?php

namespace App\Console\Commands;

use App\Models\Business;
use App\Models\User;
use App\Notifications\DormantAccountWarning;
use App\Services\Platform\PlatformAuditService;
use Carbon\Carbon;
use Illuminate\Console\Command;

class CleanDormantBusinesses extends Command
{
    protected $signature = 'businesses:clean-dormant';

    protected $description = 'Soft-delete businesses inactive for 120+ days after a 7-day warning notification';

    public function handle(PlatformAuditService $audit): int
    {
        $cutoff = Carbon::now()->subDays(120);
        $sevenDaysAgo = Carbon::now()->subDays(7);

        $dormantBusinesses = Business::query()
            ->whereNull('deleted_at')
            ->where(function ($q) use ($cutoff) {
                $q->where('last_activity_at', '<', $cutoff)
                  ->orWhereNull('last_activity_at');
            })
            ->where('created_at', '<', $cutoff)
            ->where('status', 'active')
            ->get();

        $notified = 0;
        $deleted = 0;

        foreach ($dormantBusinesses as $business) {
            $owner = User::find($business->owner_id);
            if (!$owner || !$owner->email) {
                continue;
            }

            if ($business->dormant_notified_at === null) {
                if ($business->created_at->gt($sevenDaysAgo)) {
                    continue;
                }

                $owner->notify(new DormantAccountWarning($business));
                $business->forceFill(['dormant_notified_at' => now()])->save();
                $notified++;

                $this->info("Notified owner of business {$business->id} ({$business->name}) about impending deletion.");
            } elseif ($business->dormant_notified_at->lt($sevenDaysAgo)) {
                $businessName = $business->name;
                $businessSlug = $business->slug;

                $audit->log(null, 'business.dormant_deleted', 'business', $business->id, 'Auto-deleted after 120+ days of inactivity with 7-day warning sent', [
                    'business_name' => $businessName,
                    'business_slug' => $businessSlug,
                    'dormant_notified_at' => $business->dormant_notified_at->toISOString(),
                ]);

                $business->delete();
                $deleted++;

                $this->info("Deleted dormant business {$business->id} ({$businessName}).");
            }
        }

        $this->info("Processed {$dormantBusinesses->count()} dormant businesses: {$notified} warned, {$deleted} deleted.");

        return self::SUCCESS;
    }
}
