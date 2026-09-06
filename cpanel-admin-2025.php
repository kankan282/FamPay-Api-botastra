<?php
/**
 * FamPay Payment Gateway - hidden admin panel
 * URL: /cpanel-admin-2025  (rewritten by .htaccess) or /cpanel-admin-2025.php
 * Author: @lazzy_guy
 */

declare(strict_types=1);

require_once __DIR__ . '/api/helpers.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax']);
    session_start();
}

header('X-Frame-Options: SAMEORIGIN');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');

$flash = null;
$flashType = 'success';
$dbError = null;
// Self URL used for post/redirect/get. Sanitised so no header injection is possible.
$selfPath = strtok((string) ($_SERVER['REQUEST_URI'] ?? '/cpanel-admin-2025.php'), '?') ?: '/cpanel-admin-2025.php';
$selfPath = preg_replace('#[^A-Za-z0-9/_\-\.]#', '', $selfPath) ?: '/cpanel-admin-2025.php';

/** Redirect back to the panel (post/redirect/get). */
function admin_redirect(string $type, string $message, string $path): never
{
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
    header('Location: ' . $path);
    exit;
}

// ---------------------------------------------------------------------------
// Authentication
// ---------------------------------------------------------------------------
$isLoggedIn = !empty($_SESSION['fampay_admin']) && $_SESSION['fampay_admin'] === true;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['action'] ?? '') === 'login') {
    $submitted = (string) ($_POST['password'] ?? '');
    if (hash_equals(ADMIN_PASSWORD, $submitted)) {
        session_regenerate_id(true);
        $_SESSION['fampay_admin'] = true;
        $_SESSION['login_time'] = time();
        csrf_token();
        header('Location: ' . $selfPath);
        exit;
    }
    $flash = 'Invalid password.';
    $flashType = 'danger';
    usleep(400000); // slow down brute force attempts
}

if (isset($_GET['logout'])) {
    $_SESSION = [];
    session_destroy();
    header('Location: ' . $selfPath);
    exit;
}

// Session lifetime: 2 hours
if ($isLoggedIn && isset($_SESSION['login_time']) && (time() - (int) $_SESSION['login_time']) > 7200) {
    $_SESSION = [];
    session_destroy();
    $isLoggedIn = false;
    $flash = 'Session expired, please sign in again.';
    $flashType = 'warning';
}

