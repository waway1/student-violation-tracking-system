<?php
/* Mailer: sends via Gmail SMTP (PHPMailer) on localhost, or the Brevo HTTP API on a live host, so registration email never breaks. */

/* True when the site is running on your own computer (XAMPP, Laragon,
   WAMP, ...) rather than the live InfinityFree host. This was a second
   copy of config/database.php's check; both now call the one function in
   config/env.php, so they cannot drift apart again. */
require_once __DIR__ . '/../config/env.php';
function mail_is_local() {
    /* One answer, shared with config/database.php. It used to be a second
       copy of the HTTP_HOST test, which meant a tunnelled request did not
       just pick the wrong DATABASE -- it also decided mail should behave
       as if it were on the live host. */
    return vts_is_local_env();
}

/* Has the mail API already proved unreachable during THIS request?

   The per-call timeouts below cap ONE send at 4s to connect. That is fine for
   one email and miserable for fifty: an offline server running a bulk action
   (importing a roster, mailing a shift report to every OSA account) paid the
   full 4s for every recipient, turning a working offline operation into a
   request that looked hung and could time out halfway through.

   The first connection failure is proof enough for the rest of the request —
   the internet does not come back mid-loop — so every later send in the same
   request gives up instantly. Cleared naturally: it is per-request state, so
   the next page load tries the network again and recovers by itself the
   moment there is a connection. */
function vts_mail_net_down($set = null) {
    static $down = false;
    if ($set === true) $down = true;
    return $down;
}

/* Send an email via the Brevo HTTP API. Returns true on success.
   $attachment: optional ['name'=>..,'content_base64'=>..] for the QR. */
function brevo_send($toEmail, $toName, $subject, $htmlBody, $attachment = null) {
    if (!defined('BREVO_API_KEY') || strpos(BREVO_API_KEY, 'REPLACE') !== false) {
        error_log('Brevo: API key not configured.');
        return false;
    }
    if (vts_mail_net_down()) return false;   // already established: no route out
    $payload = [
        'sender'      => ['name' => BREVO_SENDER_NAME, 'email' => BREVO_SENDER_EMAIL],
        'to'          => [['email' => $toEmail, 'name' => $toName]],
        'subject'     => $subject,
        'htmlContent' => $htmlBody,
    ];
    if ($attachment && !empty($attachment['content_base64'])) {
        $payload['attachment'] = [[
            'content' => $attachment['content_base64'],
            'name'    => $attachment['name'] ?? 'attachment.png',
        ]];
    }
    $ch = curl_init('https://api.brevo.com/v3/smtp/email');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => [
            'accept: application/json',
            'content-type: application/json',
            'api-key: ' . BREVO_API_KEY,
        ],
        CURLOPT_POSTFIELDS     => json_encode($payload),
        // Hard caps so a slow/unreachable mail API can NEVER hang a page — this
        // is what let a bulk import freeze the whole request (and lock the DB).
        CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_TIMEOUT        => 8,
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if (curl_errno($ch)) {
        $errno = curl_errno($ch);
        error_log('Brevo curl error: ' . curl_error($ch));
        curl_close($ch);
        /* Only a genuine "cannot get there" marks the network down. A rejected
           API key or a malformed payload is about THIS message and says
           nothing about the next one, so it must not silence the rest of a
           batch. These four are the reach-the-host failures:
             6 COULDNT_RESOLVE_HOST   7 COULDNT_CONNECT
            28 OPERATION_TIMEDOUT    35 SSL_CONNECT_ERROR */
        if (in_array($errno, [6, 7, 28, 35], true)) vts_mail_net_down(true);
        return false;
    }
    curl_close($ch);
    if ($code >= 200 && $code < 300) return true;
    error_log('Brevo send failed (HTTP ' . $code . '): ' . $resp);
    return false;
}

/* =====================================================================
   MAILER HELPER  -  sends the welcome email with the student's QR code
   =====================================================================
   Provides one function:

      send_welcome_qr_email($toEmail, $fullName, $studentId, $qrFilePath)

   Returns true on success, false on failure (it never throws, so a mail
   problem can't crash registration). It quietly no-ops if MAIL_ENABLED
   is false or the PHPMailer library isn't installed yet.
   ===================================================================== */

