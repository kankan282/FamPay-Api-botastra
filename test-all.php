<?php
/**
 * /test-all.php - complete system self test (environment, database, QR,
 * validation, security helpers, files) plus optional live endpoint calls.
 *
 * Live endpoint calls: /test-all.php?http=1&admin_password=YOUR_ADMIN_PASSWORD
 */

declare(strict_types=1);

require_once __DIR__ . '/api/test-ui.php';
require_once __DIR__ . '/api/qr-generator.php';

tests_begin('Full System Test', 'FamPay Gateway v' . APP_VERSION . ' - environment, database, QR, validation, security and endpoints.');

// ---------------------------------------------------------------- environment
tests_add(PHP_VERSION_ID >= 80100, 'PHP >= 8.1', PHP_VERSION);
foreach (['pdo_pgsql' => true, 'gd' => true, 'mbstring' => false, 'json' => true, 'openssl' => false, 'imap' => false] as $ext => $required) {
    $loaded = extension_loaded($ext);
    tests_add($required ? $loaded : ($loaded ?: null), 'Extension ' . $ext, $loaded ? 'loaded' : ($required ? 'MISSING (required)' : 'not loaded (optional here, installed by the Dockerfile)'));
}
tests_add(is_writable(APP_TMP_DIR), 'Temp directory writable', APP_TMP_DIR);
tests_add(ADMIN_PASSWORD !== '', 'Admin password configured', env_value('ADMIN_PASSWORD') ? 'from environment' : 'using bundled default - override ADMIN_PASSWORD in production');
tests_add(true, 'Public base URL', app_url());

// ------------------------------------------------------------------- files
$required = [
    'config.php', 'index.html', 'cpanel-admin-2025.php', '.htaccess',
    'qr.php', 'verify.php', 'login.php', 'create-key.php',
    'api/helpers.php', 'api/qr-generator.php', 'api/test-ui.php',
    'assets/fampay-logo.png', 'assets/fampay-logo-base64.txt',
    'migrations/001_initial_schema.sql', 'Dockerfile', 'render.yaml',
    'apache-config.conf', 'composer.json', 'README.md', 'DEPLOYMENT.md',
];
$missing = [];
foreach ($required as $file) {
    if (!is_file(__DIR__ . '/' . $file)) {
        $missing[] = $file;
    }
}
tests_add($missing === [], 'All project files present', $missing === [] ? count($required) . ' files verified' : 'missing: ' . implode(', ', $missing));
tests_add(is_file(__DIR__ . '/vendor/autoload.php'), 'Composer dependencies installed', is_file(__DIR__ . '/vendor/autoload.php') ? 'vendor/autoload.php found' : 'run composer install (QR falls back to the remote API without it)');

// ---------------------------------------------------------------- validation
$upiCases = [
    // valid VPAs
    'kankan1@fam' => true, 'merchant.store@ybl' => true, 'test-user_9@okaxis' => true,
    // invalid: PSP handles never contain a dot, and these are malformed
    'no-at-sign' => false, '@fam' => false, 'user@' => false, 'user@fam.com' => false,
    "user@fam' OR 1=1" => false, '<script>@fam' => false, 'user@@fam' => false,
];
$bad = [];
foreach ($upiCases as $case => $expected) {
    if (is_valid_upi((string) $case) !== $expected) {
        $bad[] = (string) $case;
    }
}
tests_add($bad === [], 'UPI validation regex', $bad === [] ? count($upiCases) . ' cases correct' : 'wrong verdict for: ' . implode(', ', $bad));

$amountCases = ['1' => 1.0, '100' => 100.0, '99.99' => 99.99, '100000' => 100000.0, '1,000' => 1000.0,
                '0' => null, '0.5' => null, '100001' => null, 'abc' => null, '10 OR 1=1' => null, '-50' => null];
$bad = [];
foreach ($amountCases as $raw => $expected) {
    if (normalise_amount((string) $raw) !== $expected) {
        $bad[] = (string) $raw;
    }
}
tests_add($bad === [], 'Amount validation', $bad === [] ? count($amountCases) . ' cases correct' : 'wrong verdict for: ' . implode(', ', $bad));

$keys = [];
for ($i = 0; $i < 500; $i++) {
    $keys[] = random_key();
}
$allValid = count(array_filter($keys, static fn ($k) => is_valid_key($k))) === 500;
tests_add($allValid, 'API key generator (6 alphanumeric chars)', 'sample: ' . $keys[0] . ', unique in 500: ' . count(array_unique($keys)));

$orderId = generate_order_id();
tests_add(is_valid_order_id($orderId), 'Order ID format FAM + YYYYMMDD + 8', $orderId);
tests_add(!is_valid_order_id("FAM20250101' OR '1"), 'Order ID rejects injection payloads', "FAM20250101' OR '1");

$xss = clean_text('<script>alert("xss")</script>Rahul');
tests_add(!str_contains($xss, '<script'), 'XSS sanitiser strips tags', $xss);

$link = build_upi_deeplink('kankan1@fam', 100, $orderId);
tests_add(str_contains($link, 'pa=kankan1%40fam') && str_contains($link, 'am=100.00'), 'UPI deep link encoding', $link);

// ---------------------------------------------------------------- database
try {
    db_ensure_schema();
    $pdo = db();
    tests_add(true, 'Database reachable + schema ready', 'PostgreSQL ' . $pdo->query('SHOW server_version')->fetchColumn());

    $counts = [];
    foreach (['orders', 'api_keys', 'master_keys', 'payment_logs'] as $t) {
        $counts[] = $t . '=' . (int) $pdo->query("SELECT COUNT(*) FROM $t")->fetchColumn();
    }
    tests_add(true, 'Table row counts', implode(' ', $counts));
} catch (Throwable $e) {
    tests_add(false, 'Database reachable + schema ready', $e->getMessage());
}

