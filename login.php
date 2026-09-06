<?php
/**
 * GET|POST /login.php?gmail=user@gmail.com&app_password=xxxx&api_key=Ab3xKm
 *
 * Verifies the Gmail App Password over IMAP and issues a 6 character gmail_key.
 * Re-logging in with the same address refreshes the stored password and
 * returns the existing key.
 */

declare(strict_types=1);

require_once __DIR__ . '/api/helpers.php';

api_boot();

try {
    rate_limit('login', 10, 60);

    db_ensure_schema();
    $master = require_master_key(param('api_key'));

    $gmail = strtolower((string) (param('gmail') ?? param('email') ?? ''));
    if ($gmail === '') {
        api_error('Missing required parameter: gmail', 400, 'MISSING_PARAMETER');
    }
    if (!is_valid_gmail($gmail)) {
        api_error('Invalid e-mail address.', 422, 'INVALID_EMAIL');
    }

    $appPassword = (string) (param('app_password') ?? param('password') ?? '');
    if ($appPassword === '') {
        api_error('Missing required parameter: app_password', 400, 'MISSING_PARAMETER');
    }
    $appPassword = str_replace(' ', '', $appPassword);
    if (strlen($appPassword) < 8 || strlen($appPassword) > 64) {
        api_error('Invalid app_password. Google App Passwords are 16 characters.', 422, 'INVALID_APP_PASSWORD');
    }

    if (!imap_available()) {
        api_error(
            'The PHP IMAP extension is not enabled on this server. Deploy with the bundled Dockerfile, which installs ext-imap.',
            503,
            'IMAP_UNAVAILABLE'
        );
    }

    $conn = gmail_connect($gmail, $appPassword);
    if (!$conn['ok']) {
        log_action('SYSTEM', 'gmail_login_failed', (string) $master['master_key'], ['gmail' => $gmail], $conn['error']);
        api_error((string) $conn['error'], 401, 'IMAP_LOGIN_FAILED');
    }

    $mailboxInfo = @imap_check($conn['stream']);
    $totalMessages = $mailboxInfo && isset($mailboxInfo->Nmsgs) ? (int) $mailboxInfo->Nmsgs : 0;
    @imap_close($conn['stream']);
    @imap_errors();

    $pdo = db();
    $stmt = $pdo->prepare('SELECT * FROM api_keys WHERE gmail = :g LIMIT 1');
    $stmt->execute([':g' => $gmail]);
    $existing = $stmt->fetch();

    $encrypted = encrypt_secret($appPassword);

    if ($existing) {
        $gmailKey = (string) $existing['api_key'];
        $upd = $pdo->prepare(
            'UPDATE api_keys SET app_password = :pw, is_active = TRUE, last_used = CURRENT_TIMESTAMP WHERE id = :id'
        );
        $upd->execute([':pw' => $encrypted, ':id' => $existing['id']]);
        $created = false;
    } else {
        $gmailKey = unique_key('api_keys', 'api_key');
        $ins = $pdo->prepare(
            'INSERT INTO api_keys (api_key, gmail, app_password, is_active, last_used)
             VALUES (:k, :g, :pw, TRUE, CURRENT_TIMESTAMP)'
        );
        $ins->execute([':k' => $gmailKey, ':g' => $gmail, ':pw' => $encrypted]);
        $created = true;
    }

    log_action('SYSTEM', 'gmail_login', (string) $master['master_key'], ['gmail' => $gmail], ['gmail_key' => $gmailKey]);

    api_success([
        'gmail_key'      => $gmailKey,
        'gmail'          => $gmail,
        'status'         => 'active',
        'inbox_messages' => $totalMessages,
        'new_connection' => $created,
        'verify_url'     => app_url() . '/verify.php?order_id=YOUR_ORDER_ID&api_key='
            . rawurlencode((string) $master['master_key']) . '&gmail_key=' . $gmailKey,
    ], $created ? 201 : 200);
} catch (RuntimeException $e) {
    api_error($e->getMessage(), 503, 'SERVICE_UNAVAILABLE');
} catch (Throwable $e) {
    error_log('[fampay] login.php: ' . $e->getMessage());
    api_error('Internal server error during Gmail login.', 500, 'INTERNAL_ERROR');
}