// ---------------------------------------------------------------------------
// Authenticated actions
// ---------------------------------------------------------------------------
if ($isLoggedIn && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['action'] ?? '') !== 'login') {
    $action = (string) ($_POST['action'] ?? '');
    if (!csrf_valid($_POST['csrf_token'] ?? null)) {
        admin_redirect('danger', 'Invalid CSRF token. Action blocked.', $selfPath);
    }

    try {
        db_ensure_schema();
        $pdo = db();

        switch ($action) {
            case 'create_key':
                $name = clean_text((string) ($_POST['key_name'] ?? 'Untitled'), 100);
                if ($name === '') {
                    $name = 'Untitled';
                }
                $key = unique_key('master_keys', 'master_key');
                $stmt = $pdo->prepare('INSERT INTO master_keys (master_key, key_name, created_by) VALUES (:k, :n, :b)');
                $stmt->execute([':k' => $key, ':n' => $name, ':b' => 'admin']);
                admin_redirect('success', 'Master key created: ' . $key, $selfPath);
                // no break needed - admin_redirect exits

            case 'toggle_key':
                $stmt = $pdo->prepare('UPDATE master_keys SET is_active = NOT is_active WHERE id = :id RETURNING master_key, is_active');
                $stmt->execute([':id' => (int) ($_POST['id'] ?? 0)]);
                $row = $stmt->fetch();
                admin_redirect(
                    'success',
                    $row ? ('Key ' . $row['master_key'] . ' is now ' . (to_bool($row['is_active']) ? 'active' : 'disabled') . '.') : 'Key not found.',
                    $selfPath
                );

            case 'rename_key':
                $name = clean_text((string) ($_POST['key_name'] ?? ''), 100);
                if ($name === '') {
                    admin_redirect('warning', 'Key name cannot be empty.', $selfPath);
                }
                $stmt = $pdo->prepare('UPDATE master_keys SET key_name = :n WHERE id = :id');
                $stmt->execute([':n' => $name, ':id' => (int) ($_POST['id'] ?? 0)]);
                admin_redirect('success', 'Key renamed.', $selfPath);

            case 'delete_key':
                $stmt = $pdo->prepare('DELETE FROM master_keys WHERE id = :id');
                $stmt->execute([':id' => (int) ($_POST['id'] ?? 0)]);
                admin_redirect('success', 'Master key deleted (' . $stmt->rowCount() . ' row).', $selfPath);

            case 'toggle_gmail':
                $stmt = $pdo->prepare('UPDATE api_keys SET is_active = NOT is_active WHERE id = :id');
                $stmt->execute([':id' => (int) ($_POST['id'] ?? 0)]);
                admin_redirect('success', 'Gmail connection updated.', $selfPath);

            case 'delete_gmail':
                $stmt = $pdo->prepare('DELETE FROM api_keys WHERE id = :id');
                $stmt->execute([':id' => (int) ($_POST['id'] ?? 0)]);
                admin_redirect('success', 'Gmail connection deleted.', $selfPath);

            case 'delete_order':
                $stmt = $pdo->prepare('DELETE FROM orders WHERE id = :id');
                $stmt->execute([':id' => (int) ($_POST['id'] ?? 0)]);
                admin_redirect('success', 'Order deleted.', $selfPath);

            case 'mark_order':
                $newStatus = (string) ($_POST['status'] ?? '');
                if (!in_array($newStatus, ['pending', 'success', 'failed', 'expired'], true)) {
                    admin_redirect('danger', 'Invalid status.', $selfPath);
                }
                $stmt = $pdo->prepare('UPDATE orders SET status = :s WHERE id = :id');
                $stmt->execute([':s' => $newStatus, ':id' => (int) ($_POST['id'] ?? 0)]);
                admin_redirect('success', 'Order marked as ' . $newStatus . '.', $selfPath);

            case 'purge_expired':
                $count = expire_stale_orders();
                $del = $pdo->exec("DELETE FROM orders WHERE status = 'expired'");
                admin_redirect('success', 'Purged ' . (int) $del . ' expired orders (' . $count . ' newly expired).', $selfPath);

            case 'clear_logs':
                $del = $pdo->exec('DELETE FROM payment_logs');
                admin_redirect('success', 'Cleared ' . (int) $del . ' log rows.', $selfPath);

            default:
                admin_redirect('danger', 'Unknown action.', $selfPath);
        }
    } catch (Throwable $e) {
        error_log('[fampay] admin action failed: ' . $e->getMessage());
        admin_redirect('danger', 'Action failed: ' . $e->getMessage(), $selfPath);
    }
}

if (!empty($_SESSION['flash'])) {
    $flash = (string) $_SESSION['flash']['message'];
    $flashType = (string) $_SESSION['flash']['type'];
    unset($_SESSION['flash']);
}

// ---------------------------------------------------------------------------
// Data loading
// ---------------------------------------------------------------------------
$stats = ['orders' => 0, 'paid' => 0, 'pending' => 0, 'expired' => 0, 'keys' => 0, 'revenue' => 0.0, 'gmail' => 0, 'logs' => 0];
$masterKeys = [];
$gmailKeys = [];
$orders = [];
$logs = [];
$imapReady = imap_available();

