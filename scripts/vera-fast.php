<?php

/**
 * Vera Fast — syntax on changed PHP + Vera Logic (repo rules/contracts).
 * Usage: php scripts/vera-fast.php
 */

declare(strict_types=1);

$root = dirname(__DIR__);
chdir($root);

function veraCollectChangedPhpFiles(string $root): array
{
    $commands = [
        'git diff --name-only --diff-filter=ACMRTUXB HEAD',
        'git diff --cached --name-only --diff-filter=ACMRTUXB',
    ];

    $files = [];

    foreach ($commands as $command) {
        $output = shell_exec($command . ' 2>nul') ?? shell_exec($command . ' 2>/dev/null') ?? '';
        foreach (preg_split('/\R/', trim($output)) as $path) {
            if ($path === '' || !str_ends_with($path, '.php')) {
                continue;
            }
            $full = $root . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
            if (is_file($full)) {
                $files[$path] = $path;
            }
        }
    }

    return array_values($files);
}

$failed = false;
$files = veraCollectChangedPhpFiles($root);

if ($files === []) {
    echo "🧪 Vera fast: no changed PHP files — php -l skipped.\n";
} else {
    echo '🧪 Vera fast: php -l on ' . count($files) . " file(s)\n";
    foreach ($files as $file) {
        $cmd = 'php -l ' . escapeshellarg($file);
        passthru($cmd, $exitCode);
        if ($exitCode !== 0) {
            $failed = true;
        }
    }
    if (!$failed) {
        echo "✅ Vera fast: php -l passed\n";
    }
}

passthru('php ' . escapeshellarg($root . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'vera-logic.php'), $logicExit);
if ($logicExit !== 0) {
    $failed = true;
}

if ($failed) {
    echo "❌ Vera fast: failed\n";
    exit(1);
}

echo "✅ Vera fast: passed (php -l + logic)\n";
exit(0);
