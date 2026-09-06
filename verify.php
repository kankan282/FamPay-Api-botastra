<?php
/**
 * GET|POST /verify.php?order_id=FAM...&api_key=Ab3xKm&gmail_key=Xt7pQw
 *
 * Checks the order state and (for pending orders) scans the linked Gmail inbox
 * over IMAP for a matching UPI credit.
 */

declare(strict_types=1);

require_once __DIR__ . '/api/helpers.php';

api_boot();

try {
    rate_limit('verify', 60, 60);

    db_ensure_schema();
    $master = require_master_key(param('api_key'));

    $orderId = (string) (param('order_id') ?? '');
    if ($orderId === '') {
        api_error('Missing required parameter: order_id', 400, 'MISSING_PARAMETER');
    }
    if (!is_valid_order_id($orderId)) {
        api_error('Invalid order_id format. Expected FAM + YYYYMMDD + 8 characters.', 422, 'INVALID_ORDER_ID');
    }

    $order = find_order($orderId);
    if (!$order) {
        api_error('Order not found.', 404, 'ORDER_NOT_FOUND');
    }

    $expiresAt = order_expiry_time($order);
    $status    = (string) $order['status'];

    // Already settled -> return the stored result immediately.
    if ($status === 'success') {
        $details = [];
        if (!empty($order['payment_details'])) {
            $decoded = json_decode((string) $order['payment_details'], true);
            if (is_array($decoded)) {
                $details = $decoded;
            }
        }
        api_success([
            'order_id'        => $orderId,
            'status'          => 'SUCCESS',
            'payment_found'   => true,
            'amount'          => (float) $order['amount'],
            'payment_details' => [
                'utr'          => $order['utr_number'],
                'payer_name'   => $order['payer_name'],
                'payer_upi'    => $order['payer_upi'],
                'payment_date' => $order['payment_date'],
                'verified_at'  => $details['verified_at'] ?? (string) $order['updated_at'],
            ],
        ]);
    }

    // Expired (either flagged already or past the window).
    if ($status === 'expired' || ($status === 'pending' && time() > $expiresAt)) {
        if ($status === 'pending') {
            $upd = db()->prepare("UPDATE orders SET status = 'expired' WHERE order_id = :oid AND status = 'pending'");
            $upd->execute([':oid' => $orderId]);
        }
        log_action($orderId, 'verify_expired', (string) $master['master_key'], null, ['status' => 'EXPIRED']);
        api_error('This order expired after ' . ORDER_EXPIRY_MINUTES . ' minutes.', 410, 'ORDER_EXPIRED', [
            'data' => [
                'order_id'      => $orderId,
                'status'        => 'EXPIRED',
                'payment_found' => false,
                'amount'        => (float) $order['amount'],
                'expired_at'    => date('Y-m-d H:i:s', $expiresAt),
            ],
        ]);
    }

    if ($status === 'failed') {
        api_error('This order is marked as failed.', 409, 'ORDER_FAILED', [
            'data' => ['order_id' => $orderId, 'status' => 'FAILED', 'payment_found' => false],
        ]);
    }

    // Pending -> scan Gmail.
    $gmailRow = require_gmail_key(param('gmail_key'));

    if (!imap_available()) {
        api_error(
            'The PHP IMAP extension is not enabled on this server. Deploy with the bundled Dockerfile, which installs ext-imap.',
            503,
            'IMAP_UNAVAILABLE'
        );
    }

    $appPassword = decrypt_secret((string) $gmailRow['app_password']);
    if ($appPassword === '') {
        api_error('Stored Gmail credentials could not be decrypted. Please run /login.php again.', 409, 'CREDENTIALS_INVALID');
    }

    $scan = scan_inbox_for_payment((string) $gmailRow['gmail'], $appPassword, $order);

    if ($scan['error'] !== null) {
        log_action($orderId, 'verify_imap_error', (string) $master['master_key'], null, $scan['error']);
        api_error('Gmail scan failed: ' . $scan['error'], 502, 'IMAP_ERROR');
    }

    if (!$scan['found']) {
        $remaining = max(0, $expiresAt - time());
        log_action($orderId, 'verify_pending', (string) $master['master_key'], null, ['scanned' => $scan['scanned']]);
        json_out([
            'success' => true,
            'data'    => [
                'order_id'         => $orderId,
                'status'           => 'PENDING',
                'payment_found'    => false,
                'amount'           => (float) $order['amount'],
                'emails_scanned'   => $scan['scanned'],
                'gmail'            => (string) $gmailRow['gmail'],
                'expires_at'       => date('Y-m-d H:i:s', $expiresAt),
                'seconds_remaining'=> $remaining,
                'message'          => 'No matching payment e-mail yet. Retry in a few seconds.',
            ],
            'timestamp' => date('Y-m-d H:i:s'),
        ], 200);
    }

    // Payment found -> settle the order.
    $payment = $scan['payment'];
    $verifiedAt = date('Y-m-d H:i:s');
    $details = [
        'utr'          => $payment['utr'],
        'payer_name'   => $payment['payer_name'],
        'payer_upi'    => $payment['payer_upi'],
        'payment_date' => $payment['payment_date'],
        'verified_at'  => $verifiedAt,
        'email_subject'=> $payment['subject'],
        'gmail'        => (string) $gmailRow['gmail'],
    ];

    $upd = db()->prepare(
        "UPDATE orders
            SET status = 'success',
                utr_number = :utr,
                payer_name = :pname,
                payer_upi = :pupi,
                payment_date = :pdate,
                payment_details = CAST(:details AS JSONB)
          WHERE order_id = :oid AND status = 'pending'"
    );
    $upd->execute([
        ':utr'     => substr((string) $payment['utr'], 0, 50),
        ':pname'   => $payment['payer_name'] !== null ? substr((string) $payment['payer_name'], 0, 100) : null,
        ':pupi'    => $payment['payer_upi'] !== null ? substr((string) $payment['payer_upi'], 0, 100) : null,
        ':pdate'   => $payment['payment_date'],
        ':details' => json_encode($details, JSON_UNESCAPED_SLASHES),
        ':oid'     => $orderId,
    ]);

    log_action($orderId, 'verify_success', (string) $master['master_key'], null, $details);

    api_success([
        'order_id'        => $orderId,
        'status'          => 'SUCCESS',
        'payment_found'   => true,
        'amount'          => (float) $order['amount'],
        'payment_details' => [
            'utr'          => $payment['utr'],
            'payer_name'   => $payment['payer_name'],
            'payer_upi'    => $payment['payer_upi'],
            'payment_date' => $payment['payment_date'],
            'verified_at'  => $verifiedAt,
        ],
    ]);
} catch (RuntimeException $e) {
    api_error($e->getMessage(), 503, 'SERVICE_UNAVAILABLE');
} catch (Throwable $e) {
    error_log('[fampay] verify.php: ' . $e->getMessage());
    api_error('Internal server error during verification.', 500, 'INTERNAL_ERROR');
}
