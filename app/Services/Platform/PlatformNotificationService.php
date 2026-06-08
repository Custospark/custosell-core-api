<?php

namespace App\Services\Platform;

use App\Mail\StandardEmail;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class PlatformNotificationService
{
    public function sendToEmail(string $email, string $title, string $body): void
    {
        if ($email === '') {
            return;
        }

        try {
            Mail::to($email)->send(new StandardEmail(
                title: $title,
                mailBody: $body,
                isHtml: true,
            ));
        } catch (\Throwable $e) {
            Log::warning('Platform email failed', [
                'email' => $email,
                'subject' => $title,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function notifyBusinessStatusChange(
        string $businessName,
        ?string $ownerEmail,
        ?string $businessEmail,
        string $status,
        ?string $reason,
    ): void {
        $isSuspended = $status === 'suspended';
        $title = $isSuspended
            ? 'Your Custosell business account has been suspended'
            : 'Your Custosell business account has been reactivated';

        $reasonLine = $reason ? "<p><strong>Reason:</strong> ".e($reason).'</p>' : '';
        $body = $isSuspended
            ? "<p>Hello,</p><p>Your business <strong>".e($businessName)."</strong> has been suspended on Custosell.</p>{$reasonLine}<p>Contact support if you believe this is a mistake.</p>"
            : "<p>Hello,</p><p>Your business <strong>".e($businessName)."</strong> has been reactivated. You can sign in and continue using Custosell.</p>";

        foreach (array_unique(array_filter([$ownerEmail, $businessEmail])) as $email) {
            $this->sendToEmail($email, $title, $body);
        }
    }

    public function notifyUserStatusChange(User $user, bool $isActive, ?string $reason): void
    {
        $title = $isActive
            ? 'Your Custosell account has been reactivated'
            : 'Your Custosell account has been deactivated';

        $reasonLine = $reason ? "<p><strong>Reason:</strong> ".e($reason).'</p>' : '';
        $body = $isActive
            ? '<p>Hello '.e($user->name).',</p><p>Your Custosell account is active again. You can sign in normally.</p>'
            : '<p>Hello '.e($user->name).',</p><p>Your Custosell account has been deactivated.</p>'.$reasonLine;

        $this->sendToEmail($user->email, $title, $body);
    }
}
