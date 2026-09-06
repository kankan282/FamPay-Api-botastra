<?php
/**
 * /test-qr.php - QR generation + FamPay logo overlay diagnostics.
 * Optional: ?upi=someone@fam&amount=100  ?format=json
 */

declare(strict_types=1);

require_once __DIR__ . '/api/test-ui.php';
require_once __DIR__ . '/api/qr-generator.php';

tests_begin('QR Generation Test', 'Error correction level H, FamPay logo overlay, and multi-amount rendering.');

tests_add(extension_loaded('gd'), 'GD extension loaded', extension_loaded('gd') ? (gd_info()['GD Version'] ?? 'gd') : 'missing - logo overlay impossible');
tests_add(class_exists(\chillerlan\QRCode\QRCode::class), 'chillerlan/php-qrcode available', class_exists(\chillerlan\QRCode\QRCode::class) ? 'local rendering enabled' : 'falling back to api.qrserver.com (run composer install)');

$logo = fampay_logo_image();
tests_add(
    $logo['image'] instanceof GdImage,
    'FamPay logo asset loaded',
    $logo['image'] instanceof GdImage
        ? ($logo['source'] . ' (' . imagesx($logo['image']) . 'x' . imagesy($logo['image']) . ')')
        : (string) $logo['error']
);
if ($logo['image'] instanceof GdImage) {
    imagedestroy($logo['image']);
}

$upi = (string) (param('upi') ?? 'kankan1@fam');
if (!is_valid_upi($upi)) {
    tests_add(false, 'Requested UPI ID valid', $upi);
    $upi = 'kankan1@fam';
}

$preview = null;
foreach ([1.0, 100.0, 10000.0, 100000.0] as $amount) {
    $orderId = 'FAM' . date('Ymd') . 'TESTQR01';
    $payload = build_upi_deeplink($upi, $amount, $orderId);
    $qr = generate_fampay_qr($payload, true);

    if ($qr['png'] === null) {
        tests_add(false, 'QR for amount ' . number_format($amount, 2), (string) $qr['error']);
        continue;
    }
    $img = @imagecreatefromstring($qr['png']);
    $dims = $img instanceof GdImage ? imagesx($img) . 'x' . imagesy($img) : 'unreadable';
    if ($img instanceof GdImage) {
        imagedestroy($img);
    }
    tests_add(
        $qr['has_logo'],
        'QR for amount ' . number_format($amount, 2) . ' (logo embedded)',
        'engine=' . $qr['engine'] . ' size=' . $dims . ' bytes=' . strlen($qr['png']) . ($qr['has_logo'] ? '' : ' | ' . (string) $qr['error'])
    );
    if ($preview === null) {
        $preview = $qr;
    }
}

// UPI ID variations
foreach (['kankan1@fam', 'merchant.store@ybl', 'test-user_9@okaxis'] as $vpa) {
    $ok = is_valid_upi($vpa);
    $qr = $ok ? generate_fampay_qr(build_upi_deeplink($vpa, 250.5, 'FAM' . date('Ymd') . 'TESTVPA1'), true) : null;
    tests_add($ok && $qr !== null && $qr['png'] !== null, 'QR for VPA ' . $vpa, $qr !== null ? ('bytes=' . strlen((string) $qr['png'])) : 'invalid VPA');
}

// Fallback behaviour (logo disabled explicitly)
$plain = generate_fampay_qr(build_upi_deeplink($upi, 50, 'FAM' . date('Ymd') . 'TESTPLN1'), false);
tests_add($plain['png'] !== null && $plain['has_logo'] === false, 'Fallback: plain QR without logo', 'bytes=' . strlen((string) $plain['png']));

// Payload integrity
$deeplink = build_upi_deeplink($upi, 100, 'FAM' . date('Ymd') . 'TESTLNK1');
tests_add(
    str_starts_with($deeplink, 'upi://pay?') && str_contains($deeplink, 'am=100.00') && str_contains($deeplink, 'cu=INR'),
    'UPI deep link built correctly',
    $deeplink
);

if ($preview !== null) {
    tests_html(
        '<div style="color:#94a3b8;font-size:13px;margin-bottom:12px">Live preview - scan with any UPI app (test order, amount 1.00)</div>'
        . '<img class="qr" alt="FamPay QR preview" src="' . htmlspecialchars((string) $preview['base64'], ENT_QUOTES) . '">'
        . '<div style="color:#64748b;font-size:12px;margin-top:10px">engine: ' . htmlspecialchars($preview['engine'], ENT_QUOTES)
        . ' | logo: ' . ($preview['has_logo'] ? 'yes' : 'no') . ' | ecc: H</div>'
    );
}

tests_end();
