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
            $logoDataUri = 'data:image/png;base64,'.base64_encode((string) file_get_contents($logoPath));
        }

        Mail::send('emails.classmate', [
            'firstName' => $firstName,
            'year' => now()->year,
            'logoUrl' => $logoDataUri,
        ], function ($message) use ($to) {
            $message->to($to)->subject(self::SUBJECT);
        });
    }
}