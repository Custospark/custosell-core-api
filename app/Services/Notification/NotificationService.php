<?php
// app/Services/Notification/NotificationService.php

declare(strict_types=1);

namespace App\Services\Notification;

use App\Mail\StandardEmail;
use App\Models\Facility;
use App\Models\Notification;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Handles in-app notification persistence and transactional email delivery.
 *
 * This service is intentionally NOT event-aware — it is a pure delivery
 * mechanism called by Event Listeners. This keeps concerns cleanly separated:
 *
 *   Event → Listener → NotificationService → (DB record + Mail)
 *
 * Memory safety: bulk operations use chunk() rather than loading all
 * User records into memory at once.
 */
class NotificationService
{
    /** Number of users processed per chunk in broadcast operations. */
    private const BROADCAST_CHUNK_SIZE = 100;

    // ─────────────────────────────────────────────────────────────────────────
    // Public API
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Send a notification to a specific user.
     *
     * @param User   $user    Fully-loaded User model (avoids extra DB query)
     * @param string $title   Notification / email subject
     * @param string $body    HTML or plain-text body
     * @param string $type    Logical type tag (e.g. 'email_verification', 'security_alert')
     * @param string $channel 'email' | 'in_app' | 'both'
     */
    public function sendToUser(
        User   $user,
        string $title,
        string $body,
        string $type,
        string $channel = 'email',
        ?string $ctaUrl = null,
        ?string $ctaLabel = null,
    ): void {
        if (in_array($channel, ['in_app', 'both'], true)) {
            $this->persistNotification($user->id, $title, $body, $type, $channel);
        }

        if (in_array($channel, ['email', 'both'], true)) {
            $this->dispatchEmail($user, $title, $body, $ctaUrl, $ctaLabel);
        }
    }

