<?php
/**
 * GET|POST /qr.php?upi=kankan1@fam&amount=100&api_key=Ab3xKm
 *   -> creates an order and returns a FamPay-logo UPI QR (JSON)
 *
 * GET /qr.php?order_id=FAM...&format=png
 *   -> streams the stored PNG (used by qr_code.image_url)
 */

declare(strict_types=1);

require_once __DIR__ . '/api/helpers.php';
require_once __DIR__ . '/api/qr-generator.php';

// ---------------------------------------------------------------------------
// Image mode: /qr.php?order_id=...&format=png
// ---------------------------------------------------------------------------
$format = strtolower((string) (param('format') ?? ''));
if ($format === 'png' || $format === 'image') {
    try {
        $orderId = (string) (param('order_id') ?? '');
        if (!is_valid_order_id($orderId)) {
            header('Content-Type: application/json');
            api_error('Invalid or missing order_id.', 400, 'INVALID_ORDER_ID');
        }
        db_ensure_schema();
        $order = find_order($orderId);
        if (!$order || empty($order['qr_code_base64'])) {
            header('Content-Type: application/json');
            api_error('QR image not found for this order.', 404, 'NOT_FOUND');
        }
        $b64 = preg_replace('#^data:image/png;base64,#', '', (string) $order['qr_code_base64']) ?? '';
        $bin = base64_decode($b64, true);
        if ($bin === false || $bin === '') {
            header('Content-Type: application/json');
            api_error('Stored QR image is corrupt.', 500, 'INTERNAL_ERROR');
        }
        header('Content-Type: image/png');
        header('Content-Length: ' . strlen($bin));
        header('Cache-Control: public, max-age=900');
        header('X-Content-Type-Options: nosniff');
        echo $bin;
        exit;
    } catch (Throwable $e) {
        error_log('[fampay] qr.php image mode: ' . $e->getMessage());
        header('Content-Type: application/json');
        api_error('Unable to serve the QR image.', 500, 'INTERNAL_ERROR');
    }
}

// ---------------------------------------------------------------------------
// JSON mode: create an order + QR
// ---------------------------------------------------------------------------
api_boot();

try {
    rate_limit('qr', 30, 60);

    db_ensure_schema();
    expire_stale_orders();

    $apiKeyRow = require_master_key(param('api_key'));

    $upi = (string) (param('upi') ?? param('upi_id') ?? '');
    if ($upi === '') {
        api_error('Missing required parameter: upi', 400, 'MISSING_PARAMETER');
    }
    if (!is_valid_upi($upi)) {
        api_error('Invalid UPI ID format. Example: kankan1@fam', 422, 'INVALID_UPI');
    }

    $amountRaw = param('amount');
    if ($amountRaw === null) {
        api_error('Missing required parameter: amount', 400, 'MISSING_PARAMETER');
    }
    $amount = normalise_amount($amountRaw);
    if ($amount === null) {
        api_error('Invalid amount. Allowed range: ' . (int) MIN_AMOUNT . ' - ' . (int) MAX_AMOUNT . ' INR.', 422, 'INVALID_AMOUNT');
    }

    $note    = param('note') !== null ? clean_text(param('note'), 60) : null;
    $withLogo = !in_array(strtolower((string) (param('logo') ?? '1')), ['0', 'false', 'no'], true);

    $orderId  = generate_order_id();
    $deeplink = build_upi_deeplink($upi, $amount, $orderId, $note);

    $qr = generate_fampay_qr($deeplink, $withLogo);
    if ($qr['png'] === null) {
        log_action($orderId, 'qr_failed', (string) $apiKeyRow['master_key'], ['upi' => $upi, 'amount' => $amount], $qr['error']);
        api_error((string) $qr['error'], 502, 'QR_GENERATION_FAILED');
    }

    $imageUrl = app_url() . '/qr.php?order_id=' . $orderId . '&format=png';

    $stmt = db()->prepare(
        'INSERT INTO orders (order_id, upi_id, amount, qr_code_url, qr_code_base64, qr_has_logo, status, api_key)
         VALUES (:oid, :upi, :amt, :url, :b64, :logo, :status, :key)
         RETURNING created_at'
    );
    $stmt->execute([
        ':oid'    => $orderId,
        ':upi'    => $upi,
        ':amt'    => number_format($amount, 2, '.', ''),
        ':url'    => $imageUrl,
        ':b64'    => $qr['base64'],
        ':logo'   => $qr['has_logo'] ? 'true' : 'false',
        ':status' => 'pending',
        ':key'    => (string) $apiKeyRow['master_key'],
    ]);
    $row = $stmt->fetch() ?: [];
    $createdAt = strtotime((string) ($row['created_at'] ?? 'now')) ?: time();
    $expiresAt = $createdAt + (ORDER_EXPIRY_MINUTES * 60);

    $response = [
        'order_id' => $orderId,
        'upi_id'   => $upi,
        'amount'   => $amount,
        'status'   => 'pending',
        'qr_code'  => [
            'image_url'        => $imageUrl,
            'base64'           => $qr['base64'],
            'upi_deeplink'     => $deeplink,
            'has_fampay_logo'  => $qr['has_logo'],
            'engine'           => $qr['engine'],
            'size'             => $qr['size'] . 'x' . $qr['size'],
            'error_correction' => 'H',
        ],
        'expires_in_minutes' => ORDER_EXPIRY_MINUTES,
        'expires_at'         => date('Y-m-d H:i:s', $expiresAt),
        'created_at'         => date('Y-m-d H:i:s', $createdAt),
        'verify_url'         => app_url() . '/verify.php?order_id=' . $orderId
            . '&api_key=' . rawurlencode((string) $apiKeyRow['master_key']) . '&gmail_key=YOUR_GMAIL_KEY',
    ];

    if (!$qr['has_logo'] && $withLogo) {
        $response['qr_code']['logo_warning'] = 'Logo overlay unavailable - plain QR returned. ' . (string) $qr['error'];
    }

    log_action($orderId, 'qr_created', (string) $apiKeyRow['master_key'], ['upi' => $upi, 'amount' => $amount], [
        'has_logo' => $qr['has_logo'],
        'engine'   => $qr['engine'],
    ]);

    api_success($response, 201);
} catch (RuntimeException $e) {
    api_error($e->getMessage(), 503, 'SERVICE_UNAVAILABLE');
} catch (Throwable $e) {
    error_log('[fampay] qr.php: ' . $e->getMessage());
    api_error('Internal server error while generating the QR code.', 500, 'INTERNAL_ERROR');
}