require_once __DIR__ . '/../config/mail.php';

/* =====================================================================
   UNIFIED SENDER — one way to send mail from anywhere in the app.
   Preference:
     1. Brevo HTTP API — needs only BREVO_API_KEY + cURL, NO PHPMailer,
        and works on localhost too (SMTP is usually blocked/unconfigured).
     2. Gmail/SMTP via PHPMailer — only if the library is actually installed.
     3. Give up quietly (logged) so mail problems never break a request.
   ===================================================================== */
function vts_mail_provider() {
    if (defined('BREVO_API_KEY') && strpos(BREVO_API_KEY, 'REPLACE') === false
        && trim(BREVO_API_KEY) !== '' && function_exists('curl_init')) {
        return 'brevo';
    }
    $autoload = __DIR__ . '/../vendor/autoload.php';
    $manual   = __DIR__ . '/../PHPMailer/src';
    if (file_exists($autoload)) { require_once $autoload; }
    elseif (file_exists($manual . '/PHPMailer.php')) {
        require_once $manual . '/Exception.php';
        require_once $manual . '/PHPMailer.php';
        require_once $manual . '/SMTP.php';
    }
    if (class_exists('PHPMailer\\PHPMailer\\PHPMailer')
        && defined('MAIL_USERNAME') && strpos(MAIL_USERNAME, 'REPLACE') === false) {
        return 'smtp';
    }
    return 'none';
}

/* Send one HTML email. $attachment (optional): ['name'=>..,'content_base64'=>..]
   Returns true only when a provider accepted it. Never throws. */
function vts_send_mail($toEmail, $toName, $subject, $htmlBody, $attachment = null) {
    if (!defined('MAIL_ENABLED') || MAIL_ENABLED !== true) return false;
    $toEmail = trim((string)$toEmail);
    if (!filter_var($toEmail, FILTER_VALIDATE_EMAIL)) return false;
    // Never mail the auto-generated placeholder addresses.
    if (stripos($toEmail, '@student.gwc.local') !== false) return false;

    switch (vts_mail_provider()) {
        case 'brevo':
            return brevo_send($toEmail, $toName, $subject, $htmlBody, $attachment);
        case 'smtp':
            try {
                $mail = new PHPMailer\PHPMailer\PHPMailer(true);
                $mail->isSMTP();
                $mail->Host       = MAIL_HOST;
                $mail->SMTPAuth   = true;
                $mail->Username   = MAIL_USERNAME;
                $mail->Password   = MAIL_PASSWORD;
                $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
                $mail->Port       = MAIL_PORT;
                /* PHPMailer defaults Timeout to 300 SECONDS. Offline that is
                   not a timeout, it is a hang: the request sits for five
                   minutes on a dead connection to smtp.gmail.com before it
                   gives up, and the browser gives up first. Registration,
                   the sign-in OTP and password reset all send mail, so the
                   whole login path stalled on a server that was otherwise
                   working perfectly without internet. Capped so a send can
                   never cost more than a few seconds. */
                $mail->Timeout    = 6;
                $mail->SMTPKeepAlive = false;
                $mail->setFrom(MAIL_FROM_EMAIL, MAIL_FROM_NAME);
                $mail->addAddress($toEmail, $toName);
                if ($attachment && !empty($attachment['content_base64'])) {
                    $mail->addStringAttachment(base64_decode($attachment['content_base64']),
                        $attachment['name'] ?? 'attachment');
                }
                $mail->isHTML(true);
                $mail->Subject = $subject;
                $mail->Body    = $htmlBody;
                $mail->AltBody = strip_tags($htmlBody);
                $mail->send();
                return true;
            } catch (Throwable $e) {
                error_log('VTS mail (SMTP) failed: ' . $e->getMessage());
                return false;
            }
        default:
            error_log('VTS mail: no provider configured (set BREVO_API_KEY, or install PHPMailer + set MAIL_USERNAME).');
            return false;
    }
}

