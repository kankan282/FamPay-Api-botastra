<?php
/**
 * FamPay Payment Gateway - Helper library
 * Author: @lazzy_guy
 *
 * Contains: request handling, JSON responses, validation, key generation,
 * authentication, logging, rate limiting, and the Gmail IMAP payment scanner.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config.php';

// ---------------------------------------------------------------------------
// HTTP / response helpers
// ---------------------------------------------------------------------------

/** Send permissive CORS + JSON headers and short-circuit OPTIONS pre-flights. */
function api_boot(): void
{
    header('Content-Type: application/json; charset=utf-8');
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Api-Key');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
        http_response_code(204);
        exit;
    }
}

/** @param array<string,mixed> $payload */
function json_out(array $payload, int $status = 200): never
{
    if (!headers_sent()) {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

/** @param array<string,mixed> $data */
function api_success(array $data, int $status = 200): never
{
    json_out([
        'success'   => true,
        'data'      => $data,
        'timestamp' => date('Y-m-d H:i:s'),
    ], $status);
}

function api_error(string $message, int $status = 400, string $code = 'BAD_REQUEST', array $extra = []): never
{
    json_out(array_merge([
        'success'   => false,
        'error'     => [
            'code'    => $code,
            'message' => $message,
        ],
        'timestamp' => date('Y-m-d H:i:s'),
    ], $extra), $status);
}

/**
 * Fetch a parameter from the query string, the POST body or a JSON body.
 */
function param(string $name, ?string $default = null): ?string
{
    static $jsonBody = null;
    if ($jsonBody === null) {
        $jsonBody = [];
        $ctype = $_SERVER['CONTENT_TYPE'] ?? '';
        if (stripos($ctype, 'application/json') !== false) {
            $raw = file_get_contents('php://input');
            if (is_string($raw) && $raw !== '') {
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) {
                    $jsonBody = $decoded;
                }
            }
        }
    }

    foreach ([$_GET, $_POST, $jsonBody] as $source) {
        if (isset($source[$name]) && !is_array($source[$name])) {
            $value = trim((string) $source[$name]);
            if ($value !== '') {
                return $value;
            }
        }
    }
    return $default;
}

function client_ip(): string
{
    $candidates = [
        $_SERVER['HTTP_CF_CONNECTING_IP'] ?? null,
        isset($_SERVER['HTTP_X_FORWARDED_FOR']) ? explode(',', (string) $_SERVER['HTTP_X_FORWARDED_FOR'])[0] : null,
        $_SERVER['REMOTE_ADDR'] ?? null,
    ];
    foreach ($candidates as $ip) {
        $ip = trim((string) $ip);
        if ($ip !== '' && filter_var($ip, FILTER_VALIDATE_IP)) {
            return substr($ip, 0, 45);
        }
    }
    return '0.0.0.0';
}

// ---------------------------------------------------------------------------
// Sanitising / validation
// ---------------------------------------------------------------------------

function clean_text(?string $value, int $maxLength = 100): string
{
    $value = strip_tags((string) $value);
    $value = preg_replace('/[\x00-\x1F\x7F]/u', '', $value) ?? '';
    $value = trim($value);
    if (function_exists('mb_substr')) {
        $value = mb_substr($value, 0, $maxLength);
    } else {
        $value = substr($value, 0, $maxLength);
    }
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Strict UPI VPA validation, e.g. kankan1@fam */
function is_valid_upi(string $upi): bool
{
    if (strlen($upi) < 5 || strlen($upi) > 100) {
        return false;
    }
    return (bool) preg_match('/^[a-zA-Z0-9](?:[a-zA-Z0-9._\-]{0,60})@[a-zA-Z][a-zA-Z0-9]{1,20}$/', $upi);
}

/** @return float|null normalised amount or null when invalid */
function normalise_amount(?string $raw): ?float
{
    if ($raw === null) {
        return null;
    }
    $raw = str_replace([',', ' ', "\u{20B9}"], '', $raw);
    if (!preg_match('/^\d{1,8}(\.\d{1,2})?$/', $raw)) {
        return null;
    }
    $amount = round((float) $raw, 2);
    if ($amount < MIN_AMOUNT || $amount > MAX_AMOUNT) {
        return null;
    }
    return $amount;
}

function is_valid_key(string $key): bool
{
    return (bool) preg_match('/^[A-Za-z0-9]{' . API_KEY_LENGTH . '}$/', $key);
}

function is_valid_order_id(string $orderId): bool
{
    return (bool) preg_match('/^FAM\d{8}[A-Z0-9]{8}$/', $orderId);
}

function is_valid_gmail(string $email): bool
{
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 100) {
        return false;
    }
    return true;
}

// ---------------------------------------------------------------------------
// Identifier generation
// ---------------------------------------------------------------------------

function random_key(int $length = API_KEY_LENGTH): string
{
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';
    $max = strlen($alphabet) - 1;
    $out = '';
    for ($i = 0; $i < $length; $i++) {
        $out .= $alphabet[random_int(0, $max)];
    }
    return $out;
}

/** FAM + YYYYMMDD + 8 char hash, e.g. FAM20250105ABCD1234 */
function generate_order_id(): string
{
    return 'FAM' . date('Ymd') . strtoupper(substr(bin2hex(random_bytes(8)), 0, 8));
}

/**
 * Generate a key that does not collide with an existing row.
 *
 * @param string $table  master_keys | api_keys
 * @param string $column master_key  | api_key
 */
function unique_key(string $table, string $column): string
{
    $allowed = ['master_keys' => 'master_key', 'api_keys' => 'api_key'];
    if (!isset($allowed[$table]) || $allowed[$table] !== $column) {
        throw new InvalidArgumentException('Invalid key table/column.');
    }
    $pdo = db();
    for ($i = 0; $i < 25; $i++) {
        $key = random_key();
        $stmt = $pdo->prepare("SELECT 1 FROM {$table} WHERE {$column} = :k LIMIT 1");
        $stmt->execute([':k' => $key]);
        if ($stmt->fetchColumn() === false) {
            return $key;
        }
    }
    throw new RuntimeException('Unable to allocate a unique API key, please retry.');
}

// ---------------------------------------------------------------------------
// Secret storage (Gmail app passwords are encrypted at rest)
// ---------------------------------------------------------------------------

function encrypt_secret(string $plain): string
{
    if (!function_exists('openssl_encrypt')) {
        return 'plain:' . base64_encode($plain);
    }
    $iv = random_bytes(16);
    $key = hash('sha256', APP_SECRET, true);
    $cipher = openssl_encrypt($plain, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
    if ($cipher === false) {
        return 'plain:' . base64_encode($plain);
    }
    return 'enc:' . base64_encode($iv . $cipher);
}

function decrypt_secret(string $stored): string
{
    if (str_starts_with($stored, 'plain:')) {
        return (string) base64_decode(substr($stored, 6), true);
    }
    if (!str_starts_with($stored, 'enc:')) {
        return $stored; // legacy plaintext
    }
    $blob = base64_decode(substr($stored, 4), true);
    if ($blob === false || strlen($blob) <= 16 || !function_exists('openssl_decrypt')) {
        return '';
    }
    $iv = substr($blob, 0, 16);
    $cipher = substr($blob, 16);
    $key = hash('sha256', APP_SECRET, true);
    $plain = openssl_decrypt($cipher, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
    return $plain === false ? '' : $plain;
}

// ---------------------------------------------------------------------------
// Authentication
// ---------------------------------------------------------------------------

/**
 * Validate a master API key. Terminates the request on failure.
 *
 * @return array<string,mixed> master_keys row
 */
function require_master_key(?string $key): array
{
    if ($key === null || $key === '') {
        api_error('Missing required parameter: api_key', 401, 'MISSING_API_KEY');
    }
    if (!is_valid_key($key)) {
        api_error('Invalid api_key format (expected ' . API_KEY_LENGTH . ' alphanumeric characters).', 401, 'INVALID_API_KEY');
    }

    $stmt = db()->prepare('SELECT * FROM master_keys WHERE master_key = :k LIMIT 1');
    $stmt->execute([':k' => $key]);
    $row = $stmt->fetch();

    if (!$row) {
        api_error('Invalid API key.', 401, 'INVALID_API_KEY');
    }
    if (!to_bool($row['is_active'])) {
        api_error('This API key is disabled.', 403, 'API_KEY_DISABLED');
    }

    $upd = db()->prepare('UPDATE master_keys SET usage_count = usage_count + 1, last_used = CURRENT_TIMESTAMP WHERE id = :id');
    $upd->execute([':id' => $row['id']]);

    return $row;
}

/**
 * Validate a Gmail key. Terminates the request on failure.
 *
 * @return array<string,mixed> api_keys row
 */
function require_gmail_key(?string $key): array
{
    if ($key === null || $key === '') {
        api_error('Missing required parameter: gmail_key', 401, 'MISSING_GMAIL_KEY');
    }
    if (!is_valid_key($key)) {
        api_error('Invalid gmail_key format (expected ' . API_KEY_LENGTH . ' alphanumeric characters).', 401, 'INVALID_GMAIL_KEY');
    }

    $stmt = db()->prepare('SELECT * FROM api_keys WHERE api_key = :k LIMIT 1');
    $stmt->execute([':k' => $key]);
    $row = $stmt->fetch();

    if (!$row) {
        api_error('Invalid Gmail key.', 401, 'INVALID_GMAIL_KEY');
    }
    if (!to_bool($row['is_active'])) {
        api_error('This Gmail connection is disabled.', 403, 'GMAIL_KEY_DISABLED');
    }

    $upd = db()->prepare('UPDATE api_keys SET last_used = CURRENT_TIMESTAMP WHERE id = :id');
    $upd->execute([':id' => $row['id']]);

    return $row;
}

/** PostgreSQL booleans arrive as bool, 't'/'f' or 1/0 depending on driver build. */
function to_bool(mixed $value): bool
{
    if (is_bool($value)) {
        return $value;
    }
    if (is_int($value)) {
        return $value === 1;
    }
    $value = strtolower(trim((string) $value));
    return in_array($value, ['t', 'true', '1', 'yes', 'on'], true);
}

function hash_equals_password(string $provided, string $expected): bool
{
    return hash_equals($expected, $provided);
}

// ---------------------------------------------------------------------------
// Logging
// ---------------------------------------------------------------------------

function log_action(string $orderId, string $action, ?string $apiKey = null, mixed $request = null, mixed $response = null): void
{
    try {
        $stmt = db()->prepare(
            'INSERT INTO payment_logs (order_id, api_key, action, request_data, response_data, ip_address)
             VALUES (:oid, :key, :action, :req, :res, :ip)'
        );
        $stmt->execute([
            ':oid'    => substr($orderId, 0, 50),
            ':key'    => $apiKey !== null ? substr($apiKey, 0, 64) : null,
            ':action' => substr($action, 0, 50),
            ':req'    => $request === null ? null : (is_string($request) ? $request : json_encode($request)),
            ':res'    => $response === null ? null : (is_string($response) ? $response : json_encode($response)),
            ':ip'     => client_ip(),
        ]);
    } catch (Throwable $e) {
        error_log('[fampay] log_action failed: ' . $e->getMessage());
    }
}

// ---------------------------------------------------------------------------
// Rate limiting (file based, works on Render's ephemeral disk)
// ---------------------------------------------------------------------------

function rate_limit(string $bucket, ?int $limit = null, ?int $window = null): void
{
    $limit  = $limit ?? RATE_LIMIT_REQUESTS;
    $window = $window ?? RATE_LIMIT_WINDOW;
    if ($limit <= 0) {
        return;
    }

    $dir = APP_TMP_DIR . '/ratelimit';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    $file = $dir . '/' . sha1($bucket . '|' . client_ip()) . '.json';

    $now = time();
    $state = ['start' => $now, 'count' => 0];
    if (is_file($file)) {
        $decoded = json_decode((string) @file_get_contents($file), true);
        if (is_array($decoded) && isset($decoded['start'], $decoded['count'])) {
            $state = ['start' => (int) $decoded['start'], 'count' => (int) $decoded['count']];
        }
    }
    if (($now - $state['start']) >= $window) {
        $state = ['start' => $now, 'count' => 0];
    }
    $state['count']++;
    @file_put_contents($file, json_encode($state), LOCK_EX);

    if ($state['count'] > $limit) {
        $retry = max(1, $window - ($now - $state['start']));
        if (!headers_sent()) {
            header('Retry-After: ' . $retry);
        }
        api_error('Rate limit exceeded. Try again in ' . $retry . ' seconds.', 429, 'RATE_LIMITED');
    }
}

// ---------------------------------------------------------------------------
// Orders
// ---------------------------------------------------------------------------

/** Flip pending orders older than the expiry window to "expired". */
function expire_stale_orders(): int
{
    try {
        $stmt = db()->prepare(
            "UPDATE orders SET status = 'expired'
             WHERE status = 'pending'
               AND created_at < (CURRENT_TIMESTAMP - (:mins || ' minutes')::interval)"
        );
        $stmt->execute([':mins' => (string) ORDER_EXPIRY_MINUTES]);
        return $stmt->rowCount();
    } catch (Throwable $e) {
        error_log('[fampay] expire_stale_orders failed: ' . $e->getMessage());
        return 0;
    }
}

/** @return array<string,mixed>|null */
function find_order(string $orderId): ?array
{
    $stmt = db()->prepare('SELECT * FROM orders WHERE order_id = :oid LIMIT 1');
    $stmt->execute([':oid' => $orderId]);
    $row = $stmt->fetch();
    return $row === false ? null : $row;
}

function order_expiry_time(array $order): int
{
    return strtotime((string) $order['created_at']) + (ORDER_EXPIRY_MINUTES * 60);
}

/** Build the UPI deep link for a payment. */
function build_upi_deeplink(string $upi, float $amount, string $orderId, ?string $note = null): string
{
    $params = [
        'pa' => $upi,
        'pn' => QR_MERCHANT_NAME,
        'am' => number_format($amount, 2, '.', ''),
        'cu' => 'INR',
        'tn' => $note ?? ('Order ' . $orderId),
        'tr' => $orderId,
    ];
    return 'upi://pay?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
}

// ---------------------------------------------------------------------------
// Gmail IMAP - connection + payment e-mail parsing
// ---------------------------------------------------------------------------

function imap_available(): bool
{
    return function_exists('imap_open');
}

/**
 * Open an IMAP stream to Gmail.
 *
 * @return array{ok:bool,stream:mixed,error:string|null}
 */
function gmail_connect(string $gmail, string $appPassword, int $timeoutSeconds = 20): array
{
    if (!imap_available()) {
        return ['ok' => false, 'stream' => null, 'error' => 'PHP IMAP extension is not installed on this server.'];
    }

    // App passwords are frequently pasted with spaces - Gmail ignores them.
    $appPassword = str_replace(' ', '', $appPassword);

    @imap_timeout(IMAP_OPENTIMEOUT, $timeoutSeconds);
    @imap_timeout(IMAP_READTIMEOUT, $timeoutSeconds);

    $mailbox = '{imap.gmail.com:993/imap/ssl/novalidate-cert}INBOX';
    $stream = @imap_open($mailbox, $gmail, $appPassword, 0, 1);

    if ($stream === false) {
        $errors = @imap_errors();
        $message = is_array($errors) && $errors ? implode('; ', array_slice($errors, -2)) : 'IMAP login failed.';
        if (stripos($message, 'Invalid credentials') !== false || stripos($message, 'AUTHENTICATIONFAILED') !== false) {
            $message = 'Gmail rejected the credentials. Use a 16-character App Password with 2-Step Verification enabled.';
        }
        return ['ok' => false, 'stream' => null, 'error' => $message];
    }

    return ['ok' => true, 'stream' => $stream, 'error' => null];
}

/** Decode an IMAP MIME body part into UTF-8 text. */
function imap_decode_part(string $body, int $encoding): string
{
    $decoded = match ($encoding) {
        3       => base64_decode($body, false) ?: '',
        4       => quoted_printable_decode($body),
        default => $body,
    };
    if (function_exists('mb_check_encoding') && !mb_check_encoding($decoded, 'UTF-8')) {
        $converted = @mb_convert_encoding($decoded, 'UTF-8', 'ISO-8859-1');
        if (is_string($converted)) {
            $decoded = $converted;
        }
    }
    return $decoded;
}

/** Recursively collect the plain text of an IMAP message. */
function imap_message_text(mixed $stream, int $messageNumber): string
{
    $structure = @imap_fetchstructure($stream, $messageNumber);
    if (!$structure) {
        return (string) @imap_body($stream, $messageNumber);
    }

    if (empty($structure->parts)) {
        $body = (string) @imap_body($stream, $messageNumber);
        return imap_decode_part($body, (int) ($structure->encoding ?? 0));
    }

    $text = '';
    $walk = function ($parts, string $prefix) use (&$walk, $stream, $messageNumber, &$text): void {
        foreach ($parts as $index => $part) {
            $section = $prefix === '' ? (string) ($index + 1) : $prefix . '.' . ($index + 1);
            $subtype = strtoupper((string) ($part->subtype ?? ''));
            if ((int) ($part->type ?? 0) === 0 && in_array($subtype, ['PLAIN', 'HTML'], true)) {
                $raw = (string) @imap_fetchbody($stream, $messageNumber, $section);
                $text .= "\n" . imap_decode_part($raw, (int) ($part->encoding ?? 0));
            }
            if (!empty($part->parts)) {
                $walk($part->parts, $section);
            }
        }
    };
    $walk($structure->parts, '');

    return $text;
}

/** Convert HTML e-mail bodies to readable text. */
function html_to_text(string $html): string
{
    $text = preg_replace('#<(script|style)\b[^>]*>.*?</\1>#is', ' ', $html) ?? $html;
    $text = preg_replace('#<(br|/tr|/p|/div|/td)[^>]*>#i', "\n", $text) ?? $text;
    $text = strip_tags($text);
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = str_replace(["\xc2\xa0", "\u{20B9}"], [' ', 'Rs '], $text);
    $text = preg_replace('/[ \t]+/', ' ', $text) ?? $text;
    return trim(preg_replace('/\n{2,}/', "\n", $text) ?? $text);
}

/**
 * Extract UPI payment facts from an e-mail. Pure function - unit tested.
 *
 * @return array{amount:float|null,utr:string|null,payer_name:string|null,payer_upi:string|null,is_credit:bool}
 */
function parse_payment_email(string $subject, string $body): array
{
    $text = html_to_text($subject . "\n" . $body);
    $flat = preg_replace('/\s+/', ' ', $text) ?? $text;

    // --- amount -----------------------------------------------------------
    $amount = null;
    $amountPatterns = [
        '/(?:Rs\.?|INR|₹)\s*([0-9][0-9,]*(?:\.[0-9]{1,2})?)/i',
        '/\b([0-9][0-9,]*(?:\.[0-9]{1,2})?)\s*(?:Rs\.?|INR|rupees)\b/i',
        '/amount(?:\s+of)?\s*[:\-]?\s*(?:Rs\.?|INR|₹)?\s*([0-9][0-9,]*(?:\.[0-9]{1,2})?)/i',
    ];
    foreach ($amountPatterns as $pattern) {
        if (preg_match($pattern, $flat, $m)) {
            $amount = (float) str_replace(',', '', $m[1]);
            break;
        }
    }

    // --- UTR / reference number -------------------------------------------
    $utr = null;
    $utrPatterns = [
        '/\b(?:UTR|RRN|UPI\s*Ref(?:erence)?(?:\s*(?:No\.?|Number|ID))?|Ref(?:erence)?\s*(?:No\.?|Number|ID)|Transaction\s*(?:ID|Ref(?:erence)?\s*(?:No\.?|ID)?)|Txn\s*(?:ID|No\.?))\s*[:\-#is]{0,3}\s*([A-Za-z0-9]{9,23})\b/i',
        '/\b(\d{12})\b/',
    ];
    foreach ($utrPatterns as $pattern) {
        if (preg_match($pattern, $flat, $m)) {
            $utr = strtoupper($m[1]);
            break;
        }
    }

    // --- payer UPI --------------------------------------------------------
    $payerUpi = null;
    if (preg_match('/\bfrom\s+[^\n]{0,60}?\(?([a-zA-Z0-9][a-zA-Z0-9._\-]{1,60}@[a-zA-Z][a-zA-Z0-9]{1,20})\)?/i', $flat, $m)) {
        $payerUpi = $m[1];
    } elseif (preg_match_all('/\b([a-zA-Z0-9][a-zA-Z0-9._\-]{1,60}@[a-zA-Z][a-zA-Z0-9]{1,20})\b/', $flat, $all)) {
        foreach ($all[1] as $candidate) {
            // ignore mail addresses of the notification sender
            if (preg_match('/\.(com|in|org|net|co)$/i', $candidate)) {
                continue;
            }
            $payerUpi = $candidate;
            break;
        }
    }

    // --- payer name -------------------------------------------------------
    // Matched against the line-preserved text so a name never bleeds into the
    // next sentence/line of the e-mail (e.g. "... from Priya Nair\nTransaction ID: ...").
    $payerName = null;
    $namePatterns = [
        '/(?:received|credited|payment|money)\b[^\n]{0,40}?\bfrom\s+([A-Z][A-Za-z\.\' ]{1,48}?)(?=\s*(?:\(|,|\.|:|-|via\b|on\b|through\b|to\b|using\b|with\b|UPI\b|Rs\b|INR\b|Transaction\b|Txn\b|Ref\b|UTR\b|$))/m',
        '/\bfrom\s+([A-Z][A-Za-z\.\' ]{1,48}?)(?=\s*(?:\(|,|\.|:|-|via\b|on\b|through\b|to\b|using\b|with\b|UPI\b|Rs\b|INR\b|Transaction\b|Txn\b|Ref\b|UTR\b|$))/m',
        '/\bsender\s*(?:name)?\s*[:\-]\s*([A-Za-z\.\' ]{2,48})/im',
        '/\bpaid\s+by\s+([A-Za-z\.\' ]{2,48})/im',
    ];
    foreach ($namePatterns as $pattern) {
        if (preg_match($pattern, $text, $m)) {
            $candidate = trim(preg_replace('/\s+/', ' ', $m[1]) ?? '');
            if ($candidate !== '' && !preg_match('/^(your|the|an?|upi|fampay)$/i', $candidate)) {
                $payerName = $candidate;
                break;
            }
        }
    }

    // --- credit vs debit --------------------------------------------------
    $isCredit = (bool) preg_match(
        '/\b(received|credited|credit|has been added|added to your|money in|payment of|paid you|successfully received)\b/i',
        $flat
    );
    if (preg_match('/\b(debited|debit|you paid|sent to|withdrawn)\b/i', $flat) && !preg_match('/\b(received|credited)\b/i', $flat)) {
        $isCredit = false;
    }

    return [
        'amount'     => $amount,
        'utr'        => $utr,
        'payer_name' => $payerName !== null ? clean_text($payerName, 100) : null,
        'payer_upi'  => $payerUpi !== null ? substr($payerUpi, 0, 100) : null,
        'is_credit'  => $isCredit,
    ];
}

/**
 * Scan the inbox for a credit matching the given order.
 *
 * @param array<string,mixed> $order
 * @return array{found:bool,error:string|null,scanned:int,payment:array<string,mixed>|null}
 */
function scan_inbox_for_payment(string $gmail, string $appPassword, array $order): array
{
    $result = ['found' => false, 'error' => null, 'scanned' => 0, 'payment' => null];

    $conn = gmail_connect($gmail, $appPassword);
    if (!$conn['ok']) {
        $result['error'] = $conn['error'];
        return $result;
    }
    $stream = $conn['stream'];

    try {
        $createdAt = strtotime((string) $order['created_at']) ?: time();
        $sinceDate = date('j-M-Y', $createdAt - 86400);
        $expected  = round((float) $order['amount'], 2);

        $ids = @imap_search($stream, 'SINCE "' . $sinceDate . '"', SE_UID);
        if (!is_array($ids) || $ids === []) {
            return $result;
        }

        rsort($ids);
        $ids = array_slice($ids, 0, 60); // newest 60 messages is plenty for a 15 min window

        foreach ($ids as $uid) {
            $msgNo = @imap_msgno($stream, (int) $uid);
            if (!$msgNo) {
                continue;
            }
            $headers = @imap_headerinfo($stream, $msgNo);
            if (!$headers) {
                continue;
            }
            $result['scanned']++;

            $mailTime = isset($headers->udate) ? (int) $headers->udate : 0;
            if ($mailTime > 0 && $mailTime < ($createdAt - 300)) {
                continue; // older than the order (5 min grace for clock skew)
            }

            $subject = '';
            if (!empty($headers->subject)) {
                foreach ((array) imap_mime_header_decode($headers->subject) as $chunk) {
                    $subject .= $chunk->text;
                }
            }

            $body = imap_message_text($stream, $msgNo);
            $haystack = strtolower($subject . ' ' . $body);
            if (!str_contains($haystack, 'upi') && !str_contains($haystack, 'fampay')
                && !str_contains($haystack, 'received') && !str_contains($haystack, 'credited')) {
                continue;
            }

            $parsed = parse_payment_email($subject, $body);
            if (!$parsed['is_credit'] || $parsed['amount'] === null) {
                continue;
            }
            if (abs($parsed['amount'] - $expected) > 0.009) {
                continue;
            }

            // A UTR may only settle one order.
            if ($parsed['utr'] !== null) {
                $dupe = db()->prepare('SELECT order_id FROM orders WHERE utr_number = :utr AND order_id <> :oid LIMIT 1');
                $dupe->execute([':utr' => $parsed['utr'], ':oid' => (string) $order['order_id']]);
                if ($dupe->fetchColumn() !== false) {
                    continue;
                }
            }

            $result['found'] = true;
            $result['payment'] = [
                'utr'          => $parsed['utr'] ?? ('NOUTR' . strtoupper(substr(sha1((string) $uid . $gmail), 0, 10))),
                'payer_name'   => $parsed['payer_name'],
                'payer_upi'    => $parsed['payer_upi'],
                'amount'       => $parsed['amount'],
                'payment_date' => date('Y-m-d H:i:s', $mailTime ?: time()),
                'subject'      => clean_text($subject, 200),
                'mail_uid'     => (int) $uid,
            ];
            break;
        }
    } catch (Throwable $e) {
        $result['error'] = 'IMAP scan failed: ' . $e->getMessage();
    } finally {
        if ($stream) {
            @imap_close($stream);
        }
        if (function_exists('imap_errors')) {
            @imap_errors();
            @imap_alerts();
        }
    }

    return $result;
}

// ---------------------------------------------------------------------------
// CSRF (admin panel)
// ---------------------------------------------------------------------------

function csrf_token(): string
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return (string) $_SESSION['csrf_token'];
}

function csrf_valid(?string $token): bool
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    return is_string($token) && !empty($_SESSION['csrf_token']) && hash_equals((string) $_SESSION['csrf_token'], $token);
}
