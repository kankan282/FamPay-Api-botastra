<?php
/**
 * FamPay Payment Gateway - Central Configuration
 * Version : 2.0
 * Author  : @lazzy_guy (Telegram)
 *
 * Every sensitive value is read from environment variables (Render dashboard /
 * render.yaml). Sane defaults are provided for local development only.
 */

declare(strict_types=1);

if (defined('FAMPAY_CONFIG_LOADED')) {
    return;
}
define('FAMPAY_CONFIG_LOADED', true);

// ---------------------------------------------------------------------------
// Error handling: never leak stack traces to the client, always log them.
// ---------------------------------------------------------------------------
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

date_default_timezone_set(getenv('APP_TIMEZONE') ?: 'Asia/Kolkata');

/**
 * Read an environment variable with a fallback.
 */
function env_value(string $key, ?string $default = null): ?string
{
    $value = getenv($key);
    if ($value === false || $value === '') {
        if (isset($_ENV[$key]) && $_ENV[$key] !== '') {
            $value = (string) $_ENV[$key];
        } elseif (isset($_SERVER[$key]) && $_SERVER[$key] !== '') {
            $value = (string) $_SERVER[$key];
        } else {
            return $default;
        }
    }
    return (string) $value;
}

// ---------------------------------------------------------------------------
// Load a local .env file when present (local development convenience).
// ---------------------------------------------------------------------------
(function (): void {
    $envFile = __DIR__ . '/.env';
    if (!is_file($envFile) || !is_readable($envFile)) {
        return;
    }
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
            continue;
        }
        [$k, $v] = explode('=', $line, 2);
        $k = trim($k);
        $v = trim($v);
        if (strlen($v) > 1 && ($v[0] === '"' || $v[0] === "'") && $v[0] === substr($v, -1)) {
            $v = substr($v, 1, -1);
        }
        if ($k !== '' && getenv($k) === false) {
            putenv("$k=$v");
            $_ENV[$k] = $v;
        }
    }
})();

// ---------------------------------------------------------------------------
// Application constants
// ---------------------------------------------------------------------------
define('APP_NAME', 'FamPay Payment Gateway');
define('APP_VERSION', '2.0');
define('APP_DEVELOPER', '@lazzy_guy');
define('APP_TELEGRAM', 'https://t.me/lazzy_guy');

define('ADMIN_PASSWORD', env_value('ADMIN_PASSWORD', 'kankan201028'));
define('ADMIN_PANEL_PATH', '/cpanel-admin-2025');

/** Secret used to encrypt Gmail app passwords at rest. */
define('APP_SECRET', env_value('APP_SECRET', 'fampay-' . ADMIN_PASSWORD . '-v2'));

define('ORDER_EXPIRY_MINUTES', (int) (env_value('ORDER_EXPIRY_MINUTES', '15')));
define('API_KEY_LENGTH', 6);
define('MIN_AMOUNT', 1.0);
define('MAX_AMOUNT', 100000.0);

define('QR_SIZE', (int) env_value('QR_SIZE', '400'));
define('QR_LOGO_SIZE', (int) env_value('QR_LOGO_SIZE', '92'));       // 23% of 400
define('QR_LOGO_BG_SIZE', (int) env_value('QR_LOGO_BG_SIZE', '112')); // white badge
// Badge shape behind the logo: 'rounded' (square/photo logos) or 'circle'
define('QR_LOGO_SHAPE', strtolower((string) env_value('QR_LOGO_SHAPE', 'rounded')) === 'circle' ? 'circle' : 'rounded');
define('QR_MERCHANT_NAME', env_value('QR_MERCHANT_NAME', 'FamPay'));

/** Rate limiting (per IP). */
define('RATE_LIMIT_REQUESTS', (int) env_value('RATE_LIMIT_REQUESTS', '60'));
define('RATE_LIMIT_WINDOW', (int) env_value('RATE_LIMIT_WINDOW', '60'));