/* Wrap any message body in the app's shared email look. */
function vts_mail_shell($headline, $innerHtml) {
    return '
    <div style="font-family:Arial,Helvetica,sans-serif;max-width:560px;margin:auto;border:1px solid #e6e6e6;border-radius:10px;overflow:hidden;">
      <div style="background:linear-gradient(90deg,#1a3a6b,#2a5298 55%,#c9a227);padding:18px 22px;color:#fff;">
        <div style="font-size:18px;font-weight:bold;">VIOLATION TRACKING SYSTEM</div>
        <div style="font-size:12px;opacity:.85;">Golden West Colleges, Inc.</div>
      </div>
      <div style="padding:22px;color:#222;">
        <h2 style="margin:0 0 12px;font-size:17px;color:#1a3a6b;">' . htmlspecialchars($headline) . '</h2>
        ' . $innerHtml . '
        <p style="font-size:12px;color:#999;margin-top:22px;">This is an automated message. Please do not reply.</p>
      </div>
    </div>';
}

/**
 * Email the STUDENT about a violation, with escalating tone (1st / 2nd /
 * final / limit exceeded). Silently skips students who only have the
 * auto-generated placeholder address. Never throws.
 */
function send_student_violation_email($conn, $studentRowId, $violationName, $severity, $activeCount) {
    try {
        $st = $conn->prepare("SELECT fullname, email, student_id FROM users WHERE id = :id LIMIT 1");
        $st->execute([':id' => (int)$studentRowId]);
        $s = $st->fetch(PDO::FETCH_ASSOC);
        if (!$s) return false;

        $email = trim((string)($s['email'] ?? ''));
        if ($email === '' || stripos($email, '@student.gwc.local') !== false) return false;  // no real address

        [$title, $msg] = function_exists('vts_offense_warning_text')
            ? vts_offense_warning_text($activeCount)
            : ['Violation Recorded', 'A violation was recorded on your account.'];

        $sev  = htmlspecialchars((string)$severity);
        $vio  = htmlspecialchars((string)$violationName);
        $cnt  = (int)$activeCount;
        $sevColor = $severity === 'Grave' ? '#b0122b' : ($severity === 'Major' ? '#c47f00' : '#2a7a4b');

        $inner = '
          <p style="font-size:14px;line-height:1.6;color:#333;">Hi <b>' . htmlspecialchars($s['fullname']) . '</b>,</p>
          <p style="font-size:14px;line-height:1.6;color:#333;">' . htmlspecialchars($msg) . '</p>
          <table style="font-size:14px;color:#333;border-collapse:collapse;margin:14px 0;width:100%;">
            <tr><td style="padding:6px 10px 6px 0;color:#888;">Classification</td><td style="padding:6px 0;font-weight:bold;color:' . $sevColor . ';">' . strtoupper($sev) . '</td></tr>
            <tr><td style="padding:6px 10px 6px 0;color:#888;">Total on record</td><td style="padding:6px 0;font-weight:bold;">' . $cnt . '</td></tr>
            <tr><td style="padding:6px 10px 6px 0;color:#888;">Student ID</td><td style="padding:6px 0;">' . htmlspecialchars($s['student_id'] ?? '') . '</td></tr>
          </table>
          <p style="font-size:13px;color:#555;line-height:1.6;">Sign in to QR Shield to see the full details of your record. If you believe this is a mistake, please visit the Office of Student Affairs.</p>';

        return vts_send_mail($email, $s['fullname'], 'GWC: ' . $title, vts_mail_shell($title, $inner));
    } catch (Throwable $e) {
        error_log('VTS student violation email failed: ' . $e->getMessage());
        return false;
    }
}