if ($isLoggedIn) {
    try {
        db_ensure_schema();
        expire_stale_orders();
        $pdo = db();

        $row = $pdo->query(
            "SELECT
                COUNT(*) AS total,
                COUNT(*) FILTER (WHERE status = 'success') AS paid,
                COUNT(*) FILTER (WHERE status = 'pending') AS pending,
                COUNT(*) FILTER (WHERE status = 'expired') AS expired,
                COALESCE(SUM(amount) FILTER (WHERE status = 'success'), 0) AS revenue
             FROM orders"
        )->fetch() ?: [];
        $stats['orders']  = (int) ($row['total'] ?? 0);
        $stats['paid']    = (int) ($row['paid'] ?? 0);
        $stats['pending'] = (int) ($row['pending'] ?? 0);
        $stats['expired'] = (int) ($row['expired'] ?? 0);
        $stats['revenue'] = (float) ($row['revenue'] ?? 0);
        $stats['keys']    = (int) $pdo->query('SELECT COUNT(*) FROM master_keys')->fetchColumn();
        $stats['gmail']   = (int) $pdo->query('SELECT COUNT(*) FROM api_keys')->fetchColumn();
        $stats['logs']    = (int) $pdo->query('SELECT COUNT(*) FROM payment_logs')->fetchColumn();

        $masterKeys = $pdo->query('SELECT * FROM master_keys ORDER BY created_at DESC LIMIT 100')->fetchAll();
        $gmailKeys  = $pdo->query('SELECT id, api_key, gmail, is_active, last_used, created_at FROM api_keys ORDER BY created_at DESC LIMIT 100')->fetchAll();
        $orders     = $pdo->query('SELECT id, order_id, upi_id, amount, status, utr_number, payer_name, qr_has_logo, created_at FROM orders ORDER BY created_at DESC LIMIT 60')->fetchAll();
        $logs       = $pdo->query('SELECT order_id, action, ip_address, created_at FROM payment_logs ORDER BY created_at DESC LIMIT 25')->fetchAll();
    } catch (Throwable $e) {
        $dbError = $e->getMessage();
    }
}

$csrf = $isLoggedIn ? csrf_token() : '';