// ---------------------------------------------------------------- QR pipeline
$qr = generate_fampay_qr(build_upi_deeplink('kankan1@fam', 100, $orderId), true);
tests_add($qr['png'] !== null, 'QR rendered', 'engine=' . $qr['engine'] . ' bytes=' . strlen((string) $qr['png']));
tests_add($qr['has_logo'], 'FamPay logo embedded', $qr['has_logo'] ? 'source=' . $qr['logo_source'] : (string) $qr['error']);
tests_add(is_string($qr['base64']) && str_starts_with((string) $qr['base64'], 'data:image/png;base64,'), 'Base64 data URI produced', substr((string) $qr['base64'], 0, 40) . '...');

// ---------------------------------------------------------------- IMAP parser
$parsed = parse_payment_email('You received Rs 100.00 on FamPay', 'You have received Rs 100.00 from John Doe (john@ybl) via UPI. UPI Ref No: 412345678901.');
tests_add(
    $parsed['amount'] === 100.0 && $parsed['utr'] === '412345678901' && $parsed['is_credit'],
    'Payment e-mail parser',
    sprintf('amount=%s utr=%s payer=%s', (string) $parsed['amount'], (string) $parsed['utr'], (string) $parsed['payer_name'])
);
tests_add(imap_available() ?: null, 'IMAP extension for live verification', imap_available() ? 'available' : 'not on this host - the Dockerfile installs ext-imap');

// ---------------------------------------------------------------- endpoints
$doHttp = in_array(strtolower((string) (param('http') ?? '0')), ['1', 'true', 'yes'], true);
if (!$doHttp) {
    tests_add(null, 'Live endpoint calls', 'skipped - append &http=1&admin_password=... to run them');
} else {
    $base = app_url();
    $call = static function (string $path) use ($base): array {
        $body = fampay_http_get($base . $path, 15);
        return ['raw' => $body, 'json' => $body !== null ? json_decode($body, true) : null];
    };

    // The PHP built-in dev server is single threaded and cannot answer a request
    // it makes to itself - detect that instead of reporting false failures.
    $probe = $call('/index.html');
    if ($probe['raw'] === null) {
        tests_add(null, 'Live endpoint calls', 'skipped - ' . $base . ' did not answer a loopback request. '
            . 'The PHP built-in dev server cannot serve its own request; run this check on Apache / Render.');
        tests_end();
    }

    $r = $call('/create-key.php?admin_password=wrong-password&key_name=Test');
    tests_add(is_array($r['json']) && ($r['json']['success'] ?? true) === false, 'create-key rejects a wrong password', substr((string) $r['raw'], 0, 120));

    $adminPassword = param('admin_password');
    if ($adminPassword === null) {
        tests_add(null, 'create-key issues a key', 'skipped - supply &admin_password=...');
    } else {
        $r = $call('/create-key.php?admin_password=' . rawurlencode($adminPassword) . '&key_name=SelfTest');
        $key = $r['json']['data']['api_key'] ?? null;
        tests_add(is_string($key) && is_valid_key($key), 'create-key issues a key', (string) $key);

        if (is_string($key) && is_valid_key($key)) {
            $r = $call('/qr.php?upi=kankan1@fam&amount=100&api_key=' . $key);
            $newOrder = $r['json']['data']['order_id'] ?? null;
            tests_add(
                is_string($newOrder) && is_valid_order_id($newOrder) && ($r['json']['data']['qr_code']['has_fampay_logo'] ?? false) === true,
                'qr.php creates an order with a logo QR',
                (string) $newOrder
            );

            $r = $call('/qr.php?upi=bad-upi&amount=100&api_key=' . $key);
            tests_add(($r['json']['error']['code'] ?? '') === 'INVALID_UPI', 'qr.php rejects an invalid UPI', (string) ($r['json']['error']['code'] ?? ''));

            $r = $call('/qr.php?upi=kankan1@fam&amount=999999999&api_key=' . $key);
            tests_add(($r['json']['error']['code'] ?? '') === 'INVALID_AMOUNT', 'qr.php rejects an out-of-range amount', (string) ($r['json']['error']['code'] ?? ''));

            $r = $call("/qr.php?upi=kankan1@fam&amount=100&api_key=" . rawurlencode("' OR '1'='1"));
            tests_add(($r['json']['error']['code'] ?? '') === 'INVALID_API_KEY', 'SQL injection in api_key blocked', (string) ($r['json']['error']['code'] ?? ''));

            if (is_string($newOrder)) {
                $r = $call('/verify.php?order_id=' . $newOrder . '&api_key=' . $key . '&gmail_key=ZZZZZZ');
                tests_add(($r['json']['error']['code'] ?? '') === 'INVALID_GMAIL_KEY', 'verify.php rejects an unknown gmail_key', (string) ($r['json']['error']['code'] ?? ''));
            }
        }
    }

    $r = $call('/qr.php?upi=kankan1@fam&amount=100');
    tests_add(($r['json']['error']['code'] ?? '') === 'MISSING_API_KEY', 'qr.php requires an api_key', (string) ($r['json']['error']['code'] ?? ''));

    $r = $call('/verify.php?order_id=FAM20250101ABCDEFGH&api_key=ABCDEF');
    tests_add(in_array(($r['json']['error']['code'] ?? ''), ['INVALID_API_KEY', 'ORDER_NOT_FOUND'], true), 'verify.php validates before touching IMAP', (string) ($r['json']['error']['code'] ?? ''));
}

tests_end();
