<?php
/**
 * POST|GET /create-key.php?admin_password=...&key_name=MyApp
 * Creates a Master API key. Protected by the admin password.
 */

declare(strict_types=1);

require_once __DIR__ . '/api/helpers.php';

api_boot();

try {
    rate_limit('create-key', 10, 60);

    $password = param('admin_password') ?? param('password');
    if ($password === null) {
        api_error('Missing required parameter: admin_password', 400, 'MISSING_PARAMETER');
    }
    if (!hash_equals(ADMIN_PASSWORD, $password)) {
        api_error('Invalid admin password.', 401, 'UNAUTHORIZED');
    }

    $keyName = clean_text(param('key_name', 'Untitled'), 100);
    if ($keyName === '') {
        $keyName = 'Untitled';
    }

    db_ensure_schema();

    $apiKey = unique_key('master_keys', 'master_key');
    $stmt = db()->prepare(
        'INSERT INTO master_keys (master_key, key_name, created_by, is_active)
         VALUES (:key, :name, :by, TRUE) RETURNING id, created_at'
    );
    $stmt->execute([':key' => $apiKey, ':name' => $keyName, ':by' => 'api']);
    $row = $stmt->fetch() ?: [];

    log_action('SYSTEM', 'create_master_key', $apiKey, ['key_name' => $keyName], ['created' => true]);

    api_success([
        'api_key'    => $apiKey,
        'key_name'   => $keyName,
        'status'     => 'active',
        'created_at' => substr((string) ($row['created_at'] ?? date('Y-m-d H:i:s')), 0, 19),
        'usage'      => [
            'qr'    => app_url() . '/qr.php?upi=YOUR_UPI@fam&amount=100&api_key=' . $apiKey,
            'login' => app_url() . '/login.php?gmail=you@gmail.com&app_password=APP_PASSWORD&api_key=' . $apiKey,
        ],
    ], 201);
} catch (RuntimeException $e) {
    api_error($e->getMessage(), 503, 'SERVICE_UNAVAILABLE');
} catch (Throwable $e) {
    error_log('[fampay] create-key.php: ' . $e->getMessage());
    api_error('Internal server error while creating the API key.', 500, 'INTERNAL_ERROR');
}
