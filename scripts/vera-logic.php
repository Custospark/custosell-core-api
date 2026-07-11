<?php

/**
 * Vera Logic — Backend repo rules & contracts (not php -l).
 * Usage: php scripts/vera-logic.php
 * Also runs from vera-fast.php after syntax checks.
 */

declare(strict_types=1);

$root = dirname(__DIR__);
chdir($root);

const VERA_MAX_LINES = 500;

/**
 * @return list<string>
 */
function veraLogicChangedPhpFiles(string $root): array
{
    $commands = [
        'git diff --name-only --diff-filter=ACMRTUXB HEAD',
        'git diff --cached --name-only --diff-filter=ACMRTUXB',
        'git ls-files --others --exclude-standard',
    ];

    $files = [];

    foreach ($commands as $command) {
        $output = shell_exec($command . ' 2>nul') ?? shell_exec($command . ' 2>/dev/null') ?? '';
        foreach (preg_split('/\R/', trim($output)) as $path) {
            if ($path === '' || !str_ends_with($path, '.php')) {
                continue;
            }
            $normalized = str_replace('\\', '/', $path);
            if (!str_starts_with($normalized, 'app/') && !str_starts_with($normalized, 'tests/')) {
                continue;
            }
            $full = $root . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
            if (is_file($full)) {
                $files[$normalized] = $normalized;
            }
        }
    }

    return array_values($files);
}

function veraLogicRead(string $root, string $rel): ?string
{
    $full = $root . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $rel);
    if (!is_file($full)) {
        return null;
    }

    return file_get_contents($full) ?: '';
}

function veraLogicLineCount(string $root, string $rel): int
{
    $text = veraLogicRead($root, $rel);
    if ($text === null) {
        return 0;
    }

    return substr_count($text, "\n") + (str_ends_with($text, "\n") ? 0 : 1);
}

/**
 * @return list<array{id: string, ok: bool, detail: string}>
 */
function veraLogicCheckFileSize(string $root, array $changed): array
{
    $results = [];
    foreach ($changed as $file) {
        $lines = veraLogicLineCount($root, $file);
        if ($lines > VERA_MAX_LINES) {
            $results[] = [
                'id' => 'file-size-500',
                'ok' => false,
                'detail' => "{$file} has {$lines} lines (max " . VERA_MAX_LINES . ')',
            ];
        }
    }

    if ($results === []) {
        $results[] = [
            'id' => 'file-size-500',
            'ok' => true,
            'detail' => $changed === []
                ? 'No changed app/tests PHP — size check skipped'
                : 'Changed app/tests PHP ≤ ' . VERA_MAX_LINES . ' lines (' . count($changed) . ' checked)',
        ];
    }

    return $results;
}

/**
 * @return array{id: string, ok: bool, detail: string}
 */
function veraLogicOwnerOnlyPayments(string $root): array
{
    $src = veraLogicRead($root, 'app/Services/InvoiceService.php') ?? '';
    $ok = (bool) preg_match(
        '/function\s+canManagePayments\s*\([^)]*\)[^{]*\{[^}]*isOwnedByBusiness/s',
        $src
    );

    return [
        'id' => 'owner-only-payments',
        'ok' => $ok,
        'detail' => $ok
            ? 'InvoiceService::canManagePayments is owner-only (isOwnedByBusiness)'
            : 'InvoiceService::canManagePayments must return isOwnedByBusiness (seller-only payments)',
    ];
}

/**
 * @return array{id: string, ok: bool, detail: string}
 */
function veraLogicBuyerApService(string $root): array
{
    $service = veraLogicRead($root, 'app/Services/SupplierInvoiceAccountingService.php');
    $invoiceListener = veraLogicRead($root, 'app/Listeners/AccountForInvoiceSent.php') ?? '';
    $paymentListener = veraLogicRead($root, 'app/Listeners/AccountForPaymentRecorded.php') ?? '';

    $hasService = $service !== null
        && str_contains($service, 'REF_INVOICE')
        && str_contains($service, 'postBuyerOnInvoiceSent')
        && str_contains($service, 'postBuyerOnPayment');

    $wiredSend = str_contains($invoiceListener, 'postBuyerOnInvoiceSent');
    $wiredPay = str_contains($paymentListener, 'postBuyerOnPayment');

    $ok = $hasService && $wiredSend && $wiredPay;

    return [
        'id' => 'buyer-ap-automation',
        'ok' => $ok,
        'detail' => $ok
            ? 'SupplierInvoiceAccountingService wired on invoice send + payment'
            : 'Missing buyer AP service or listener wiring (postBuyerOnInvoiceSent / postBuyerOnPayment)',
    ];
}

