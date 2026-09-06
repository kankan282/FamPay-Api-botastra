<?php
/**
 * Router for the PHP built-in web server (local development only).
 *
 *   php -S 0.0.0.0:8080 -t . tests/router.php
 *
 * It emulates the .htaccess rewrites (pretty admin URL + extension-less API
 * paths) and blocks the paths that Apache denies in production.
 */

declare(strict_types=1);

$root = dirname(__DIR__);
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$path = '/' . ltrim((string) $path, '/');

// Deny the same things .htaccess denies
if (preg_match('#^/(vendor|migrations|tests)/#', $path)
    || preg_match('#^/(config\.php|composer\.(json|lock)|render\.yaml|Dockerfile|apache-config\.conf|docker-entrypoint\.sh|\.env.*|\.htaccess|.*\.md|.*\.sql)$#', $path)) {
    http_response_code(403);
    header('Content-Type: text/plain');
    echo "403 Forbidden";
    return true;
}

// Pretty admin URL
if (rtrim($path, '/') === '/cpanel-admin-2025') {
    require $root . '/cpanel-admin-2025.php';
    return true;
}

// Extension-less API endpoints
if (preg_match('#^/(qr|verify|login|create-key|test-db|test-imap|test-qr|test-all)/?$#', $path, $m)) {
    require $root . '/' . $m[1] . '.php';
    return true;
}

if ($path === '/' || $path === '') {
    require $root . '/index.html';
    return true;
}

$file = realpath($root . $path);
if ($file !== false && is_file($file) && str_starts_with($file, $root)) {
    return false; // let the built-in server serve/execute it
}

http_response_code(404);
header('Content-Type: application/json');
echo json_encode(['success' => false, 'error' => ['code' => 'NOT_FOUND', 'message' => 'Endpoint not found: ' . $path]], JSON_PRETTY_PRINT);
return true;
