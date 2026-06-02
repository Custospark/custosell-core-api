<?php

/**
 * Vera Extended — fast checks + targeted BE gates (no full suite).
 * Usage: php scripts/vera-extended.php
 */

declare(strict_types=1);

$root = dirname(__DIR__);
chdir($root);

passthru('php scripts/vera-fast.php', $fastExit);
if ($fastExit !== 0) {
    exit(1);
}

function veraCollectChangedPaths(): array
{
    $commands = [
        'git diff --name-only --diff-filter=ACMRTUXB HEAD',
        'git diff --cached --name-only --diff-filter=ACMRTUXB',
    ];

    $files = [];

    foreach ($commands as $command) {
        $output = shell_exec($command . ' 2>nul') ?? shell_exec($command . ' 2>/dev/null') ?? '';
        foreach (preg_split('/\R/', trim($output)) as $path) {
            if ($path !== '') {
                $files[$path] = $path;
            }
        }
    }

    return array_values($files);
}

$changed = veraCollectChangedPaths();

$hasMigration = (bool) array_filter(
    $changed,
    static fn (string $p) => str_contains($p, 'database/migrations/') && str_ends_with($p, '.php'),
);

$hasRoutes = (bool) array_filter(
    $changed,
    static fn (string $p) => str_contains($p, 'routes/') && str_ends_with($p, '.php'),
);

if ($hasMigration) {
    echo "🧪 Vera extended: migrate --pretend\n";
    passthru('php artisan migrate --pretend --no-interaction', $migrateExit);
    if ($migrateExit !== 0) {
        exit(1);
    }
}

if ($hasRoutes) {
    echo "🧪 Vera extended: route file syntax (no full route:list)\n";
    foreach ($changed as $path) {
        if (!str_contains($path, 'routes/') || !str_ends_with($path, '.php')) {
            continue;
        }
        passthru('php -l ' . escapeshellarg($path), $routeLintExit);
        if ($routeLintExit !== 0) {
            exit(1);
        }
    }
}

$testFilters = [];

foreach ($changed as $path) {
    if (!preg_match('#tests/(?:Feature|Unit)/(.+?)Test\.php$#', $path, $m)) {
        continue;
    }
    $testFilters[] = $m[1];
}

foreach ($changed as $path) {
    if (preg_match('#app/(?:Http/Controllers|Services|Models)/(?:.+/)?([A-Za-z0-9]+)(?:Controller|Service)?\.php$#', $path, $m)) {
        $testFilters[] = $m[1];
    }
}

$testFilters = array_values(array_unique(array_filter($testFilters)));

if ($testFilters !== []) {
    foreach ($testFilters as $filter) {
        echo "🧪 Vera extended: php artisan test --filter={$filter}\n";
        passthru(
            'php artisan test --filter=' . escapeshellarg($filter) . ' --no-interaction',
            $testExit,
        );
        if ($testExit !== 0) {
            exit(1);
        }
    }
} else {
    echo "🧪 Vera extended: no targeted tests inferred — skipped PHPUnit.\n";
}

echo "🧪 Vera extended: done.\n";
exit(0);