function send_welcome_qr_email($toEmail, $fullName, $studentId, $qrFilePath) {

    // Respect the on/off toggle
    if (!defined('MAIL_ENABLED') || MAIL_ENABLED !== true) {
        return false;
    }

    // PHPMailer: composer (/vendor) OR a manual download in /PHPMailer/src
    $autoload = __DIR__ . '/../vendor/autoload.php';
    $manual   = __DIR__ . '/../PHPMailer/src';
    if (file_exists($autoload)) {
        require_once $autoload;
    } elseif (file_exists($manual . '/PHPMailer.php')) {
        require_once $manual . '/Exception.php';
        require_once $manual . '/PHPMailer.php';
        require_once $manual . '/SMTP.php';
    } else {
        // Library not installed yet - skip silently so registration still works
        error_log('SVTMS mail: PHPMailer not found (no /vendor and no /PHPMailer/src).');
        return false;
    }

        // Online (InfinityFree): SMTP blocked -> Brevo HTTP API with QR attached.
    if (!mail_is_local()) {
        $qrB64 = ($qrFilePath && file_exists($qrFilePath)) ? base64_encode(file_get_contents($qrFilePath)) : null;
        $html = '
        <div style="font-family:Arial,Helvetica,sans-serif;max-width:520px;margin:auto;border:1px solid #e6e6e6;border-radius:10px;overflow:hidden;">
          <div style="background:linear-gradient(90deg,#1a3a6b,#2a5298 55%,#c9a227);padding:18px 22px;color:#fff;">
            <div style="font-size:18px;font-weight:bold;">VIOLATION TRACKING SYSTEM</div>
            <div style="font-size:12px;opacity:.85;">Golden West Colleges, Inc.</div>
          </div>
          <div style="padding:22px;color:#222;">
            <p>Hi <b>' . htmlspecialchars($fullName) . '</b>,</p>
            <p>Your student account has been created. Student ID: <b>' . htmlspecialchars($studentId) . '</b>.</p>
            <p style="color:#555;font-size:13px;">Your personal QR code is attached as <b>my_qr_code.png</b>. Present it to the guard when asked. You can also log in to view it anytime.</p>
          </div>
        </div>';
        $att = $qrB64 ? ['name'=>'my_qr_code.png','content_base64'=>$qrB64] : null;
        return brevo_send($toEmail, $fullName, 'Welcome to GWC QR Shield', $html, $att);
    }

    // Degrade gracefully if the PHPMailer library isn't actually installed
    // (composer autoload may exist without it) — never fatal the request.
    if (!class_exists('PHPMailer\\PHPMailer\\PHPMailer')) {
        error_log('SVTMS mail: PHPMailer class unavailable — skipping SMTP send.');
        return false;
    }
    $mail = new PHPMailer\PHPMailer\PHPMailer(true);

    try {
        $mail->isSMTP();
        $mail->Host       = MAIL_HOST;
        $mail->SMTPAuth   = true;
        $mail->Username   = MAIL_USERNAME;
        $mail->Password   = MAIL_PASSWORD;
        $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = MAIL_PORT;
        /* See the note on the first SMTP block: PHPMailer would otherwise
           wait 300s on an unreachable mail host and hang the request. */
        $mail->Timeout    = 6;
        $mail->SMTPKeepAlive = false;

        $mail->setFrom(MAIL_FROM_EMAIL, MAIL_FROM_NAME);
        $mail->addAddress($toEmail, $fullName);

        // Attach the QR png (so it's downloadable) AND embed it inline
        $hasQr = $qrFilePath && file_exists($qrFilePath);
        if ($hasQr) {
            $mail->addAttachment($qrFilePath, 'my_qr_code.png');
            $mail->addEmbeddedImage($qrFilePath, 'qrcid', 'my_qr_code.png');
        }

        $mail->isHTML(true);
        $mail->Subject = 'Welcome to GWC QR Shield';

        $qrBlock = $hasQr
            ? '<p style="margin:18px 0 6px;font-weight:bold;color:#1a3a6b;">Your personal QR code:</p>
               <img src="cid:qrcid" alt="Your QR Code" style="width:200px;height:200px;border:1px solid #ddd;border-radius:8px;">
               <p style="font-size:13px;color:#555;">The same image is attached to this email as <b>my_qr_code.png</b>. Present it to the guard when asked.</p>'
            : '<p style="font-size:13px;color:#555;">Log in to the system to view and download your QR code.</p>';

        $mail->Body = '
        <div style="font-family:Arial,Helvetica,sans-serif;max-width:520px;margin:auto;border:1px solid #e6e6e6;border-radius:10px;overflow:hidden;">
          <div style="background:linear-gradient(90deg,#1a3a6b,#2a5298 55%,#c9a227);padding:18px 22px;color:#fff;">
            <div style="font-size:18px;font-weight:bold;letter-spacing:.5px;">VIOLATION TRACKING SYSTEM</div>
            <div style="font-size:12px;opacity:.85;">Golden West Colleges, Inc.</div>
          </div>
          <div style="padding:22px;">
            <p style="font-size:15px;color:#222;">Hi <b>' . htmlspecialchars($fullName) . '</b>,</p>
            <p style="font-size:14px;color:#444;line-height:1.5;">
              Your student account has been created successfully.
              Below are your details:
            </p>
            <table style="font-size:14px;color:#333;border-collapse:collapse;margin:10px 0;">
              <tr><td style="padding:4px 10px 4px 0;color:#888;">Student ID</td><td style="padding:4px 0;font-weight:bold;">' . htmlspecialchars($studentId) . '</td></tr>
              <tr><td style="padding:4px 10px 4px 0;color:#888;">Name</td><td style="padding:4px 0;font-weight:bold;">' . htmlspecialchars($fullName) . '</td></tr>
            </table>
            ' . $qrBlock . '
            <p style="font-size:12px;color:#999;margin-top:22px;">
              This is an automated message. Please do not reply.
            </p>
          </div>
        </div>';

        $mail->AltBody = "Hi $fullName,\n\nYour student account has been created.\nStudent ID: $studentId\n\nYour QR code is attached to this email. Present it to the guard when asked.";

        $mail->send();
        return true;

    } catch (Exception $e) {
        error_log('SVTMS mail error: ' . $mail->ErrorInfo);
        return false;
    }
}



