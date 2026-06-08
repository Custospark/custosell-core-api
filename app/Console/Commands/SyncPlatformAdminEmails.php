<?php

namespace App\Console\Commands;

use App\Services\Platform\PlatformAdminService;
use Illuminate\Console\Command;

class SyncPlatformAdminEmails extends Command
{
    protected $signature = 'platform:sync-admin-emails';

    protected $description = 'Assign platform-admin role to users whose emails are listed in PLATFORM_ADMIN_EMAILS';

    public function handle(PlatformAdminService $platformAdminService): int
    {
        $emails = config('platform.admin_emails', []);

        if ($emails === []) {
            $this->warn('PLATFORM_ADMIN_EMAILS is empty. Set it in .env first.');

            return self::FAILURE;
        }

        $count = $platformAdminService->syncConfiguredAdminEmails();

        $this->info("Synced platform-admin role for {$count} user(s).");
        $this->line('Configured emails: '.implode(', ', $emails));

        return self::SUCCESS;
    }
}
