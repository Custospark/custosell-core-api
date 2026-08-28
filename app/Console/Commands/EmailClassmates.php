<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

class EmailClassmates extends Command
{
    protected $signature = 'email:classmates
        {--list= : Path to recipients CSV (name,email,phone)}
        {--test= : Single recipient email to send to (review) }
        {--delay=20 : Seconds to sleep between sends}
        {--dry-run : Render and print, do not send}';

    protected $description = 'Send the personalized Custosell classmate marketing email through the configured mailer';

    private const SUBJECT = 'Built by one of us - Custosell is here, and it can earn for you';

    public function handle(): int
    {
        $delay = max(0, (int) $this->option('delay'));
        $test = (string) $this->option('test');
        $list = (string) $this->option('list');

        $recipients = $this->loadRecipients($list, $test);
        if ($recipients === []) {
            $this->error('No recipients. Provide --test EMAIL or --list CSV.');
            return self::FAILURE;
        }

        $this->info('from: '.config('mail.from.address'));
        $this->info('recipients: '.count($recipients).' | delay: '.$delay.'s | dry-run: '.($this->option('dry-run') ? 'yes' : 'no'));

        $sent = 0;
        foreach ($recipients as $i => $recipient) {
            $name = $recipient['name'] === '' ? 'there' : $recipient['name'];
            $first = preg_split('/\s+/', trim($name))[0] ?? $name;

            if ($this->option('dry-run')) {
                $this->line(sprintf('[%d] %s -> %s (dry-run)', $i + 1, $recipient['email'], $first));
                continue;
            }

            try {
                $this->send($recipient['email'], $first);
                $this->line(sprintf('[%d] sent to %s', $i + 1, $recipient['email']));
                $sent++;
            } catch (\Throwable $e) {
                $this->error(sprintf('[%d] FAILED %s: %s', $i + 1, $recipient['email'], $e->getMessage()));
            }

            if ($i < count($recipients) - 1) {
                sleep($delay);
            }
        }

        $this->info(sprintf('done. sent=%d of %d', $sent, count($recipients)));
        return self::SUCCESS;
    }

    /** @return list<array{name: string, email: string, phone: ?string}> */
    private function loadRecipients(string $list, string $test): array
    {
        if ($test !== '') {
            return [['name' => '', 'email' => $test, 'phone' => null]];
        }

        $path = $list !== '' ? $list : base_path('docs/custosell-recipients.csv');
        if (!is_file($path)) {
            $this->error("recipients file not found: {$path}");
            return [];
        }

        $recipients = [];
        if (($fh = fopen($path, 'r')) !== false) {
            $header = fgetcsv($fh);
            while (($row = fgetcsv($fh)) !== false) {
                $assoc = $this->assocRow($header, $row);
                $email = strtolower(trim((string) ($assoc['email'] ?? '')));
                if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    continue;
                }
                $phone = isset($assoc['phone']) && $assoc['phone'] !== '' ? (string) $assoc['phone'] : null;
                $recipients[] = [
                    'name' => trim((string) ($assoc['name'] ?? '')),
                    'email' => $email,
                    'phone' => $phone,
                ];
            }
            fclose($fh);
        }

        return $recipients;
    }

    /** @param  list<string|null>  $header */
    private function assocRow(array $header, array $row): array
    {
        $assoc = [];
        foreach ($row as $i => $value) {
            $key = $header[$i] ?? null;
            if ($key !== null) {
                $assoc[$key] = $value;
            }
        }
        return $assoc;
    }

    private function send(string $to, string $firstName): void
    {
        $logoDataUri = null;
        $logoPath = public_path('images/custosell-logo-email.png');
        if (file_exists($logoPath)) {
            $data = file_get_contents($logoPath);
            $mime = 'image/png';
            $logoDataUri = 'data:'.$mime.';base64,'.base64_encode($data);
        }

        $body = $this->marketingBody($firstName);

        Mail::send('emails.standard', [
            'title' => 'From our class to the world - Custosell, built by one of us',
            'logoUrl' => $logoDataUri,
            'mailBody' => $body,
            'isHtml' => true,
            'ctaUrl' => 'https://custosell.com/register',
            'ctaLabel' => 'Create your free account',
        ], function ($message) use ($to) {
            $message->to($to)->subject(self::SUBJECT);
        });
    }

    private function marketingBody(string $firstName): string
    {
        return <<<HTML
        <p>Hello <strong>{$firstName}</strong>,</p>
        <p>I hope the semester is going well. I'm <strong>Opiyo Oscar</strong> — and I built something I want to share with our class first, because it literally started here.</p>
        <p>Together with <strong>Custospark Company Ltd</strong>, I built <strong>Custosell</strong>: one system for running a business — Point of Sale, an online store, inventory, accounting, invoicing, expenses, HR &amp; payroll, CRM, and more — that even works offline.</p>

        <div style="background:#f9fafb;border:1px solid #e5e7eb;border-radius:10px;padding:16px 18px;margin:16px 0;">
          <h3 style="margin:0 0 6px;font-size:15px;color:#2563eb;">1. Create a free Personal account — get organized.</h3>
          <p style="margin:0;font-size:14px;color:#374151;">With a Personal account you get project management, productivity tools, expense tracking, bookkeeping, and document management — all in one place, offline-ready. Start free and upgrade whenever you like.</p>
        </div>

        <div style="background:#f9fafb;border:1px solid #e5e7eb;border-radius:10px;padding:16px 18px;margin:16px 0;">
          <h3 style="margin:0 0 6px;font-size:15px;color:#2563eb;">2. Turn your network into income — with your own QR code.</h3>
          <p style="margin:0;font-size:14px;color:#374151;">The moment you create an account, Custosell automatically generates a personal referral QR code for you. When anyone uses it to subscribe to a Custosell plan, you earn a commission on what they actually pay — paid out monthly to your Mobile Money or bank account. There's no limit to how many businesses you can refer, and your dashboard tracks every earning.</p>
        </div>

        <div style="background:#f9fafb;border:1px solid #e5e7eb;border-radius:10px;padding:16px 18px;margin:16px 0;">
          <h3 style="margin:0 0 6px;font-size:15px;color:#2563eb;">3. When you're ready to start something real — you already have the engine.</h3>
          <p style="margin:0;font-size:14px;color:#374151;">Planning a startup? Start with your Personal account today, and upgrade to a Business account when you're ready — POS, inventory, accounting, and your online store, without re-inventing the wheel.</p>
        </div>

        <p><strong>Built by one of us.</strong> Custosell grew out of our class. Whether you use it, or simply refer it to a business you know, we'd love for our class to be part of it.</p>
        <p style="text-align:center;font-size:13px;color:#6b7280;">Or explore at <a href="https://custosell.com" style="color:#2563eb;">custosell.com</a> — reply to this email and I'll walk you through it personally.</p>
        HTML;
    }
}