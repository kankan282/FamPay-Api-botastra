<?php
/**
 * FamPay Payment Gateway - QR generator with FamPay logo overlay
 * Author: @lazzy_guy
 *
 * Strategy:
 *   1. Render the QR locally with chillerlan/php-qrcode (ECC level H) - offline, fast.
 *   2. If the library is unavailable, fall back to api.qrserver.com (ecc=H).
 *   3. Overlay the FamPay logo on a white circle in the exact centre (GD).
 *   4. If the logo cannot be loaded, log it and return the plain QR (still valid).
 */

declare(strict_types=1);

require_once __DIR__ . '/../config.php';

if (is_file(__DIR__ . '/../vendor/autoload.php')) {
    require_once __DIR__ . '/../vendor/autoload.php';
}

/**
 * Locate the FamPay logo and return it as a GD image (with alpha).
 *
 * @return array{image:\GdImage|null,source:string,error:string|null}
 */
function fampay_logo_image(): array
{
    static $cache = null;
    if ($cache !== null && $cache['image'] instanceof GdImage) {
        // Always hand back a private copy so callers may destroy it safely.
        $copy = fampay_clone_image($cache['image']);
        return ['image' => $copy, 'source' => $cache['source'], 'error' => null];
    }

    $errors = [];

    // 1) bundled PNG asset
    $localPng = __DIR__ . '/../assets/fampay-logo.png';
    if (is_file($localPng)) {
        $img = @imagecreatefrompng($localPng);
        if ($img instanceof GdImage) {
            imagealphablending($img, false);
            imagesavealpha($img, true);
            $cache = ['image' => $img, 'source' => 'assets/fampay-logo.png'];
            return ['image' => fampay_clone_image($img), 'source' => $cache['source'], 'error' => null];
        }
        $errors[] = 'assets/fampay-logo.png could not be decoded';
    } else {
        $errors[] = 'assets/fampay-logo.png missing';
    }

    // 2) bundled base64 backup
    $b64File = __DIR__ . '/../assets/fampay-logo-base64.txt';
    if (is_file($b64File)) {
        $raw = trim((string) file_get_contents($b64File));
        $raw = preg_replace('#^data:image/[a-z\+]+;base64,#i', '', $raw) ?? $raw;
        $bin = base64_decode($raw, true);
        if ($bin !== false && $bin !== '') {
            $img = @imagecreatefromstring($bin);
            if ($img instanceof GdImage) {
                imagealphablending($img, false);
                imagesavealpha($img, true);
                $cache = ['image' => $img, 'source' => 'assets/fampay-logo-base64.txt'];
                return ['image' => fampay_clone_image($img), 'source' => $cache['source'], 'error' => null];
            }
        }
        $errors[] = 'base64 asset could not be decoded';
    }

    // 3) remote sources (cached to disk once fetched)
    $cacheFile = APP_TMP_DIR . '/fampay-logo-remote.png';
    if (is_file($cacheFile) && filesize($cacheFile) > 100) {
        $img = @imagecreatefromstring((string) file_get_contents($cacheFile));
        if ($img instanceof GdImage) {
            $cache = ['image' => $img, 'source' => 'remote-cache'];
            return ['image' => fampay_clone_image($img), 'source' => 'remote-cache', 'error' => null];
        }
    }
    foreach (fampay_remote_logo_sources() as $url) {
        $bin = fampay_http_get($url, 8);
        if ($bin === null || strlen($bin) < 100) {
            $errors[] = 'fetch failed: ' . $url;
            continue;
        }
        $img = @imagecreatefromstring($bin);
        if ($img instanceof GdImage) {
            @file_put_contents($cacheFile, $bin);
            $cache = ['image' => $img, 'source' => $url];
            return ['image' => fampay_clone_image($img), 'source' => $url, 'error' => null];
        }
        $errors[] = 'decode failed: ' . $url;
    }

    return ['image' => null, 'source' => 'none', 'error' => implode(' | ', $errors)];
}