/**
 * Send the 6-digit OTP verification email.
 * Returns true on success, false if mail is off/misconfigured (never throws).
 */
function send_verification_email($toEmail, $toName, $code) {
    if (!defined('MAIL_ENABLED') || MAIL_ENABLED !== true) {
        return false;
    }
    $autoload = __DIR__ . '/../vendor/autoload.php';
    $manual   = __DIR__ . '/../PHPMailer/src';
    if (file_exists($autoload)) {
        require_once $autoload;
    } elseif (file_exists($manual . '/PHPMailer.php')) {
        require_once $manual . '/Exception.php';
        require_once $manual . '/PHPMailer.php';
        require_once $manual . '/SMTP.php';
    } else {
        error_log('SVTMS mail: PHPMailer not found for verification email.');
        return false;
    }

    $link = base_url() . "/verify.php?email=" . urlencode($toEmail) . "&code=" . urlencode($code);
    $htmlBody = '
          <div style="font-family:Arial,Helvetica,sans-serif;max-width:520px;margin:auto;border:1px solid #e6e6e6;border-radius:10px;overflow:hidden;">
            <div style="background:linear-gradient(90deg,#1a3a6b,#2a5298 55%,#c9a227);padding:18px 22px;color:#fff;">
              <div style="font-size:18px;font-weight:bold;letter-spacing:.5px;">VIOLATION TRACKING SYSTEM</div>
              <div style="font-size:12px;opacity:.85;">Golden West Colleges, Inc.</div>
            </div>
            <div style="padding:22px;color:#222;">
              <p>Hi <b>' . htmlspecialchars($toName) . '</b>,</p>
              <p>Your email verification code is:</p>
              <p style="font-size:30px;letter-spacing:.4em;font-weight:800;color:#1a3a6b;text-align:center;margin:18px 0;">' . $code . '</p>
              <p>Enter this code on the verification page, or click the button below:</p>
              <p style="text-align:center;margin:22px 0;">
                <a href="' . $link . '" style="background:#1a3a6b;color:#fff;padding:12px 26px;border-radius:8px;text-decoration:none;font-weight:bold;">Verify My Email</a>
              </p>
              <p style="color:#777;font-size:13px;">This code expires in 30 minutes. If you did not register, ignore this email.</p>
            </div>
          </div>';

    // Online (InfinityFree): SMTP is blocked -> use Brevo HTTP API.
    if (!mail_is_local()) {
        return brevo_send($toEmail, $toName, "Your VTS verification code: {$code}", $htmlBody);
    }

    // Localhost: Gmail SMTP via PHPMailer.
    // Degrade gracefully if the PHPMailer library isn't actually installed
    // (composer autoload may exist without it) — never fatal the request.
    if (!class_exists('PHPMailer\\PHPMailer\\PHPMailer')) {
        error_log('SVTMS mail: PHPMailer class unavailable — skipping SMTP send.');
        return false;
    }
    $mail = new PHPMailer\PHPMailer\PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host       = MAIL_HOST;
        $mail->SMTPAuth   = true;
        $mail->Username   = MAIL_USERNAME;
        $mail->Password   = MAIL_PASSWORD;
        $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = MAIL_PORT;
        /* See the note on the first SMTP block: PHPMailer would otherwise
           wait 300s on an unreachable mail host and hang the request. */
        $mail->Timeout    = 6;
        $mail->SMTPKeepAlive = false;

        $mail->setFrom(MAIL_FROM_EMAIL, MAIL_FROM_NAME);
        $mail->addAddress($toEmail, $toName);

        $mail->isHTML(true);
        $mail->Subject = "Your VTS verification code: {$code}";
        $mail->Body = $htmlBody;
        $mail->AltBody = "Your VTS verification code is {$code}. It expires in 30 minutes.";
        $mail->send();
        return true;
    } catch (Throwable $e) {
        error_log('SVTMS verification mail failed: ' . $e->getMessage());
        return false;
    }
}