/** Writable scratch directory (Render containers allow /tmp). */
define('APP_TMP_DIR', env_value('APP_TMP_DIR', sys_get_temp_dir() . '/fampay-gateway'));
if (!is_dir(APP_TMP_DIR)) {
    @mkdir(APP_TMP_DIR, 0775, true);
}

/**
 * Public base URL of the deployment. Auto-detected when APP_URL is not set.
 */
function app_url(): string
{
    static $url = null;
    if ($url !== null) {
        return $url;
    }
    $configured = env_value('APP_URL');
    if ($configured) {
        return $url = rtrim($configured, '/');
    }
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
        || ((int) ($_SERVER['SERVER_PORT'] ?? 80) === 443);
    $host = $_SERVER['HTTP_X_FORWARDED_HOST'] ?? $_SERVER['HTTP_HOST'] ?? 'localhost';
    $host = preg_replace('/[^A-Za-z0-9\.\-:_]/', '', (string) $host);
    return $url = ($https ? 'https://' : 'http://') . $host;
}

// ---------------------------------------------------------------------------
// Database (PostgreSQL on Render)
// ---------------------------------------------------------------------------

/**
 * Build the PDO DSN + credentials from either DATABASE_URL or DB_* variables.
 *
 * @return array{dsn:string,user:string,pass:string,name:string,host:string}
 */
function db_config(): array
{
    $databaseUrl = env_value('DATABASE_URL');
    if ($databaseUrl) {
        $parts = parse_url($databaseUrl);
        if ($parts !== false && isset($parts['host'])) {
            $host = $parts['host'];
            $port = (string) ($parts['port'] ?? 5432);
            $name = ltrim($parts['path'] ?? '/postgres', '/');
            $user = urldecode($parts['user'] ?? 'postgres');
            $pass = urldecode($parts['pass'] ?? '');
            $sslmode = env_value('DB_SSLMODE', 'prefer');
            return [
                'dsn'  => "pgsql:host=$host;port=$port;dbname=$name;sslmode=$sslmode",
                'user' => $user,
                'pass' => $pass,
                'name' => $name,
                'host' => $host,
            ];
        }
    }

    $host = env_value('DB_HOST', 'localhost');
    $port = env_value('DB_PORT', '5432');
    $name = env_value('DB_NAME', 'fampay');
    $user = env_value('DB_USER', 'fampay_user');
    $pass = env_value('DB_PASS', '');
    $sslmode = env_value('DB_SSLMODE', 'prefer');

    return [
        'dsn'  => "pgsql:host=$host;port=$port;dbname=$name;sslmode=$sslmode",
        'user' => (string) $user,
        'pass' => (string) $pass,
        'name' => (string) $name,
        'host' => (string) $host,
    ];
}

/**
 * Shared PDO handle. Throws RuntimeException on failure (never fatals).
 */
function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    if (!in_array('pgsql', PDO::getAvailableDrivers(), true)) {
        throw new RuntimeException('PDO pgsql driver is not installed on this server.');
    }

    $cfg = db_config();
    try {
        $pdo = new PDO($cfg['dsn'], $cfg['user'], $cfg['pass'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_TIMEOUT            => 10,
        ]);
    } catch (PDOException $e) {
        error_log('[fampay] DB connection failed: ' . $e->getMessage());
        throw new RuntimeException('Database connection failed. Check DB_* environment variables.');
    }

    // Align the PostgreSQL session time zone with PHP. Render databases run in
    // UTC; without this every naive CURRENT_TIMESTAMP would be misread by PHP
    // and orders would look expired the moment they are created.
    try {
        $tz = date_default_timezone_get();
        $stmt = $pdo->prepare('SET TIME ZONE ' . $pdo->quote($tz));
        $stmt->execute();
    } catch (Throwable $e) {
        error_log('[fampay] could not set DB time zone: ' . $e->getMessage());
    }

    return $pdo;
}