/**
 * @return array{id: string, ok: bool, detail: string}
 */
function veraLogicBuyerApAccounts(string $root): array
{
    $service = veraLogicRead($root, 'app/Services/SupplierInvoiceAccountingService.php') ?? '';
    $hasAp = str_contains($service, "accounts_payable") || str_contains($service, "'2101'");
    $hasInventory = str_contains($service, "['inventory']") || str_contains($service, "inventory");

    $ok = $hasAp && $hasInventory;

    return [
        'id' => 'buyer-ap-accounts',
        'ok' => $ok,
        'detail' => $ok
            ? 'Buyer AP automation references accounts_payable + inventory'
            : 'Buyer AP automation must post AP and inventory (or expense) accounts',
    ];
}

/**
 * @return array{id: string, ok: bool, detail: string}
 */
function veraLogicPhpImports(string $root, array $changed): array
{
    if ($changed === []) {
        return [
            'id' => 'php-imports',
            'ok' => true,
            'detail' => 'No changed app/tests PHP — import check skipped',
        ];
    }

    $broken = [];

    foreach ($changed as $file) {
        $text = veraLogicRead($root, $file);
        if ($text === null) {
            continue;
        }

        // use App\Foo\Bar; | use App\Foo\Bar as Alias;
        if (!preg_match_all('/^\s*use\s+(App\\\\[A-Za-z0-9_\\\\]+|Tests\\\\[A-Za-z0-9_\\\\]+)\s*(?:as\s+\w+)?\s*;/m', $text, $matches)) {
            continue;
        }

        foreach ($matches[1] as $fqcn) {
            $resolved = veraLogicResolvePsr4($root, $fqcn);
            if ($resolved === null || !is_file($resolved)) {
                $broken[] = "{$file} → {$fqcn}";
            }
        }
    }

    if ($broken !== []) {
        $shown = array_slice($broken, 0, 8);
        $extra = count($broken) > 8 ? ' (+' . (count($broken) - 8) . ' more)' : '';

        return [
            'id' => 'php-imports',
            'ok' => false,
            'detail' => 'Unresolved App/Tests use import(s): ' . implode('; ', $shown) . $extra,
        ];
    }

    return [
        'id' => 'php-imports',
        'ok' => true,
        'detail' => 'App/Tests use imports resolve for ' . count($changed) . ' changed file(s)',
    ];
}

/**
 * Map App\ / Tests\ FQCN to a filesystem path (PSR-4).
 */
function veraLogicResolvePsr4(string $root, string $fqcn): ?string
{
    $map = [
        'App\\' => 'app/',
        'Tests\\' => 'tests/',
    ];

    foreach ($map as $prefix => $base) {
        if (!str_starts_with($fqcn, $prefix)) {
            continue;
        }
        $relative = str_replace('\\', '/', substr($fqcn, strlen($prefix))) . '.php';

        return $root . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $base . $relative);
    }

    return null;
}

/**
 * @return array{id: string, ok: bool, detail: string}
 */
function veraLogicBuyerApTest(string $root): array
{
    $testA = veraLogicRead($root, 'tests/Feature/SupplyChainTest.php') ?? '';
    $testB = veraLogicRead($root, 'tests/Feature/SupplyChainReceiveAndPartyTest.php') ?? '';
    $haystack = $testA."\n".$testB;
    $ok = str_contains($haystack, 'test_shared_po_invoice_posts_seller_ar_and_buyer_ap')
        || str_contains($haystack, 'shared_po_invoice_posts_seller_ar_and_buyer_ap');

    return [
        'id' => 'buyer-ap-feature-test',
        'ok' => $ok,
        'detail' => $ok
            ? 'Supply chain tests cover seller AR + buyer AP journals'
            : 'Missing feature test for shared PO invoice AR/AP posting',
    ];
}

$changed = veraLogicChangedPhpFiles($root);
$results = array_merge(
    veraLogicCheckFileSize($root, $changed),
    [
        veraLogicPhpImports($root, $changed),
        veraLogicOwnerOnlyPayments($root),
        veraLogicBuyerApService($root),
        veraLogicBuyerApAccounts($root),
        veraLogicBuyerApTest($root),
    ],
);

$failed = array_values(array_filter($results, static fn (array $r): bool => !$r['ok']));

echo '🧪 Vera logic: ' . count($results) . " rule(s)\n";
foreach ($results as $r) {
    echo '  ' . ($r['ok'] ? '✅' : '❌') . " [{$r['id']}] {$r['detail']}\n";
}

if ($failed !== []) {
    echo '❌ Vera logic: failed (' . count($failed) . ")\n";
    exit(1);
}

echo "✅ Vera logic: passed\n";
exit(0);