/** @param mixed $v */
function h(mixed $v): string
{
    return htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function status_class(string $status): string
{
    return match ($status) {
        'success' => 'badge-success',
        'pending' => 'badge-warning',
        'expired' => 'badge-muted',
        'failed'  => 'badge-danger',
        default   => 'badge-muted',
    };
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Control Panel - FamPay Gateway</title>
<style>
:root{
  --bg:#06080f; --bg2:#0c1018; --card:#111827; --card-hover:#151d2e;
  --border:#1e293b; --border-hover:#334155;
  --accent:#6366f1; --accent-light:#818cf8;
  --success:#10b981; --warning:#f59e0b; --danger:#ef4444; --info:#06b6d4; --purple:#a855f7;
  --text:#f1f5f9; --text2:#94a3b8; --muted:#64748b;
  --mono:'SF Mono','Fira Code','Cascadia Code',Consolas,monospace;
}
*{box-sizing:border-box;margin:0;padding:0}
body{background:var(--bg);color:var(--text);font-family:Inter,-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;font-size:14px;line-height:1.6;-webkit-font-smoothing:antialiased}
a{color:var(--accent-light);text-decoration:none}
.wrap{max-width:1240px;margin:0 auto;padding:0 20px}
.topbar{position:sticky;top:0;z-index:40;background:rgba(6,8,15,.9);backdrop-filter:blur(12px);border-bottom:1px solid var(--border)}
.topbar-in{display:flex;align-items:center;justify-content:space-between;gap:16px;height:64px;flex-wrap:wrap}
.brand{display:flex;align-items:center;gap:10px;font-weight:700;letter-spacing:-.02em}
.brand-mark{width:34px;height:34px;border-radius:9px;background:linear-gradient(135deg,var(--accent),var(--purple));display:grid;place-items:center;color:#fff}
.brand small{display:block;font-weight:500;font-size:11px;color:var(--muted);letter-spacing:.04em;text-transform:uppercase}
.tools{display:flex;align-items:center;gap:8px;flex-wrap:wrap}
.btn{display:inline-flex;align-items:center;gap:7px;padding:8px 13px;border-radius:9px;border:1px solid var(--border);background:var(--card);color:var(--text);font-size:13px;font-weight:500;cursor:pointer;transition:.15s;font-family:inherit}
.btn:hover{border-color:var(--border-hover);background:var(--card-hover)}
.btn-primary{background:var(--accent);border-color:var(--accent);color:#fff}
.btn-primary:hover{background:var(--accent-light);border-color:var(--accent-light)}
.btn-danger{color:#fecaca;border-color:#3f1d20}
.btn-danger:hover{background:rgba(239,68,68,.14);border-color:var(--danger);color:#fff}
.btn-warning{color:#fde68a;border-color:#3f2f14}
.btn-warning:hover{background:rgba(245,158,11,.14);border-color:var(--warning)}
.btn-success{color:#a7f3d0;border-color:#12362b}
.btn-success:hover{background:rgba(16,185,129,.14);border-color:var(--success)}
.btn-icon{padding:7px;border-radius:8px}
.btn:disabled{opacity:.5;cursor:not-allowed}
main{padding:28px 0 60px}
.grid-stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:14px;margin-bottom:26px}
.stat{background:var(--card);border:1px solid var(--border);border-radius:14px;padding:16px 18px;transition:.15s}
.stat:hover{border-color:var(--border-hover);background:var(--card-hover)}
.stat-top{display:flex;align-items:center;justify-content:space-between;color:var(--text2);font-size:12px;text-transform:uppercase;letter-spacing:.06em}
.stat-val{font-size:26px;font-weight:700;margin-top:8px;letter-spacing:-.02em}
.ico-box{width:30px;height:30px;border-radius:8px;display:grid;place-items:center}
.card{background:var(--card);border:1px solid var(--border);border-radius:16px;margin-bottom:22px;overflow:hidden}
.card-head{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:15px 18px;border-bottom:1px solid var(--border);flex-wrap:wrap}
.card-title{display:flex;align-items:center;gap:9px;font-weight:600;font-size:15px}
.card-body{padding:18px}
.table-scroll{overflow-x:auto}
table{width:100%;border-collapse:collapse;font-size:13px;min-width:640px}
th{text-align:left;padding:11px 14px;color:var(--muted);font-weight:600;font-size:11px;text-transform:uppercase;letter-spacing:.07em;border-bottom:1px solid var(--border);white-space:nowrap}
td{padding:12px 14px;border-bottom:1px solid rgba(30,41,59,.6);vertical-align:middle}
tr:last-child td{border-bottom:none}
tbody tr:hover{background:rgba(99,102,241,.05)}
.mono{font-family:var(--mono);font-size:12.5px}
.key-chip{display:inline-flex;align-items:center;gap:8px;background:#0b1220;border:1px solid var(--border);border-radius:8px;padding:5px 9px;font-family:var(--mono);font-size:13px;letter-spacing:.06em}
.badge{display:inline-flex;align-items:center;gap:5px;padding:3px 9px;border-radius:999px;font-size:11.5px;font-weight:600;text-transform:uppercase;letter-spacing:.04em}
.badge-success{background:rgba(16,185,129,.14);color:#6ee7b7}
.badge-warning{background:rgba(245,158,11,.14);color:#fcd34d}
.badge-danger{background:rgba(239,68,68,.14);color:#fca5a5}
.badge-muted{background:rgba(100,116,139,.16);color:#cbd5e1}
.badge-info{background:rgba(6,182,212,.14);color:#67e8f9}
.actions{display:flex;gap:6px;align-items:center;flex-wrap:wrap}
form.inline{display:inline}
input[type=text],input[type=password],select{background:#0b1220;border:1px solid var(--border);color:var(--text);padding:10px 12px;border-radius:9px;font-size:14px;font-family:inherit;outline:none;width:100%;transition:.15s}
input:focus,select:focus{border-color:var(--accent);box-shadow:0 0 0 3px rgba(99,102,241,.15)}
.form-row{display:flex;gap:10px;flex-wrap:wrap;align-items:center}
.form-row input{flex:1;min-width:220px}
.alert{display:flex;align-items:center;gap:10px;padding:12px 15px;border-radius:11px;margin-bottom:20px;font-size:13.5px;border:1px solid}
.alert-success{background:rgba(16,185,129,.09);border-color:rgba(16,185,129,.35);color:#a7f3d0}
.alert-danger{background:rgba(239,68,68,.09);border-color:rgba(239,68,68,.35);color:#fecaca}
.alert-warning{background:rgba(245,158,11,.09);border-color:rgba(245,158,11,.35);color:#fde68a}
.login-shell{min-height:100vh;display:grid;place-items:center;padding:24px;background:radial-gradient(700px 400px at 50% -10%,rgba(99,102,241,.16),transparent 70%)}
.login-card{width:100%;max-width:390px;background:var(--card);border:1px solid var(--border);border-radius:18px;padding:32px}
.login-icon{width:52px;height:52px;border-radius:14px;background:linear-gradient(135deg,var(--accent),var(--purple));display:grid;place-items:center;margin:0 auto 16px;color:#fff}
.login-card h1{font-size:20px;text-align:center;letter-spacing:-.02em}
.login-card p{text-align:center;color:var(--text2);font-size:13px;margin:6px 0 22px}
.label{display:block;font-size:12px;color:var(--text2);margin-bottom:7px;font-weight:500}
.footer{border-top:1px solid var(--border);padding:22px 0;color:var(--muted);font-size:12.5px;display:flex;justify-content:space-between;gap:14px;flex-wrap:wrap;align-items:center}
.empty{padding:30px;text-align:center;color:var(--muted);font-size:13.5px}
.hint{color:var(--muted);font-size:12px}
@media(max-width:640px){.stat-val{font-size:22px}.topbar-in{height:auto;padding:12px 0}}
</style>
</head>
<body>
<?php if (!$isLoggedIn): ?>
<div class="login-shell">
  <div class="login-card">
    <div class="login-icon"><i data-lucide="shield" style="width:26px;height:26px"></i></div>
    <h1>Control Panel</h1>
    <p>FamPay Gateway v<?= h(APP_VERSION) ?> &middot; restricted access</p>
    <?php if ($flash): ?>
      <div class="alert alert-<?= h($flashType) ?>"><i data-lucide="circle-alert" style="width:16px;height:16px"></i><span><?= h($flash) ?></span></div>
    <?php endif; ?>
    <form method="post" autocomplete="off">
      <input type="hidden" name="action" value="login">
      <label class="label" for="pw">Administrator password</label>
      <input id="pw" type="password" name="password" placeholder="Enter password" required autofocus>
      <button class="btn btn-primary" type="submit" style="width:100%;justify-content:center;margin-top:16px;padding:11px">
        <i data-lucide="log-in" style="width:16px;height:16px"></i> Sign In
      </button>
    </form>
  </div>
</div>
<?php else: ?>
<header class="topbar">
  <div class="wrap topbar-in">
    <div class="brand">
      <span class="brand-mark"><i data-lucide="shield-check" style="width:19px;height:19px"></i></span>
      <span>Control Panel<small>FamPay Gateway v<?= h(APP_VERSION) ?></small></span>
    </div>
    <div class="tools">
      <a class="btn" href="/"><i data-lucide="globe" style="width:15px;height:15px"></i> Site</a>
      <form class="inline" method="post" onsubmit="return confirm('Delete every expired order?')">
        <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
        <input type="hidden" name="action" value="purge_expired">
        <button class="btn btn-warning" type="submit"><i data-lucide="trash" style="width:15px;height:15px"></i> Purge Expired</button>
      </form>
      <form class="inline" method="post" onsubmit="return confirm('Clear all logs?')">
        <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
        <input type="hidden" name="action" value="clear_logs">
        <button class="btn btn-warning" type="submit"><i data-lucide="eraser" style="width:15px;height:15px"></i> Clear Logs</button>
      </form>
      <a class="btn btn-danger" href="?logout=1"><i data-lucide="log-out" style="width:15px;height:15px"></i> Exit</a>
    </div>
  </div>
</header>

<main class="wrap">
  <?php if ($flash): ?>
    <div class="alert alert-<?= h($flashType) ?>"><i data-lucide="info" style="width:16px;height:16px"></i><span><?= h($flash) ?></span></div>
  <?php endif; ?>
  <?php if ($dbError): ?>
    <div class="alert alert-danger"><i data-lucide="database" style="width:16px;height:16px"></i><span>Database error: <?= h($dbError) ?></span></div>
  <?php endif; ?>
  <?php if (!$imapReady): ?>
    <div class="alert alert-warning"><i data-lucide="mail-warning" style="width:16px;height:16px"></i><span>PHP IMAP extension is not loaded - Gmail login and payment verification are disabled on this host. The bundled Dockerfile installs it.</span></div>
  <?php endif; ?>

  <section class="grid-stats">
    <div class="stat"><div class="stat-top"><span>Orders</span><span class="ico-box" style="background:rgba(99,102,241,.14);color:var(--accent-light)"><i data-lucide="receipt" style="width:16px;height:16px"></i></span></div><div class="stat-val"><?= (int) $stats['orders'] ?></div></div>
    <div class="stat"><div class="stat-top"><span>Paid</span><span class="ico-box" style="background:rgba(16,185,129,.14);color:var(--success)"><i data-lucide="circle-check" style="width:16px;height:16px"></i></span></div><div class="stat-val"><?= (int) $stats['paid'] ?></div></div>
    <div class="stat"><div class="stat-top"><span>Pending</span><span class="ico-box" style="background:rgba(245,158,11,.14);color:var(--warning)"><i data-lucide="clock" style="width:16px;height:16px"></i></span></div><div class="stat-val"><?= (int) $stats['pending'] ?></div></div>
    <div class="stat"><div class="stat-top"><span>Expired</span><span class="ico-box" style="background:rgba(100,116,139,.16);color:var(--text2)"><i data-lucide="timer-off" style="width:16px;height:16px"></i></span></div><div class="stat-val"><?= (int) $stats['expired'] ?></div></div>
    <div class="stat"><div class="stat-top"><span>Keys</span><span class="ico-box" style="background:rgba(168,85,247,.14);color:var(--purple)"><i data-lucide="key" style="width:16px;height:16px"></i></span></div><div class="stat-val"><?= (int) $stats['keys'] ?></div></div>
    <div class="stat"><div class="stat-top"><span>Revenue</span><span class="ico-box" style="background:rgba(6,182,212,.14);color:var(--info)"><i data-lucide="indian-rupee" style="width:16px;height:16px"></i></span></div><div class="stat-val"><?= h(number_format($stats['revenue'], 2)) ?></div></div>
  </section>

  <section class="card">
    <div class="card-head">
      <div class="card-title"><i data-lucide="circle-plus" style="width:17px;height:17px;color:var(--accent-light)"></i> Create Master API Key</div>
      <span class="hint">6 character alphanumeric key</span>
    </div>
    <div class="card-body">
      <form method="post" class="form-row">
        <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
        <input type="hidden" name="action" value="create_key">
        <input type="text" name="key_name" placeholder="Key name (e.g. Production App)" maxlength="100" required>
        <button class="btn btn-primary" type="submit"><i data-lucide="key-round" style="width:15px;height:15px"></i> Generate Key</button>
      </form>
    </div>
  </section>

  <section class="card">
    <div class="card-head">
      <div class="card-title"><i data-lucide="key" style="width:17px;height:17px;color:var(--purple)"></i> Master API Keys</div>
      <span class="hint"><?= count($masterKeys) ?> shown</span>
    </div>
    <div class="table-scroll">
      <?php if (!$masterKeys): ?>
        <div class="empty">No master keys yet. Create one above.</div>
      <?php else: ?>
      <table>
        <thead><tr><th>Key</th><th>Name</th><th>Status</th><th>Uses</th><th>Last used</th><th>Created</th><th style="text-align:right">Actions</th></tr></thead>
        <tbody>
        <?php foreach ($masterKeys as $k): $active = to_bool($k['is_active']); ?>
          <tr>
            <td><span class="key-chip"><?= h($k['master_key']) ?>
              <button class="btn btn-icon" type="button" title="Copy key" onclick="copyText('<?= h($k['master_key']) ?>',this)"><i data-lucide="copy" style="width:14px;height:14px"></i></button>
            </span></td>
            <td><?= h($k['key_name']) ?></td>
            <td><span class="badge <?= $active ? 'badge-success' : 'badge-muted' ?>"><?= $active ? 'Active' : 'Disabled' ?></span></td>
            <td class="mono"><?= (int) $k['usage_count'] ?></td>
            <td class="mono"><?= $k['last_used'] ? h(substr((string) $k['last_used'], 0, 19)) : '-' ?></td>
            <td class="mono"><?= h(substr((string) $k['created_at'], 0, 19)) ?></td>
            <td>
              <div class="actions" style="justify-content:flex-end">
                <form class="inline" method="post">
                  <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                  <input type="hidden" name="action" value="toggle_key">
                  <input type="hidden" name="id" value="<?= (int) $k['id'] ?>">
                  <button class="btn btn-icon <?= $active ? 'btn-warning' : 'btn-success' ?>" type="submit" title="<?= $active ? 'Disable key' : 'Enable key' ?>">
                    <i data-lucide="<?= $active ? 'pause' : 'play' ?>" style="width:14px;height:14px"></i>
                  </button>
                </form>
                <form class="inline" method="post" onsubmit="return confirm('Delete key <?= h($k['master_key']) ?>?')">
                  <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                  <input type="hidden" name="action" value="delete_key">
                  <input type="hidden" name="id" value="<?= (int) $k['id'] ?>">
                  <button class="btn btn-icon btn-danger" type="submit" title="Delete key"><i data-lucide="trash" style="width:14px;height:14px"></i></button>
                </form>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <?php endif; ?>
    </div>
  </section>

  <section class="card">
    <div class="card-head">
      <div class="card-title"><i data-lucide="mail" style="width:17px;height:17px;color:var(--info)"></i> Gmail Connections</div>
      <span class="hint"><?= count($gmailKeys) ?> connected</span>
    </div>
    <div class="table-scroll">
      <?php if (!$gmailKeys): ?>
        <div class="empty">No Gmail connections. Use /login.php to add one.</div>
      <?php else: ?>
      <table>
        <thead><tr><th>Gmail key</th><th>Address</th><th>Status</th><th>Last used</th><th>Created</th><th style="text-align:right">Actions</th></tr></thead>
        <tbody>
        <?php foreach ($gmailKeys as $g): $active = to_bool($g['is_active']); ?>
          <tr>
            <td><span class="key-chip"><?= h($g['api_key']) ?>
              <button class="btn btn-icon" type="button" title="Copy key" onclick="copyText('<?= h($g['api_key']) ?>',this)"><i data-lucide="copy" style="width:14px;height:14px"></i></button>
            </span></td>
            <td class="mono"><?= h($g['gmail']) ?></td>
            <td><span class="badge <?= $active ? 'badge-success' : 'badge-muted' ?>"><?= $active ? 'Active' : 'Disabled' ?></span></td>
            <td class="mono"><?= $g['last_used'] ? h(substr((string) $g['last_used'], 0, 19)) : '-' ?></td>
            <td class="mono"><?= h(substr((string) $g['created_at'], 0, 19)) ?></td>
            <td>
              <div class="actions" style="justify-content:flex-end">
                <form class="inline" method="post">
                  <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                  <input type="hidden" name="action" value="toggle_gmail">
                  <input type="hidden" name="id" value="<?= (int) $g['id'] ?>">
                  <button class="btn btn-icon <?= $active ? 'btn-warning' : 'btn-success' ?>" type="submit" title="<?= $active ? 'Disable' : 'Enable' ?>">
                    <i data-lucide="<?= $active ? 'pause' : 'play' ?>" style="width:14px;height:14px"></i>
                  </button>
                </form>
                <form class="inline" method="post" onsubmit="return confirm('Delete Gmail connection <?= h($g['gmail']) ?>?')">
                  <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                  <input type="hidden" name="action" value="delete_gmail">
                  <input type="hidden" name="id" value="<?= (int) $g['id'] ?>">
                  <button class="btn btn-icon btn-danger" type="submit" title="Delete"><i data-lucide="trash" style="width:14px;height:14px"></i></button>
                </form>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <?php endif; ?>
    </div>
  </section>

  <section class="card">
    <div class="card-head">
      <div class="card-title"><i data-lucide="receipt" style="width:17px;height:17px;color:var(--accent-light)"></i> Orders</div>
      <span class="hint">latest <?= count($orders) ?></span>
    </div>
    <div class="table-scroll">
      <?php if (!$orders): ?>
        <div class="empty">No orders yet.</div>
      <?php else: ?>
      <table>
        <thead><tr><th>Order ID</th><th>UPI</th><th>Amount</th><th>Status</th><th>UTR</th><th>Payer</th><th>QR</th><th>Created</th><th style="text-align:right">Actions</th></tr></thead>
        <tbody>
        <?php foreach ($orders as $o): ?>
          <tr>
            <td class="mono"><?= h($o['order_id']) ?></td>
            <td class="mono"><?= h($o['upi_id']) ?></td>
            <td class="mono"><?= h(number_format((float) $o['amount'], 2)) ?></td>
            <td><span class="badge <?= status_class((string) $o['status']) ?>"><?= h($o['status']) ?></span></td>
            <td class="mono"><?= $o['utr_number'] ? h($o['utr_number']) : '-' ?></td>
            <td><?= $o['payer_name'] ? h($o['payer_name']) : '-' ?></td>
            <td><span class="badge <?= to_bool($o['qr_has_logo']) ? 'badge-info' : 'badge-muted' ?>"><?= to_bool($o['qr_has_logo']) ? 'Logo' : 'Plain' ?></span></td>
            <td class="mono"><?= h(substr((string) $o['created_at'], 0, 19)) ?></td>
            <td>
              <div class="actions" style="justify-content:flex-end">
                <a class="btn btn-icon" href="/qr.php?order_id=<?= h($o['order_id']) ?>&amp;format=png" target="_blank" rel="noopener" title="View QR"><i data-lucide="qr-code" style="width:14px;height:14px"></i></a>
                <?php if ((string) $o['status'] !== 'success'): ?>
                <form class="inline" method="post" onsubmit="return confirm('Mark this order as paid?')">
                  <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                  <input type="hidden" name="action" value="mark_order">
                  <input type="hidden" name="status" value="success">
                  <input type="hidden" name="id" value="<?= (int) $o['id'] ?>">
                  <button class="btn btn-icon btn-success" type="submit" title="Mark paid"><i data-lucide="check" style="width:14px;height:14px"></i></button>
                </form>
                <?php endif; ?>
                <form class="inline" method="post" onsubmit="return confirm('Delete order <?= h($o['order_id']) ?>?')">
                  <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                  <input type="hidden" name="action" value="delete_order">
                  <input type="hidden" name="id" value="<?= (int) $o['id'] ?>">
                  <button class="btn btn-icon btn-danger" type="submit" title="Delete order"><i data-lucide="trash" style="width:14px;height:14px"></i></button>
                </form>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <?php endif; ?>
    </div>
  </section>

  <section class="card">
    <div class="card-head">
      <div class="card-title"><i data-lucide="scroll-text" style="width:17px;height:17px;color:var(--text2)"></i> Recent Activity</div>
      <span class="hint"><?= (int) $stats['logs'] ?> log rows total</span>
    </div>
    <div class="table-scroll">
      <?php if (!$logs): ?>
        <div class="empty">No activity logged yet.</div>
      <?php else: ?>
      <table>
        <thead><tr><th>Time</th><th>Order</th><th>Action</th><th>IP</th></tr></thead>
        <tbody>
        <?php foreach ($logs as $l): ?>
          <tr>
            <td class="mono"><?= h(substr((string) $l['created_at'], 0, 19)) ?></td>
            <td class="mono"><?= h($l['order_id']) ?></td>
            <td><span class="badge badge-muted"><?= h($l['action']) ?></span></td>
            <td class="mono"><?= h($l['ip_address']) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <?php endif; ?>
    </div>
  </section>

  <div class="footer">
    <span>FamPay Gateway v<?= h(APP_VERSION) ?> &middot; PostgreSQL &middot; PHP <?= h(PHP_VERSION) ?></span>
    <a class="btn" href="<?= h(APP_TELEGRAM) ?>" target="_blank" rel="noopener"><i data-lucide="send" style="width:14px;height:14px"></i> <?= h(APP_DEVELOPER) ?></a>
  </div>
</main>
<?php endif; ?>

<script src="https://unpkg.com/lucide@latest"></script>
<script>
function renderIcons(){ if (window.lucide && typeof window.lucide.createIcons === 'function') { window.lucide.createIcons(); } }
document.addEventListener('DOMContentLoaded', renderIcons);
window.addEventListener('load', renderIcons);
function copyText(text, btn){
  var done = function(){
    if(!btn) return;
    var original = btn.innerHTML;
    btn.innerHTML = '<i data-lucide="check" style="width:14px;height:14px"></i>';
    renderIcons();
    setTimeout(function(){ btn.innerHTML = original; renderIcons(); }, 1400);
  };
  if (navigator.clipboard && window.isSecureContext) {
    navigator.clipboard.writeText(text).then(done).catch(function(){ fallbackCopy(text, done); });
  } else {
    fallbackCopy(text, done);
  }
}
function fallbackCopy(text, cb){
  var ta = document.createElement('textarea');
  ta.value = text; ta.style.position='fixed'; ta.style.opacity='0';
  document.body.appendChild(ta); ta.select();
  try { document.execCommand('copy'); } catch(e) {}
  document.body.removeChild(ta); cb();
}
</script>
</body>
</html>
