<?php

namespace App\Services\Platform;

use App\Models\Business;
use App\Models\User;
use App\Services\Notification\NotificationService;
use Illuminate\Support\Collection;

class PlatformNotificationService
{
    public function __construct(
        protected NotificationService $notificationService,
    ) {}

    public function notifyBusinessStatusChange(
        Business $business,
        string $status,
        string $reason,
        string $channel = 'both',
    ): void {
        $loginUrl = rtrim((string) config('app.frontend_url', config('app.url')), '/').'/login';
        $reasonLine = '<p><strong>Reason:</strong> '.e($reason).'</p>';

        [$title, $body, $ctaLabel, $ctaUrl, $tip] = match ($status) {
            'suspended' => [
                'Your Custosell business account has been suspended',
                '<p>Hello,</p>'
                    .'<p>Your business <strong>'.e($business->name).'</strong> has been suspended on Custosell.</p>'
                    .$reasonLine
                    .'<p>While suspended, you and your staff cannot sign in or use Custosell until the account is reactivated.</p>'
                    .'<p>If you believe this is a mistake, please reach out to the Custosell team.</p>',
                'Contact Support',
                'mailto:support@custosell.com',
                'Only an active business account can access sales, inventory, and reports.',
            ],
            'restricted' => [
                'Your Custosell business account has been restricted',
                '<p>Hello,</p>'
                    .'<p>Your business <strong>'.e($business->name).'</strong> has been restricted on Custosell.</p>'
                    .$reasonLine
                    .'<p>While restricted, you and your staff cannot sign in until the restriction is lifted.</p>'
                    .'<p>Please contact the Custosell team so we can help resolve this.</p>',
                'Contact Support',
                'mailto:support@custosell.com',
                'Resolve the issue promptly to restore access for your team.',
            ],
            'warning' => [
                'Important notice about your Custosell business account',
                '<p>Hello,</p>'
                    .'<p>Your business <strong>'.e($business->name).'</strong> has received an account warning on Custosell.</p>'
                    .$reasonLine
                    .'<p>Your account remains active, but please address this matter to avoid further action.</p>',
                'Sign in to Custosell',
                $loginUrl,
                'Ignoring warnings may lead to account restriction or suspension.',
            ],
            'notified' => [
                'We have been in touch about your business',
                '<p>Hello,</p>'
                    .'<p>Your business <strong>'.e($business->name).'</strong> has a recent message on file from the Custosell team.</p>'
                    .$reasonLine
                    .'<p>Your account is still fully active — no action is needed unless we asked you to respond.</p>',
                'Sign in to Custosell',
                $loginUrl,
                'No action is required unless you received a separate message asking you to respond.',
            ],
            default => [
                'Your Custosell business account has been reactivated',
                '<p>Hello,</p>'
                    .'<p>Your business <strong>'.e($business->name).'</strong> is now active on Custosell.</p>'
                    .$reasonLine
                    .'<p>You and your staff can sign in and continue using the platform.</p>',
                'Sign in to Custosell',
                $loginUrl,
                'Welcome back — your data and settings are unchanged.',
            ],
        };

        $metadata = [
            'business_id' => $business->id,
            'business_name' => $business->name,
            'status' => $status,
            'reason' => $reason,
        ];

        $this->deliverToBusinessRecipients(
            $business,
            $title,
            $body,
            'business_status',
            $channel,
            null,
            $metadata,
            $ctaUrl,
            $ctaLabel,
            $tip,
        );
    }

