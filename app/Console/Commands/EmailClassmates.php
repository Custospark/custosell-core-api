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
        {--delay=10 : Seconds to sleep between sends}
        {--dry-run : Render and print, do not send}
        {--log= : Optional CSV path to record send results (email,name,status,error,time)}';

    protected $description = 'Send the personalized Custosell classmate marketing email through the configured mailer';

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
        $failed = 0;
        $logPath = (string) $this->option('log');
        $logHandle = $logPath !== '' ? $this->openLog($logPath) : null;

        foreach ($recipients as $i => $recipient) {
            $name = $recipient['name'] === '' ? 'there' : $recipient['name'];
            $tokens = preg_split('/\s+/', trim($name));
            $first = $tokens !== false && $tokens !== [] ? end($tokens) : $name;

            if ($this->option('dry-run')) {
                $this->line(sprintf('[%d] %s -> %s (dry-run)', $i + 1, $recipient['email'], $first));
                continue;
            }

            $status = 'sent';
            $error = '';
            try {
                $this->sendClassmateEmail($recipient['email'], $first);
                $this->line(sprintf('[%d] sent to %s', $i + 1, $recipient['email']));
                $sent++;
            } catch (\Throwable $e) {
                $status = 'failed';
                $error = str_replace(["\r", "\n"], ' ', $e->getMessage());
                $this->error(sprintf('[%d] FAILED %s: %s', $i + 1, $recipient['email'], $e->getMessage()));
                $failed++;
            }

            if ($logHandle !== null) {
                $this->writeLog($logHandle, $recipient['email'], $recipient['name'], $status, $error);
            }

            if ($i < count($recipients) - 1) {
                sleep($delay);
            }
        }

        if ($logHandle !== null) {
            fclose($logHandle);
            $this->info('results logged to: '.$logPath);
        }

        $this->info(sprintf('done. sent=%d failed=%d of %d', $sent, $failed, count($recipients)));
        return self::SUCCESS;
    }

    /** @return list<array{name: string, email: string, phone: ?string}> */
    private function loadRecipients(string $list, string $test): array
    {
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

        if ($test !== '') {
            $target = strtolower(trim($test));
            foreach ($recipients as $rec) {
                if ($rec['email'] === $target) {
                    return [$rec];
                }
            }
            return [['name' => '', 'email' => $target, 'phone' => null]];
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

    private function openLog(string $path)
    {
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        }
        $fh = fopen($path, 'w');
        if ($fh !== false) {
            fputcsv($fh, ['email', 'name', 'status', 'error', 'time']);
        }
        return $fh;
    }

    /** @param  resource  $fh */
    private function writeLog($fh, string $email, string $name, string $status, string $error): void
    {
        fputcsv($fh, [$email, $name, $status, $error, now()->toIso8601String()]);
    }

    private function sendClassmateEmail(string $to, string $firstName): void
    {
        $logoPath = public_path('images/custosell-logo-email.png');
        $logoCid = null;

        // Render the body first with a placeholder; the real cid is assigned
        // inside the message callback (embed() only works there).
        $body = view('emails.classmate', [
            'firstName' => $firstName,
            'year' => now()->year,
            'logoCid' => '__CUSTOSELL_LOGO_CID__',
        ])->render();

        Mail::send([], [], function ($message) use ($to, $body, $logoPath, &$logoCid) {
            $message->to($to);
            $message->subject('Built by one of us - meet Custosell from Custospark.');
            $message->from(config('mail.from.address'), 'Custospark Company Ltd');
            if (file_exists($logoPath)) {
                $logoCid = $message->embed($logoPath);
            }
            $final = str_replace('__CUSTOSELL_LOGO_CID__', (string) $logoCid, $body);
            $message->html($final);
        });
    }
}