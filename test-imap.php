<?php
/**
 * /test-imap.php - IMAP extension check, optional live Gmail login and
 * payment e-mail parser validation.
 *
 * Live login:  /test-imap.php?gmail=you@gmail.com&app_password=xxxxxxxxxxxxxxxx
 * (credentials are used once and never stored by this page)
 */

declare(strict_types=1);

require_once __DIR__ . '/api/test-ui.php';

tests_begin('IMAP / Gmail Test', 'Extension availability, credential handling and payment e-mail parsing.');

tests_add(imap_available(), 'PHP IMAP extension loaded', imap_available() ? 'imap_open() available' : 'not installed - deploy with the bundled Dockerfile');
tests_add(function_exists('openssl_encrypt'), 'OpenSSL available for credential encryption', function_exists('openssl_encrypt') ? 'aes-256-cbc' : 'missing, passwords fall back to base64');

// --- encryption round trip ---------------------------------------------------
$secret = 'abcd efgh ijkl mnop';
$enc = encrypt_secret(str_replace(' ', '', $secret));
$dec = decrypt_secret($enc);
tests_add($dec === 'abcdefghijklmnop', 'App password encrypt/decrypt round trip', 'stored as: ' . substr($enc, 0, 22) . '...');

// --- parser tests ------------------------------------------------------------
$samples = [
    [
        'name'    => 'FamPay credit alert',
        'subject' => 'You received Rs 100.00 on FamPay',
        'body'    => 'Hi, You have received Rs 100.00 from John Doe (john@ybl) via UPI. UPI Ref No: 412345678901. Your FamPay balance is updated.',
        'expect'  => ['amount' => 100.0, 'utr' => '412345678901', 'payer_upi' => 'john@ybl', 'credit' => true],
    ],
    [
        'name'    => 'Bank UPI credit',
        'subject' => 'INR 2,500.50 credited to your account',
        'body'    => 'Dear Customer, INR 2,500.50 has been credited to your account from RAHUL SHARMA. UTR: 987654321012. UPI ID: rahul@okhdfcbank.',
        'expect'  => ['amount' => 2500.50, 'utr' => '987654321012', 'credit' => true],
    ],
    [
        'name'    => 'HTML body with entities',
        'subject' => 'Payment received',
        'body'    => '<html><body><p>You have <b>received</b> &#8377;750 from Priya Nair</p><p>Transaction ID: HDFC0012345678</p></body></html>',
        'expect'  => ['amount' => 750.0, 'utr' => 'HDFC0012345678', 'credit' => true],
    ],
    [
        'name'    => 'Debit alert must not count as credit',
        'subject' => 'Rs 300 debited from your account',
        'body'    => 'You paid Rs 300 to Amazon via UPI. Txn ID: 555566667777.',
        'expect'  => ['credit' => false],
    ],
];

foreach ($samples as $sample) {
    $parsed = parse_payment_email($sample['subject'], $sample['body']);
    $problems = [];
    foreach ($sample['expect'] as $field => $expected) {
        $actual = match ($field) {
            'credit' => $parsed['is_credit'],
            default  => $parsed[$field] ?? null,
        };
        if (is_float($expected)) {
            if ($actual === null || abs((float) $actual - $expected) > 0.009) {
                $problems[] = "$field expected $expected got " . var_export($actual, true);
            }
        } elseif ($actual !== $expected) {
            $problems[] = "$field expected " . var_export($expected, true) . ' got ' . var_export($actual, true);
        }
    }
    tests_add(
        $problems === [],
        'Parser: ' . $sample['name'],
        $problems === []
            ? sprintf('amount=%s utr=%s payer=%s upi=%s credit=%s', (string) $parsed['amount'], (string) $parsed['utr'], (string) $parsed['payer_name'], (string) $parsed['payer_upi'], $parsed['is_credit'] ? 'yes' : 'no')
            : implode('; ', $problems)
    );
}

// --- optional live login -----------------------------------------------------
$gmail = param('gmail');
$appPassword = param('app_password');

if ($gmail === null || $appPassword === null) {
    tests_add(null, 'Live Gmail IMAP login', 'skipped - call /test-imap.php?gmail=you@gmail.com&app_password=xxxxxxxxxxxxxxxx to test real credentials');
} elseif (!imap_available()) {
    tests_add(false, 'Live Gmail IMAP login', 'IMAP extension missing on this host');
} elseif (!is_valid_gmail($gmail)) {
    tests_add(false, 'Live Gmail IMAP login', 'invalid e-mail address supplied');
} else {
    $conn = gmail_connect($gmail, $appPassword, 20);
    if (!$conn['ok']) {
        tests_add(false, 'Live Gmail IMAP login', (string) $conn['error']);
    } else {
        $check = @imap_check($conn['stream']);
        tests_add(true, 'Live Gmail IMAP login', 'connected as ' . $gmail . ' - ' . ($check->Nmsgs ?? 0) . ' messages in INBOX');

        $since = date('j-M-Y', time() - 86400 * 7);
        $ids = @imap_search($conn['stream'], 'SINCE "' . $since . '"', SE_UID);
        tests_add(true, 'IMAP search (last 7 days)', is_array($ids) ? count($ids) . ' message(s) found' : '0 messages found');

        @imap_close($conn['stream']);
        @imap_errors();
    }
}

// --- graceful failure handling ----------------------------------------------
if (imap_available()) {
    $bad = gmail_connect('definitely-not-a-real-account-9182@gmail.com', 'wrongpassword123', 8);
    tests_add(!$bad['ok'] && $bad['error'] !== null, 'Invalid credentials fail gracefully', (string) $bad['error']);
} else {
    $bad = gmail_connect('x@gmail.com', 'y', 5);
    tests_add(!$bad['ok'], 'Missing extension handled gracefully', (string) $bad['error']);
}

tests_end();