/**
 * Marshal → Admin report email. Sends a scanned-violations summary to the
 * admin with a real CSV attached — the "pag nag-release, nandon yung
 * attachment" flow from the CITE meeting, so a student can't deny being caught.
 *
 *   $rows: array of assoc rows with keys
 *          sid, name, course, year, violation, severity, offense, date
 *   Returns true on success, false if mail is off/misconfigured (never throws).
 */
function send_violation_report_email($toEmail, $toName, $guardName, array $rows, $rangeLabel = '') {
    if (!defined('MAIL_ENABLED') || MAIL_ENABLED !== true) return false;

    // Build the CSV attachment in memory.
    $headers = ['#','School ID','Student Name','Course','Year','Violation','Severity','Offense','Date & Time'];
    $fh = fopen('php://temp', 'r+');
    fputcsv($fh, $headers);
    $n = 0;
    foreach ($rows as $r) {
        $n++;
        fputcsv($fh, [
            $n, $r['sid'] ?? '', $r['name'] ?? '', $r['course'] ?? '', $r['year'] ?? '',
            $r['violation'] ?? '', $r['severity'] ?? '', $r['offense'] ?? '', $r['date'] ?? '',
        ]);
    }
    rewind($fh);
    $csv = "\xEF\xBB\xBF" . stream_get_contents($fh);   // UTF-8 BOM so Excel opens it cleanly
    fclose($fh);
    $csvName = 'violation_report_' . date('Y-m-d_His') . '.csv';

    // HTML body: a compact table preview (first 40 rows) + totals.
    $rowsHtml = '';
    foreach (array_slice($rows, 0, 40) as $i => $r) {
        $sc = ($r['severity'] ?? '') === 'Grave' ? '#b0122b' : (($r['severity'] ?? '') === 'Major' ? '#c47f00' : '#2a7a4b');
        $rowsHtml .= '<tr>'
            . '<td style="padding:5px 8px;border-bottom:1px solid #eee;">' . ($i + 1) . '</td>'
            . '<td style="padding:5px 8px;border-bottom:1px solid #eee;">' . htmlspecialchars($r['sid'] ?? '') . '</td>'
            . '<td style="padding:5px 8px;border-bottom:1px solid #eee;">' . htmlspecialchars($r['name'] ?? '') . '</td>'
            . '<td style="padding:5px 8px;border-bottom:1px solid #eee;">' . htmlspecialchars($r['violation'] ?? '') . '</td>'
            . '<td style="padding:5px 8px;border-bottom:1px solid #eee;color:' . $sc . ';font-weight:700;">' . htmlspecialchars($r['severity'] ?? '') . '</td>'
            . '<td style="padding:5px 8px;border-bottom:1px solid #eee;">' . htmlspecialchars($r['offense'] ?? '') . '</td>'
            . '<td style="padding:5px 8px;border-bottom:1px solid #eee;white-space:nowrap;">' . htmlspecialchars($r['date'] ?? '') . '</td>'
            . '</tr>';
    }
    $more = count($rows) > 40 ? '<p style="color:#888;font-size:12px;">…and ' . (count($rows) - 40) . ' more in the attached CSV.</p>' : '';

    $htmlBody = '
    <div style="font-family:Arial,Helvetica,sans-serif;max-width:720px;margin:auto;border:1px solid #e6e6e6;border-radius:10px;overflow:hidden;">
      <div style="background:linear-gradient(90deg,#1a3a6b,#2a5298 55%,#c9a227);padding:18px 22px;color:#fff;">
        <div style="font-size:18px;font-weight:bold;">VIOLATION SCAN REPORT</div>
        <div style="font-size:12px;opacity:.85;">Golden West Colleges, Inc. · QR Shield</div>
      </div>
      <div style="padding:22px;color:#222;">
        <p>Submitted by <b>' . htmlspecialchars($guardName) . '</b>' . ($rangeLabel ? ' — <b>' . htmlspecialchars($rangeLabel) . '</b>' : '') . '.</p>
        <p><b>' . count($rows) . '</b> violation' . (count($rows) === 1 ? '' : 's') . ' recorded. Full list attached as <b>' . htmlspecialchars($csvName) . '</b>.</p>
        <table style="border-collapse:collapse;width:100%;font-size:13px;color:#333;margin-top:10px;">
          <thead><tr style="background:#f3f5fa;text-align:left;">
            <th style="padding:6px 8px;">#</th><th style="padding:6px 8px;">School ID</th><th style="padding:6px 8px;">Name</th>
            <th style="padding:6px 8px;">Violation</th><th style="padding:6px 8px;">Severity</th><th style="padding:6px 8px;">Offense</th><th style="padding:6px 8px;">Date</th>
          </tr></thead>
          <tbody>' . ($rowsHtml ?: '<tr><td colspan="7" style="padding:10px;color:#888;">No violations in this range.</td></tr>') . '</tbody>
        </table>
        ' . $more . '
        <p style="font-size:12px;color:#999;margin-top:22px;">Automated report. Please do not reply.</p>
      </div>
    </div>';

    $subject = 'Violation Scan Report' . ($rangeLabel ? ' — ' . $rangeLabel : '') . ' (' . count($rows) . ')';

    // Online (InfinityFree): SMTP blocked -> Brevo HTTP API with the CSV attached.
    if (!mail_is_local()) {
        $att = ['name' => $csvName, 'content_base64' => base64_encode($csv)];
        return brevo_send($toEmail, $toName, $subject, $htmlBody, $att);
    }

    // Localhost: Gmail SMTP via PHPMailer.
    $autoload = __DIR__ . '/../vendor/autoload.php';
    $manual   = __DIR__ . '/../PHPMailer/src';
    if (file_exists($autoload)) {
        require_once $autoload;
    } elseif (file_exists($manual . '/PHPMailer.php')) {
        require_once $manual . '/Exception.php';
        require_once $manual . '/PHPMailer.php';
        require_once $manual . '/SMTP.php';
    } else {
        error_log('SVTMS mail: PHPMailer not found for report email.');
        return false;
    }

    // Degrade gracefully if the PHPMailer library isn't actually installed
    // (composer autoload may exist without it) — never fatal the request.
    if (!class_exists('PHPMailer\\PHPMailer\\PHPMailer')) {
        error_log('SVTMS mail: PHPMailer class unavailable — skipping SMTP send.');
        return false;
    }
    $mail = new PHPMailer\PHPMailer\PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host       = MAIL_HOST;
        $mail->SMTPAuth   = true;
        $mail->Username   = MAIL_USERNAME;
        $mail->Password   = MAIL_PASSWORD;
        $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = MAIL_PORT;
        /* See the note on the first SMTP block: PHPMailer would otherwise
           wait 300s on an unreachable mail host and hang the request. */
        $mail->Timeout    = 6;
        $mail->SMTPKeepAlive = false;

        $mail->setFrom(MAIL_FROM_EMAIL, MAIL_FROM_NAME);
        $mail->addAddress($toEmail, $toName);
        $mail->addStringAttachment($csv, $csvName, 'base64', 'text/csv');

        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $htmlBody;
        $mail->AltBody = count($rows) . ' violations recorded by ' . $guardName . '. See attached CSV.';
        $mail->send();
        return true;
    } catch (Throwable $e) {
        error_log('SVTMS report mail failed: ' . $e->getMessage());
        return false;
    }
}