    public function notifyBusinessMessage(
        Business $business,
        string $intention,
        string $message,
        ?string $subject = null,
        string $channel = 'both',
    ): void {
        $title = $subject ?: $this->defaultSubjectForIntention($intention, $business->name);
        $reasonLine = '<p>'.nl2br(e($message)).'</p>';
        $loginUrl = rtrim((string) config('app.frontend_url', config('app.url')), '/').'/login';

        [$body, $ctaLabel, $ctaUrl, $tip] = match ($intention) {
            'warning_notice' => [
                '<p>Hello,</p>'
                    .'<p>This is an important notice regarding your business <strong>'.e($business->name).'</strong> on Custosell.</p>'
                    .$reasonLine
                    .'<p>Please review and take action if required.</p>',
                'Sign in to Custosell',
                $loginUrl,
                'Addressing notices promptly helps keep your account in good standing.',
            ],
            'payment_reminder' => [
                '<p>Hello,</p>'
                    .'<p>This is a payment reminder for <strong>'.e($business->name).'</strong>.</p>'
                    .$reasonLine,
                'Contact Billing',
                'mailto:support@custosell.com',
                'Keep your subscription current to avoid service interruption.',
            ],
            'policy_update' => [
                '<p>Hello,</p>'
                    .'<p>We have a policy update that applies to <strong>'.e($business->name).'</strong>.</p>'
                    .$reasonLine,
                'Sign in to Custosell',
                $loginUrl,
                'Review policy changes to ensure your business stays compliant.',
            ],
            'reactivation_nudge' => [
                '<p>Hello,</p>'
                    .'<p>We noticed <strong>'.e($business->name).'</strong> has been quiet on Custosell lately.</p>'
                    .$reasonLine
                    .'<p>Sign back in to continue managing sales, inventory, and customers.</p>',
                'Sign in to Custosell',
                $loginUrl,
                'Your data is safe and waiting for you.',
            ],
            'announcement' => [
                '<p>Hello,</p>'
                    .'<p>A message from the Custosell team regarding <strong>'.e($business->name).'</strong>:</p>'
                    .$reasonLine,
                'Sign in to Custosell',
                $loginUrl,
                null,
            ],
            default => [
                '<p>Hello,</p>'
                    .'<p>A message from the Custosell team regarding <strong>'.e($business->name).'</strong>:</p>'
                    .$reasonLine,
                'Sign in to Custosell',
                $loginUrl,
                null,
            ],
        };

        $metadata = [
            'business_id' => $business->id,
            'business_name' => $business->name,
            'intention' => $intention,
            'message' => $message,
        ];

        $this->deliverToBusinessRecipients(
            $business,
            $title,
            $body,
            'platform_message',
            $channel,
            $intention,
            $metadata,
            $ctaUrl,
            $ctaLabel,
            $tip,
        );
    }

    public function notifyUserStatusChange(
        User $user,
        bool $isActive,
        ?string $reason,
        string $channel = 'both',
    ): void {
        $title = $isActive
            ? 'Your Custosell account has been reactivated'
            : 'Your Custosell account has been deactivated';

        $reasonLine = $reason ? '<p><strong>Reason:</strong> '.e($reason).'</p>' : '';
        $body = $isActive
            ? '<p>Hello '.e($user->name).',</p><p>Your Custosell account is active again. You can sign in normally.</p>'
            : '<p>Hello '.e($user->name).',</p><p>Your Custosell account has been deactivated.</p>'.$reasonLine;

        $this->notificationService->sendToUser(
            $user,
            $title,
            $body,
            'user_status',
            $channel,
            $user->business_id,
            null,
            [
                'is_active' => $isActive,
                'reason' => $reason,
            ],
        );
    }

    public function notifyUserMessage(
        User $user,
        string $intention,
        string $message,
        ?string $subject = null,
        string $channel = 'both',
    ): void {
        $title = $subject ?: $this->defaultUserSubjectForIntention($intention, $user->name);
        $reasonLine = '<p>'.nl2br(e($message)).'</p>';
        $loginUrl = rtrim((string) config('app.frontend_url', config('app.url')), '/').'/login';

        [$body, $ctaLabel, $ctaUrl, $tip] = match ($intention) {
            'warning_notice' => [
                '<p>Hello '.e($user->name).',</p>'
                    .'<p>This is an important notice about your Custosell account.</p>'
                    .$reasonLine
                    .'<p>Please review and take action if required.</p>',
                'Sign in to Custosell',
                $loginUrl,
                'Addressing notices promptly helps keep your account in good standing.',
            ],
            'policy_update' => [
                '<p>Hello '.e($user->name).',</p>'
                    .'<p>We have a policy update that applies to your Custosell account.</p>'
                    .$reasonLine,
                'Sign in to Custosell',
                $loginUrl,
                'Review policy changes to stay compliant.',
            ],
            'reactivation_nudge' => [
                '<p>Hello '.e($user->name).',</p>'
                    .'<p>We noticed you have not signed in to Custosell recently.</p>'
                    .$reasonLine
                    .'<p>Sign back in to continue managing your business.</p>',
                'Sign in to Custosell',
                $loginUrl,
                'Your data is safe and waiting for you.',
            ],
            'account_notice' => [
                '<p>Hello '.e($user->name).',</p>'
                    .'<p>A notice regarding your Custosell account:</p>'
                    .$reasonLine,
                'Sign in to Custosell',
                $loginUrl,
                null,
            ],
            'announcement' => [
                '<p>Hello '.e($user->name).',</p>'
                    .'<p>A message from the Custosell team:</p>'
                    .$reasonLine,
                'Sign in to Custosell',
                $loginUrl,
                null,
            ],
            default => [
                '<p>Hello '.e($user->name).',</p>'
                    .'<p>A message from the Custosell team:</p>'
                    .$reasonLine,
                'Sign in to Custosell',
                $loginUrl,
                null,
            ],
        };

        $metadata = [
            'user_id' => $user->id,
            'user_name' => $user->name,
            'intention' => $intention,
            'message' => $message,
        ];

        if ($user->business_id) {
            $metadata['business_id'] = $user->business_id;
        }

        $this->notificationService->sendToUser(
            $user,
            $title,
            $body,
            'platform_message',
            $channel,
            $user->business_id,
            $intention,
            $metadata,
            $ctaUrl,
            $ctaLabel,
            $tip,
        );
    }