    /**
     * Send a billing email to a facility's owner(s) and the facility's direct email.
     *
     * @param  Facility                                            $facility
     * @param  string                                              $subject
     * @param  string                                              $body        HTML body
     * @param  array<int, array{data: string, name: string, mime: string}>  $attachments
     */
    public function sendBillingToFacility(
        Facility $facility,
        string   $subject,
        string   $body,
        array    $attachments = [],
    ): void {
        $emails = [];

        // Facility's direct email
        if ($facility->email) {
            $emails[] = $facility->email;
        }

        // Facility owners' emails (facility_owners → staff → users)
        $owners = DB::table('facility_owners')
            ->join('staff', 'facility_owners.staff_id', '=', 'staff.id')
            ->join('users', 'staff.user_id', '=', 'users.id')
            ->where('facility_owners.facility_id', $facility->id)
            ->whereNotNull('users.email_encrypted')
            ->select('users.id', 'users.email_encrypted')
            ->get();

        foreach ($owners as $owner) {
            try {
                $emails[] = decrypt($owner->email_encrypted);
            } catch (\Exception $e) {
                Log::warning('sendBillingToFacility: failed to decrypt owner email', [
                    'user_id'     => $owner->id,
                    'facility_id' => $facility->id,
                ]);
            }
        }

        $emails = array_unique(array_filter($emails));

        foreach ($emails as $email) {
            try {
                Mail::to($email)->send(new StandardEmail(
                    title:           $subject,
                    mailBody:        $body,
                    isHtml:          true,
                    fileAttachments: $attachments,
                ));

                Log::info('Billing email sent', [
                    'email'       => $email,
                    'facility_id' => $facility->id,
                    'subject'     => $subject,
                ]);
            } catch (\Exception $e) {
                Log::error('Billing email failed', [
                    'email'       => $email,
                    'facility_id' => $facility->id,
                    'error'       => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Build a formatted billing info block for email bodies.
     */
    public static function billingInfoBlock(?Subscription $subscription): string
    {
        if (! $subscription) return '';

        $plan = $subscription->plan;
        $cycle = $subscription->billing_cycle === 'yearly' ? 'Annual' : 'Monthly';
        $price = $plan?->price_usd ?? 0;
        $displayPrice = $subscription->billing_cycle === 'yearly'
            ? round($price * 10 / 12, 2) . " USD / mo (billed annually — $" . round($price * 10, 2) . "/yr)"
            : $price . " USD / mo";

        $lines = [
            "<strong>Plan:</strong> {$plan?->name}",
            "<strong>Billing Cycle:</strong> {$cycle}",
            "<strong>Price:</strong> {$displayPrice}",
        ];

        if ($subscription->starts_at) {
            $lines[] = "<strong>Started:</strong> " . $subscription->starts_at->format('M j, Y');
        }

        if ($subscription->trial_ends_at && $subscription->trial_ends_at->isFuture()) {
            $lines[] = "<strong>Trial Ends:</strong> " . $subscription->trial_ends_at->format('M j, Y');
        }

        if ($subscription->next_billing_date) {
            $lines[] = "<strong>Next Renewal:</strong> " . $subscription->next_billing_date->format('M j, Y');
        }

        $html = '<table style="width:100%;border-collapse:collapse;margin:16px 0;font-size:13px;background:#f9fafb;border-radius:8px;">';
        $html .= '<tbody>';
        foreach ($lines as $line) {
            $html .= '<tr><td style="padding:6px 16px;border-bottom:1px solid #e5e7eb;">' . $line . '</td></tr>';
        }
        $html .= '</tbody></table>';

        return $html;
    }

    /**
     * Broadcast a notification to ALL users.
     * Chunks the query to prevent loading the entire users table into memory.
     *
     * @param string $title
     * @param string $body
     * @param string $type
     * @param string $channel  'email' | 'in_app' | 'both'
     */
    public function broadcastToAll(
        string $title,
        string $body,
        string $type,
        string $channel = 'in_app'
    ): void {
        // Persist a single system-wide notification record (no user_id)
        if (in_array($channel, ['in_app', 'both'], true)) {
            $this->persistSystemNotification($title, $body, $type, $channel);
        }

        // Send individual emails — chunked to avoid OOM
        if (in_array($channel, ['email', 'both'], true)) {
            User::query()
                ->whereNotNull('email_encrypted') // Only users with an email on file
                ->chunk(self::BROADCAST_CHUNK_SIZE, function ($users) use ($title, $body) {
                    foreach ($users as $user) {
                        $this->dispatchEmail($user, $title, $body);
                    }
                });
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Legacy compatibility shim
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * @deprecated Use sendToUser() or broadcastToAll() directly.
     *             Kept for backward compatibility with any existing callers.
     */
    public function sendNotification(
        string  $title,
        string  $body,
        string  $type,
        string  $channel,
        ?int    $userId = null
    ): void {
        if ($userId !== null) {
            $user = User::find($userId);

            if (!$user) {
                Log::warning('sendNotification: user not found', ['user_id' => $userId]);
                return;
            }

            $this->sendToUser($user, $title, $body, $type, $channel);
            return;
        }

        $this->broadcastToAll($title, $body, $type, $channel);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Private helpers
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Persist an in-app notification record for a specific user.
     */
    private function persistNotification(
        int    $userId,
        string $title,
        string $body,
        string $type,
        string $channel
    ): void {
        Notification::create([
            'user_id'     => $userId,
            'title'       => $title,
            'message'     => $body,
            'target_type' => $type,
            'channel'     => $channel,
            'sent_at'     => Carbon::now(),
        ]);
    }

    /**
     * Persist a system-wide broadcast notification (no specific user).
     */
    private function persistSystemNotification(
        string $title,
        string $body,
        string $type,
        string $channel
    ): void {
        Notification::create([
            'user_id'     => null,
            'title'       => $title,
            'message'     => $body,
            'target_type' => $type,
            'channel'     => $channel,
            'sent_at'     => Carbon::now(),
        ]);
    }

    /**
     * Decrypt the user's email and send a transactional email.
     * Failures are caught and logged so one bad address never kills a batch.
     */
    private function dispatchEmail(
        User    $user,
        string  $title,
        string  $body,
        ?string $ctaUrl = null,
        ?string $ctaLabel = null,
    ): void {
        if (empty($user->email_encrypted)) {
            Log::warning('dispatchEmail: user has no encrypted email', ['user_id' => $user->id]);
            return;
        }

        try {
            $email = decrypt($user->email_encrypted);

            Mail::to($email)->send(new StandardEmail(
                title:    $title,
                mailBody: $body,
                ctaUrl:   $ctaUrl,
                ctaLabel: $ctaLabel,
                isHtml:   true
            ));

            Log::info('Email dispatched', ['user_id' => $user->id]);

        } catch (\Exception $e) {
            Log::error('Email dispatch failed', [
                'user_id' => $user->id,
                'error'   => $e->getMessage(),
            ]);
        }
    }
}