/** @return string[] */
function fampay_remote_logo_sources(): array
{
    $extra = env_value('FAMPAY_LOGO_URL');
    $list = [
        'https://fampay.in/assets/images/logo.png',
        'https://play-lh.googleusercontent.com/kGLD4tKgc1RRAv9GkV5DFsRArftLXjLlnEBEBIkNPHrqLZBgAmqSj9d3-QJ8dLKQr1o=s180',
    ];
    if ($extra) {
        array_unshift($list, $extra);
    }
    return $list;
}

/** GD has no rounded rectangle primitive - build one from a rect + 4 discs. */
function fampay_filled_rounded_rect(GdImage $img, int $x1, int $y1, int $x2, int $y2, int $radius, int $color): void
{
    $radius = max(0, min($radius, (int) floor(min($x2 - $x1, $y2 - $y1) / 2)));
    imagefilledrectangle($img, $x1 + $radius, $y1, $x2 - $radius, $y2, $color);
    imagefilledrectangle($img, $x1, $y1 + $radius, $x2, $y2 - $radius, $color);
    $d = $radius * 2;
    imagefilledellipse($img, $x1 + $radius, $y1 + $radius, $d, $d, $color);
    imagefilledellipse($img, $x2 - $radius, $y1 + $radius, $d, $d, $color);
    imagefilledellipse($img, $x1 + $radius, $y2 - $radius, $d, $d, $color);
    imagefilledellipse($img, $x2 - $radius, $y2 - $radius, $d, $d, $color);
}

/** Thin outline for the rounded badge. */
function fampay_rounded_rect_outline(GdImage $img, int $x1, int $y1, int $x2, int $y2, int $radius, int $color): void
{
    $radius = max(0, min($radius, (int) floor(min($x2 - $x1, $y2 - $y1) / 2)));
    imageline($img, $x1 + $radius, $y1, $x2 - $radius, $y1, $color);
    imageline($img, $x1 + $radius, $y2, $x2 - $radius, $y2, $color);
    imageline($img, $x1, $y1 + $radius, $x1, $y2 - $radius, $color);
    imageline($img, $x2, $y1 + $radius, $x2, $y2 - $radius, $color);
    $d = $radius * 2;
    imagearc($img, $x1 + $radius, $y1 + $radius, $d, $d, 180, 270, $color);
    imagearc($img, $x2 - $radius, $y1 + $radius, $d, $d, 270, 360, $color);
    imagearc($img, $x1 + $radius, $y2 - $radius, $d, $d, 90, 180, $color);
    imagearc($img, $x2 - $radius, $y2 - $radius, $d, $d, 0, 90, $color);
}

function fampay_clone_image(GdImage $src): GdImage
{
    $w = imagesx($src);
    $h = imagesy($src);
    $dst = imagecreatetruecolor($w, $h);
    imagealphablending($dst, false);
    imagesavealpha($dst, true);
    $transparent = imagecolorallocatealpha($dst, 0, 0, 0, 127);
    imagefilledrectangle($dst, 0, 0, $w, $h, $transparent);
    imagecopy($dst, $src, 0, 0, 0, 0, $w, $h);
    return $dst;
}

/** Minimal HTTP GET that works with or without cURL. */
function fampay_http_get(string $url, int $timeout = 10): ?string
{
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => $timeout,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_USERAGENT      => 'FamPayGateway/2.0',
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if (is_string($body) && $body !== '' && $code >= 200 && $code < 300) {
            return $body;
        }
        return null;
    }

    $ctx = stream_context_create(['http' => ['timeout' => $timeout, 'user_agent' => 'FamPayGateway/2.0']]);
    $body = @file_get_contents($url, false, $ctx);
    return is_string($body) && $body !== '' ? $body : null;
}

/**
 * Render the raw QR PNG (no logo yet).
 *
 * @return array{png:string|null,engine:string,error:string|null}
 */