    /** @return Collection<int, User> */
    public function businessRecipientUsers(Business $business): Collection
    {
        return User::query()
            ->whereNull('deleted_at')
            ->where('is_active', true)
            ->where(function ($q) use ($business): void {
                $q->where('business_id', $business->id);
                if ($business->owner_id) {
                    $q->orWhere('id', $business->owner_id);
                }
                if ($business->email) {
                    $q->orWhere('email', $business->email);
                }
            })
            ->get();
    }

    /** @return list<string> */
    private function businessRecipientEmails(Business $business): array
    {
        $emails = $this->businessRecipientUsers($business)
            ->pluck('email')
            ->filter()
            ->all();

        if ($business->email) {
            $emails[] = $business->email;
        }

        return array_values(array_unique(array_filter($emails)));
    }

    private function deliverToBusinessRecipients(
        Business $business,
        string $title,
        string $htmlBody,
        string $type,
        string $channel,
        ?string $intention = null,
        ?array $metadata = null,
        ?string $ctaUrl = null,
        ?string $ctaLabel = null,
        ?string $tip = null,
    ): void {
        $plainBody = $this->notificationService->plainTextFromHtml($htmlBody);
        $contentKey = $this->notificationService->buildContentDedupeKey(
            $business->id,
            $type,
            $intention,
            $title,
            $plainBody,
        );

        if (in_array($channel, ['in_app', 'both'], true)) {
            $seenUserIds = [];

            foreach ($this->businessRecipientUsers($business) as $user) {
                if (isset($seenUserIds[$user->id])) {
                    continue;
                }
                $seenUserIds[$user->id] = true;

                $this->notificationService->persistInAppIfNew(
                    $user->id,
                    $title,
                    $plainBody,
                    $type,
                    $business->id,
                    $intention,
                    $metadata,
                    $contentKey,
                );
            }
        }

        if (in_array($channel, ['email', 'both'], true)) {
            $seenEmails = [];

            foreach ($this->businessRecipientEmails($business) as $email) {
                $normalized = strtolower(trim($email));
                if ($normalized === '' || isset($seenEmails[$normalized])) {
                    continue;
                }
                $seenEmails[$normalized] = true;

                $this->notificationService->sendEmailIfNew(
                    $email,
                    $contentKey,
                    $title,
                    $htmlBody,
                    $ctaUrl,
                    $ctaLabel,
                    $tip,
                );
            }
        }
    }

    private function defaultSubjectForIntention(string $intention, string $businessName): string
    {
        return match ($intention) {
            'warning_notice' => "Important notice for {$businessName}",
            'payment_reminder' => "Payment reminder for {$businessName}",
            'policy_update' => "Policy update for {$businessName}",
            'reactivation_nudge' => "We'd love to see {$businessName} back on Custosell",
            'announcement' => "Announcement for {$businessName}",
            default => "Message from the Custosell team for {$businessName}",
        };
    }

    private function defaultUserSubjectForIntention(string $intention, string $userName): string
    {
        return match ($intention) {
            'warning_notice' => "Important notice for {$userName}",
            'policy_update' => "Policy update for your Custosell account",
            'reactivation_nudge' => "We'd love to see you back on Custosell",
            'account_notice' => "Account notice for {$userName}",
            'announcement' => 'Announcement from Custosell',
            default => 'Message from the Custosell team',
        };
    }

}
