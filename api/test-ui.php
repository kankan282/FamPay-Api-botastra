<?php
/**
 * Shared renderer for the /test-*.php diagnostic pages.
 * Supports HTML (default) and JSON (?format=json) output.
 */

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

$GLOBALS['fampay_tests'] = [
    'title'    => 'Diagnostics',
    'subtitle' => '',
    'items'    => [],
    'extra'    => [],
];

function tests_begin(string $title, string $subtitle = ''): void
{
    $GLOBALS['fampay_tests']['title'] = $title;
    $GLOBALS['fampay_tests']['subtitle'] = $subtitle;
}

/**
 * @param bool|null $pass true = pass, false = fail, null = skipped/warning
 */
function tests_add(?bool $pass, string $name, string $detail = ''): void
{
    $GLOBALS['fampay_tests']['items'][] = [
        'status' => $pass === null ? 'skip' : ($pass ? 'pass' : 'fail'),
        'name'   => $name,
        'detail' => $detail,
    ];
}

/** Attach arbitrary HTML (e.g. a QR preview) to the report. */
function tests_html(string $html): void
{
    $GLOBALS['fampay_tests']['extra'][] = $html;
}

function tests_end(): never
{
    $report = $GLOBALS['fampay_tests'];
    $pass = $fail = $skip = 0;
    foreach ($report['items'] as $item) {
        if ($item['status'] === 'pass') {
            $pass++;
        } elseif ($item['status'] === 'fail') {
            $fail++;
        } else {
            $skip++;
        }
    }

    $wantsJson = strtolower((string) (param('format') ?? '')) === 'json';
    if ($wantsJson) {
        json_out([
            'success' => $fail === 0,
            'data'    => [
                'title'   => $report['title'],
                'summary' => ['passed' => $pass, 'failed' => $fail, 'skipped' => $skip],
                'tests'   => $report['items'],
            ],
            'timestamp' => date('Y-m-d H:i:s'),
        ], $fail === 0 ? 200 : 500);
    }

    $esc = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    http_response_code($fail === 0 ? 200 : 500);
    header('Content-Type: text/html; charset=utf-8');
    ?>
<!DOCTYPE html>
<html lang="en"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex">
<title><?= $esc($report['title']) ?> - FamPay Gateway</title>
<style>
:root{--bg:#06080f;--card:#111827;--border:#1e293b;--border-hover:#334155;--accent:#6366f1;--accent-light:#818cf8;
--success:#10b981;--warning:#f59e0b;--danger:#ef4444;--text:#f1f5f9;--text2:#94a3b8;--muted:#64748b;
--mono:'SF Mono','Fira Code',Consolas,monospace}
*{margin:0;padding:0;box-sizing:border-box}
body{background:var(--bg);color:var(--text);font-family:Inter,-apple-system,'Segoe UI',sans-serif;font-size:14.5px;line-height:1.6;padding:34px 18px}
.wrap{max-width:880px;margin:0 auto}
h1{font-size:24px;letter-spacing:-.025em;display:flex;align-items:center;gap:10px}
.sub{color:var(--text2);font-size:13.5px;margin-top:6px}
.summary{display:grid;grid-template-columns:repeat(auto-fit,minmax(120px,1fr));gap:12px;margin:22px 0}
.sum{background:var(--card);border:1px solid var(--border);border-radius:13px;padding:15px 17px}
.sum b{display:block;font-size:23px;font-weight:800}
.sum span{color:var(--muted);font-size:11.5px;text-transform:uppercase;letter-spacing:.07em;font-weight:600}
.list{background:var(--card);border:1px solid var(--border);border-radius:15px;overflow:hidden}
.row{display:flex;gap:13px;padding:14px 17px;border-bottom:1px solid rgba(30,41,59,.65)}
.row:last-child{border-bottom:none}
.tag{flex-shrink:0;width:64px;text-align:center;height:23px;line-height:23px;border-radius:7px;font-size:10.5px;font-weight:800;letter-spacing:.07em}
.pass{background:rgba(16,185,129,.15);color:#6ee7b7}
.fail{background:rgba(239,68,68,.15);color:#fca5a5}
.skip{background:rgba(245,158,11,.15);color:#fcd34d}
.name{font-weight:600}
.detail{color:var(--text2);font-size:13px;font-family:var(--mono);word-break:break-word;margin-top:3px}
.extra{margin-top:22px;background:var(--card);border:1px solid var(--border);border-radius:15px;padding:18px;text-align:center}
.nav{display:flex;gap:9px;flex-wrap:wrap;margin-top:24px}
a.btn{display:inline-flex;align-items:center;gap:7px;padding:9px 14px;border-radius:10px;border:1px solid var(--border);background:var(--card);color:var(--text);font-size:13px;font-weight:600;text-decoration:none}
a.btn:hover{border-color:var(--border-hover)}
.banner{padding:13px 16px;border-radius:12px;margin-bottom:20px;font-weight:600;border:1px solid}
.ok{background:rgba(16,185,129,.08);border-color:rgba(16,185,129,.35);color:#a7f3d0}
.bad{background:rgba(239,68,68,.08);border-color:rgba(239,68,68,.35);color:#fecaca}
img.qr{max-width:320px;width:100%;border-radius:12px;background:#fff;padding:8px}
</style></head><body>
<div class="wrap">
  <h1><i data-lucide="activity" style="width:22px;height:22px"></i> <?= $esc($report['title']) ?></h1>
  <?php if ($report['subtitle'] !== ''): ?><div class="sub"><?= $esc($report['subtitle']) ?></div><?php endif; ?>

  <div class="banner <?= $fail === 0 ? 'ok' : 'bad' ?>">
    <?= $fail === 0 ? 'All checks passed.' : ($fail . ' check(s) failed - see details below.') ?>
  </div>

  <div class="summary">
    <div class="sum"><b style="color:var(--success)"><?= $pass ?></b><span>Passed</span></div>
    <div class="sum"><b style="color:var(--danger)"><?= $fail ?></b><span>Failed</span></div>
    <div class="sum"><b style="color:var(--warning)"><?= $skip ?></b><span>Skipped</span></div>
    <div class="sum"><b style="color:var(--accent-light)"><?= count($report['items']) ?></b><span>Total</span></div>
  </div>

  <div class="list">
    <?php foreach ($report['items'] as $item): ?>
      <div class="row">
        <span class="tag <?= $esc($item['status']) ?>"><?= strtoupper($esc($item['status'])) ?></span>
        <div>
          <div class="name"><?= $esc($item['name']) ?></div>
          <?php if ($item['detail'] !== ''): ?><div class="detail"><?= $esc($item['detail']) ?></div><?php endif; ?>
        </div>
      </div>
    <?php endforeach; ?>
  </div>

  <?php foreach ($report['extra'] as $html): ?>
    <div class="extra"><?= $html ?></div>
  <?php endforeach; ?>

  <div class="nav">
    <a class="btn" href="/"><i data-lucide="house" style="width:14px;height:14px"></i> Home</a>
    <a class="btn" href="/test-db.php"><i data-lucide="database" style="width:14px;height:14px"></i> Database</a>
    <a class="btn" href="/test-qr.php"><i data-lucide="qr-code" style="width:14px;height:14px"></i> QR</a>
    <a class="btn" href="/test-imap.php"><i data-lucide="mail" style="width:14px;height:14px"></i> IMAP</a>
    <a class="btn" href="/test-all.php"><i data-lucide="list-checks" style="width:14px;height:14px"></i> Full suite</a>
    <a class="btn" href="?format=json"><i data-lucide="braces" style="width:14px;height:14px"></i> JSON</a>
  </div>
</div>
<script src="https://unpkg.com/lucide@latest"></script>
<script>window.addEventListener('load',function(){if(window.lucide)window.lucide.createIcons();});</script>
</body></html>
    <?php
    exit;
}