function fampay_render_qr_png(string $payload, int $size): array
{
    // --- preferred: local library ---------------------------------------
    if (class_exists(\chillerlan\QRCode\QRCode::class)) {
        try {
            $options = new \chillerlan\QRCode\QROptions([
                'outputType'         => \chillerlan\QRCode\Output\QROutputInterface::GDIMAGE_PNG,
                'eccLevel'           => \chillerlan\QRCode\Common\EccLevel::H,
                'version'            => \chillerlan\QRCode\Common\Version::AUTO,
                'scale'              => 12,
                'imageBase64'        => false,
                'addQuietzone'       => true,
                'quietzoneSize'      => 4,
                'bgColor'            => [255, 255, 255],
                'imageTransparent'   => false,
            ]);
            $png = (new \chillerlan\QRCode\QRCode($options))->render($payload);
            if (is_string($png) && $png !== '') {
                $png = fampay_resize_png($png, $size);
                return ['png' => $png, 'engine' => 'chillerlan/php-qrcode', 'error' => null];
            }
        } catch (Throwable $e) {
            error_log('[fampay] chillerlan QR render failed: ' . $e->getMessage());
        }
    }

    // --- fallback: remote QR service -------------------------------------
    $url = 'https://api.qrserver.com/v1/create-qr-code/?' . http_build_query([
        'size'           => $size . 'x' . $size,
        'ecc'            => 'H',
        'margin'         => 8,
        'format'         => 'png',
        'qzone'          => 2,
        'data'           => $payload,
    ]);
    $png = fampay_http_get($url, 12);
    if ($png !== null && str_starts_with($png, "\x89PNG")) {
        return ['png' => $png, 'engine' => 'api.qrserver.com', 'error' => null];
    }

    return [
        'png'    => null,
        'engine' => 'none',
        'error'  => 'QR rendering failed: composer dependencies missing and api.qrserver.com unreachable.',
    ];
}

/** Resize a PNG (square) to the requested pixel size. */
function fampay_resize_png(string $png, int $size): string
{
    $img = @imagecreatefromstring($png);
    if (!$img instanceof GdImage) {
        return $png;
    }
    if (imagesx($img) === $size) {
        imagedestroy($img);
        return $png;
    }
    $resized = imagecreatetruecolor($size, $size);
    $white = imagecolorallocate($resized, 255, 255, 255);
    imagefilledrectangle($resized, 0, 0, $size, $size, $white);
    imagecopyresampled($resized, $img, 0, 0, 0, 0, $size, $size, imagesx($img), imagesy($img));
    ob_start();
    imagepng($resized, null, 9);
    $out = (string) ob_get_clean();
    imagedestroy($img);
    imagedestroy($resized);
    return $out;
}

/**
 * Overlay the FamPay logo (white circle behind it) in the centre of a QR PNG.
 *
 * @return array{png:string,has_logo:bool,logo_source:string,error:string|null}
 */
