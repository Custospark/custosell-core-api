<?php

namespace App\Notifications;

use App\Models\Business;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Mail;

class DormantAccountWarning extends Notification
{
    use Queueable;

    public Business $business;

    public function __construct(Business $business)
    {
        $this->business = $business;
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): void
    {
        $frontendUrl = config('app.frontend_url', 'http://localhost:5173');
        $exportUrl = rtrim($frontendUrl, '/') . '/settings/data-export';

        $logoDataUri = null;
        $logoPath = public_path('images/custosell-logo-email.png');
        if (file_exists($logoPath)) {
            $data = file_get_contents($logoPath);
            $mime = 'image/png';
            $logoDataUri = 'data:' . $mime . ';base64,' . base64_encode($data);
        }

        Mail::send('emails.standard', [
            'title' => 'Your Custosell Account Will Be Deleted Due to Inactivity',
            'logoUrl' => $logoDataUri,
            'mailBody' => '
                <p>Hello <strong>' . e($notifiable->name) . '</strong>,</p>
                <p>Your Custosell account for <strong>' . e($this->business->name) . '</strong> has been inactive for over 120 days.</p>
                <p>If no action is taken within <strong>7 days</strong>, your business account and all associated data will be permanently deleted.</p>
                <p>To keep your account active, simply log in to Custosell. If you no longer need the platform, we recommend exporting your data before the deletion takes effect.</p>
                <p style="font-size:14px; color:#64748b;">What will be deleted: all sales, invoices, payments, products, customers, expenses, pipeline boards, documents, estimates, accounting data, and all other business data.</p>
            ',
            'ctaUrl' => $exportUrl,
            'ctaLabel' => 'Export My Data',
            'tip' => 'Log in to your account within 7 days to prevent automatic deletion.',
            'isHtml' => true,
        ], function ($message) use ($notifiable) {
            $message->to($notifiable->email)
                    ->subject('Warning: Your Custosell Account Will Be Deleted Due to Inactivity');
        });
    }
}
