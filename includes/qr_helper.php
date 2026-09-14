<?php
/* QR helper: get_student_qr(id) returns a cached PNG web path for a student QR (phpqrcode offline, else qrserver API); qr_file_path(id) gives the disk path. */

function qr_generated_dir() {
    // Absolute path to qr/generated relative to THIS file (includes/)
    $dir = __DIR__ . '/../qr/generated/';
    if (!is_dir($dir)) {
        @mkdir($dir, 0777, true);
    }
    return $dir;
}

function qr_file_path($studentId) {
    $safe = preg_replace('/[^A-Za-z0-9_\-]/', '_', $studentId);
    return qr_generated_dir() . $safe . '.png';
}

/**
 * Ensure the QR png exists on disk. Returns absolute path on success,
 * or null on failure.
 */
function ensure_student_qr_file($studentId, $payload = null) {
    $data = $payload !== null ? $payload : $studentId;
    if ($studentId === null || $studentId === '') {
        return null;
    }

    $file = qr_file_path($studentId);
    if (file_exists($file) && filesize($file) > 0) {
        return $file;
    }

    // ---- Option 1: phpqrcode (offline) ----
    $lib = __DIR__ . '/../qr/phpqrcode/qrlib.php';
    if (file_exists($lib)) {
        require_once $lib;
        if (class_exists('QRcode')) {
            try {
                QRcode::png($data, $file, QR_ECLEVEL_H, 8, 2);
                if (file_exists($file) && filesize($file) > 0) {
                    return $file;
                }
            } catch (Throwable $e) {
                error_log('SVTMS QR (phpqrcode) failed: ' . $e->getMessage());
            }
        }
    }

    // ---- Option 2: api.qrserver.com (needs server internet) ----
    $url = 'https://api.qrserver.com/v1/create-qr-code/?size=300x300&data=' . urlencode($data);
    $data = @file_get_contents($url);
    if ($data !== false && strlen($data) > 100) {
        if (@file_put_contents($file, $data) !== false) {
            return $file;
        }
    }

    return null;
}

/**
 * Returns a WEB path (for use in <img src>) to the QR png, relative to
 * the project root. $webPrefix is prepended (e.g. "../" from /student).
 * $payload, when given, is what gets encoded (e.g. "ID|Name|Year|Course"
 * so scanners can show the student's name/year/course without needing a
 * roster loaded). Only used the first time the file is generated — once
 * cached, later calls return the same file regardless of $payload.
 * Returns null if generation failed.
 */
function get_student_qr($studentId, $webPrefix = '../', $payload = null) {
    $file = ensure_student_qr_file($studentId, $payload);
    if (!$file) {
        return null;
    }
    $name = basename($file);
    return $webPrefix . 'qr/generated/' . $name;
}
