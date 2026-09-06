<?php
/**
 * /test-db.php - PostgreSQL connectivity, schema and CRUD diagnostics.
 * Add ?init=1 to (re)apply the migration, ?format=json for machine output.
 */

declare(strict_types=1);

require_once __DIR__ . '/api/test-ui.php';

tests_begin('Database Test', 'PostgreSQL connection, schema, indexes and CRUD round trip.');

$cfg = db_config();
tests_add(true, 'Configuration loaded', 'host=' . $cfg['host'] . ' db=' . $cfg['name'] . ' driver=pgsql');
tests_add(in_array('pgsql', PDO::getAvailableDrivers(), true), 'PDO pgsql driver available', implode(', ', PDO::getAvailableDrivers()));

try {
    $pdo = db();
    $version = (string) $pdo->query('SHOW server_version')->fetchColumn();
    tests_add(true, 'Connection established', 'PostgreSQL ' . $version);
} catch (Throwable $e) {
    tests_add(false, 'Connection established', $e->getMessage());
    tests_end();
}

try {
    db_ensure_schema(isset($_GET['init']));
    tests_add(true, 'Schema ensured', 'migrations/001_initial_schema.sql applied when missing');
} catch (Throwable $e) {
    tests_add(false, 'Schema ensured', $e->getMessage());
    tests_end();
}

foreach (['orders', 'api_keys', 'master_keys', 'payment_logs'] as $table) {
    try {
        $exists = $pdo->query("SELECT to_regclass('public.$table') IS NOT NULL AS ok")->fetch();
        $ok = $exists && to_bool($exists['ok']);
        $count = $ok ? (int) $pdo->query("SELECT COUNT(*) FROM $table")->fetchColumn() : 0;
        tests_add($ok, "Table $table exists", $ok ? "$count row(s)" : 'missing');
    } catch (Throwable $e) {
        tests_add(false, "Table $table exists", $e->getMessage());
    }
}

try {
    $indexes = $pdo->query("SELECT indexname FROM pg_indexes WHERE schemaname = 'public' ORDER BY indexname")->fetchAll(PDO::FETCH_COLUMN);
    $required = ['idx_order_id', 'idx_status', 'idx_created_at', 'idx_api_key', 'idx_gmail', 'idx_master_key', 'idx_order_log', 'idx_action'];
    $missing = array_diff($required, $indexes);
    tests_add($missing === [], 'Indexes present', $missing === [] ? implode(', ', $required) : 'missing: ' . implode(', ', $missing));
} catch (Throwable $e) {
    tests_add(false, 'Indexes present', $e->getMessage());
}

// --- CRUD round trip on a throwaway order -----------------------------------
$sampleOrder = 'FAM' . date('Ymd') . 'TEST' . strtoupper(substr(bin2hex(random_bytes(2)), 0, 4));
try {
    $ins = $pdo->prepare(
        "INSERT INTO orders (order_id, upi_id, amount, status, qr_has_logo) VALUES (:o, :u, :a, 'pending', TRUE)"
    );
    $ins->execute([':o' => $sampleOrder, ':u' => 'diagnostic@fam', ':a' => '1.00']);
    tests_add(true, 'INSERT sample order', $sampleOrder);

    $sel = $pdo->prepare('SELECT * FROM orders WHERE order_id = :o');
    $sel->execute([':o' => $sampleOrder]);
    $row = $sel->fetch();
    tests_add((bool) $row, 'SELECT sample order', $row ? 'amount=' . $row['amount'] . ' status=' . $row['status'] : 'not found');

    $upd = $pdo->prepare("UPDATE orders SET status = 'success', utr_number = :u, payment_details = CAST(:d AS JSONB) WHERE order_id = :o");
    $upd->execute([':u' => 'TESTUTR' . random_int(100000, 999999), ':d' => json_encode(['test' => true]), ':o' => $sampleOrder]);
    tests_add($upd->rowCount() === 1, 'UPDATE sample order (JSONB write)', 'rows=' . $upd->rowCount());

    $sel->execute([':o' => $sampleOrder]);
    $row = $sel->fetch();
    $decoded = $row ? json_decode((string) $row['payment_details'], true) : null;
    tests_add(is_array($decoded) && ($decoded['test'] ?? false) === true, 'JSONB round trip', (string) ($row['payment_details'] ?? ''));

    $del = $pdo->prepare('DELETE FROM orders WHERE order_id = :o');
    $del->execute([':o' => $sampleOrder]);
    tests_add($del->rowCount() === 1, 'DELETE sample order', 'rows=' . $del->rowCount());
} catch (Throwable $e) {
    tests_add(false, 'CRUD round trip', $e->getMessage());
    try {
        $pdo->prepare('DELETE FROM orders WHERE order_id = :o')->execute([':o' => $sampleOrder]);
    } catch (Throwable) {
    }
}

// --- prepared statement / injection guard -----------------------------------
try {
    $evil = "x' OR '1'='1";
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM orders WHERE upi_id = :u');
    $stmt->execute([':u' => $evil]);
    tests_add(((int) $stmt->fetchColumn()) === 0, 'Prepared statements block injection', 'payload: ' . $evil);
} catch (Throwable $e) {
    tests_add(false, 'Prepared statements block injection', $e->getMessage());
}

// --- expiry helper -----------------------------------------------------------
try {
    $expired = expire_stale_orders();
    tests_add(true, 'Expiry sweep executed', $expired . ' pending order(s) older than ' . ORDER_EXPIRY_MINUTES . ' min marked expired');
} catch (Throwable $e) {
    tests_add(false, 'Expiry sweep executed', $e->getMessage());
}

tests_end();