/**
 * Idempotently create the schema (safe to call on every boot).
 * Returns true when the schema is present/created.
 */
function db_ensure_schema(bool $force = false): bool
{
    static $done = false;
    if ($done && !$force) {
        return true;
    }

    $pdo = db();
    $exists = $pdo->query("SELECT to_regclass('public.orders') IS NOT NULL AS ok")->fetch();
    if (!$force && $exists && ($exists['ok'] === true || $exists['ok'] === 't' || $exists['ok'] === 1)) {
        return $done = true;
    }

    $sqlFile = __DIR__ . '/migrations/001_initial_schema.sql';
    $sql = is_file($sqlFile) ? (string) file_get_contents($sqlFile) : embedded_schema_sql();
    $pdo->exec($sql);
    return $done = true;
}

/**
 * Fallback copy of migrations/001_initial_schema.sql.
 * Keeps the gateway working even when the migrations/ folder was not uploaded
 * (common when the repository is created from a phone).
 */
function embedded_schema_sql(): string
{
    return <<<'SQL'
CREATE TABLE IF NOT EXISTS orders (
    id SERIAL PRIMARY KEY,
    order_id VARCHAR(50) UNIQUE NOT NULL,
    upi_id VARCHAR(100) NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    qr_code_url TEXT,
    qr_code_base64 TEXT,
    qr_has_logo BOOLEAN DEFAULT TRUE,
    status VARCHAR(20) DEFAULT 'pending' CHECK (status IN ('pending', 'success', 'failed', 'expired')),
    utr_number VARCHAR(50),
    payer_name VARCHAR(100),
    payer_upi VARCHAR(100),
    payment_date TIMESTAMP,
    payment_details JSONB,
    api_key VARCHAR(64),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_order_id   ON orders(order_id);
CREATE INDEX IF NOT EXISTS idx_status     ON orders(status);
CREATE INDEX IF NOT EXISTS idx_created_at ON orders(created_at);
CREATE INDEX IF NOT EXISTS idx_utr        ON orders(utr_number);

CREATE TABLE IF NOT EXISTS api_keys (
    id SERIAL PRIMARY KEY,
    api_key VARCHAR(64) UNIQUE NOT NULL,
    gmail VARCHAR(100) NOT NULL,
    app_password VARCHAR(255) NOT NULL,
    is_active BOOLEAN DEFAULT TRUE,
    last_used TIMESTAMP,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_api_key ON api_keys(api_key);
CREATE INDEX IF NOT EXISTS idx_gmail   ON api_keys(gmail);

CREATE TABLE IF NOT EXISTS master_keys (
    id SERIAL PRIMARY KEY,
    master_key VARCHAR(64) UNIQUE NOT NULL,
    key_name VARCHAR(100) NOT NULL,
    created_by VARCHAR(50) DEFAULT 'admin',
    is_active BOOLEAN DEFAULT TRUE,
    usage_count INTEGER DEFAULT 0,
    last_used TIMESTAMP,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_master_key ON master_keys(master_key);

CREATE TABLE IF NOT EXISTS payment_logs (
    id SERIAL PRIMARY KEY,
    order_id VARCHAR(50) NOT NULL,
    api_key VARCHAR(64),
    action VARCHAR(50) NOT NULL,
    request_data TEXT,
    response_data TEXT,
    ip_address VARCHAR(45),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_order_log ON payment_logs(order_id);
CREATE INDEX IF NOT EXISTS idx_action    ON payment_logs(action);

CREATE OR REPLACE FUNCTION set_updated_at() RETURNS TRIGGER AS $$
BEGIN
    NEW.updated_at = CURRENT_TIMESTAMP;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS trg_orders_updated_at ON orders;
CREATE TRIGGER trg_orders_updated_at
    BEFORE UPDATE ON orders
    FOR EACH ROW EXECUTE FUNCTION set_updated_at();
SQL;
}