function fampay_overlay_logo(string $qrPng): array
{
    $qr = @imagecreatefromstring($qrPng);
    if (!$qr instanceof GdImage) {
        return ['png' => $qrPng, 'has_logo' => false, 'logo_source' => 'none', 'error' => 'QR image could not be decoded for logo overlay.'];
    }

    $logoData = fampay_logo_image();
    if (!$logoData['image'] instanceof GdImage) {
        imagedestroy($qr);
        error_log('[fampay] logo overlay skipped: ' . (string) $logoData['error']);
        return ['png' => $qrPng, 'has_logo' => false, 'logo_source' => 'none', 'error' => $logoData['error']];
    }
    $logo = $logoData['image'];

    try {
        $qrWidth  = imagesx($qr);
        $qrHeight = imagesy($qr);

        // Sizes scale with the QR so custom QR_SIZE values keep the 20%/25% ratio.
        $logoSize = (int) round($qrWidth * (QR_LOGO_SIZE / 400));
        $bgSize   = (int) round($qrWidth * (QR_LOGO_BG_SIZE / 400));
        $logoSize = max(24, min($logoSize, (int) ($qrWidth * 0.26)));
        $bgSize   = max($logoSize + 8, min($bgSize, (int) ($qrWidth * 0.32)));

        imagealphablending($qr, true);
        imagesavealpha($qr, true);

        // White badge behind the logo (better contrast + scanability).
        // 'rounded' suits square/photo logos, 'circle' suits round marks.
        $cx = (int) round($qrWidth / 2);
        $cy = (int) round($qrHeight / 2);
        $white = imagecolorallocate($qr, 255, 255, 255);
        $ring  = imagecolorallocatealpha($qr, 203, 213, 225, 40);

        if (function_exists('imageantialias')) {
            @imageantialias($qr, true);
        }

        if (QR_LOGO_SHAPE === 'circle') {
            imagefilledellipse($qr, $cx, $cy, $bgSize, $bgSize, $white);
            imageellipse($qr, $cx, $cy, $bgSize, $bgSize, $ring);
        } else {
            $half   = (int) round($bgSize / 2);
            $radius = (int) round($bgSize * 0.24);
            fampay_filled_rounded_rect($qr, $cx - $half, $cy - $half, $cx + $half, $cy + $half, $radius, $white);
            fampay_rounded_rect_outline($qr, $cx - $half, $cy - $half, $cx + $half, $cy + $half, $radius, $ring);
        }

        // Scale + centre the logo.
        $dstX = (int) round(($qrWidth - $logoSize) / 2);
        $dstY = (int) round(($qrHeight - $logoSize) / 2);
        imagecopyresampled($qr, $logo, $dstX, $dstY, 0, 0, $logoSize, $logoSize, imagesx($logo), imagesy($logo));

        ob_start();
        imagepng($qr, null, 9);
        $out = (string) ob_get_clean();

        return ['png' => $out, 'has_logo' => true, 'logo_source' => $logoData['source'], 'error' => null];
    } catch (Throwable $e) {
        error_log('[fampay] logo overlay failed: ' . $e->getMessage());
        return ['png' => $qrPng, 'has_logo' => false, 'logo_source' => 'none', 'error' => $e->getMessage()];
    } finally {
        if ($logo instanceof GdImage) {
            imagedestroy($logo);
        }
        if ($qr instanceof GdImage) {
            imagedestroy($qr);
        }
    }
}

/**
 * Full pipeline: payload -> QR (ECC H) -> FamPay logo overlay -> PNG + base64.
 *
 * @return array{
 *   png:string|null, base64:string|null, has_logo:bool, engine:string,
 *   logo_source:string, size:int, error:string|null
 * }
 */
function generate_fampay_qr(string $payload, bool $withLogo = true, ?int $size = null): array
{
    $size = $size ?? QR_SIZE;
    $size = max(200, min(1000, $size));

    $rendered = fampay_render_qr_png($payload, $size);
    if ($rendered['png'] === null) {
        return [
            'png'         => null,
            'base64'      => null,
            'has_logo'    => false,
            'engine'      => $rendered['engine'],
            'logo_source' => 'none',
            'size'        => $size,
            'error'       => $rendered['error'],
        ];
    }

    $png = $rendered['png'];
    $hasLogo = false;
    $logoSource = 'none';
    $error = null;

    if ($withLogo && extension_loaded('gd')) {
        $overlay = fampay_overlay_logo($png);
        $png = $overlay['png'];
        $hasLogo = $overlay['has_logo'];
        $logoSource = $overlay['logo_source'];
        $error = $overlay['error'];
    } elseif ($withLogo) {
        $error = 'GD extension not available - logo overlay skipped.';
    }

    return [
        'png'         => $png,
        'base64'      => 'data:image/png;base64,' . base64_encode($png),
        'has_logo'    => $hasLogo,
        'engine'      => $rendered['engine'],
        'logo_source' => $logoSource,
        'size'        => $size,
        'error'       => $error,
    ];
}
