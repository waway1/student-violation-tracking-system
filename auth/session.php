<?php
/* Hardened session bootstrap + security headers (idle/absolute timeouts set below). */

// How long before an idle user is logged out (seconds).
// 30 min was too aggressive during testing -> 2 hours. Change freely.
if (!defined('VTS_IDLE_TIMEOUT'))     define('VTS_IDLE_TIMEOUT', 7200);    // 2 hours idle
if (!defined('VTS_ABSOLUTE_TIMEOUT')) define('VTS_ABSOLUTE_TIMEOUT', 43200); // 12 hours max

if (session_status() == PHP_SESSION_NONE) {
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
          || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_samesite', 'Lax');
    if ($https) ini_set('session.cookie_secure', '1');

    /* -----------------------------------------------------------------
       A PRIVATE SESSION DIRECTORY, AND A GC CLOCK THAT MATCHES OUR OWN.

       THE BUG THIS FIXES. On shared hosting (InfinityFree) every account
       can share ONE session directory, and PHP's garbage collector is
       fired by whichever site happens to get a request. Two things follow,
       and both were happening live while localhost stayed perfect:

         1. Another site's GC sweep deletes OUR session files. Nothing in
            this app runs, so nothing logs it - a student is simply not
            logged in any more on the next click.
         2. gc_maxlifetime defaulted to 1440s (24 min) while this app
            considers a session good for VTS_IDLE_TIMEOUT (2 hours). So
            even our OWN cleanup was throwing away sessions the app still
            regarded as live, an hour and a half early.

       The visible symptom is what gets reported as "two students signed in
       at the same time and landed on each other's dashboard": one of them
       loses their session mid-flow, the next request arrives with no
       user_id (or a recycled one), and the role-based redirect sends them
       somewhere they should not be.

       THE FIX. Keep sessions in a directory belonging to this app only, so
       no other account's sweep can reach them, and tell the collector to
       use the same lifetime the app enforces. Both are done BEFORE
       session_start(), which is the only point either can be set.

       Deliberately defensive: if the directory cannot be made or written
       to, we leave PHP's default alone. A wrong save_path breaks every
       session on the site, and that is far worse than the bug above. */
    $sessDir = __DIR__ . '/../sessions';
    if (!is_dir($sessDir)) @mkdir($sessDir, 0700, true);
    /* The guard is written HERE, not just shipped in the repo, because the
       directory is created on demand. A session file is named sess_<id>,
       with no extension and no leading dot, so none of the root .htaccess
       rules cover it: if this folder ever existed without its own deny
       rule, GET /sessions/sess_<id> would hand a visitor a live session -
       user_id, role and all. Creating the directory and leaving it open is
       therefore worse than not moving sessions at all, so the two steps
       are never allowed to come apart. */
    if (is_dir($sessDir) && !is_file($sessDir . '/.htaccess')) {
        @file_put_contents($sessDir . '/.htaccess',
            "<IfModule mod_authz_core.c>
    Require all denied
</IfModule>
"
          . "<IfModule !mod_authz_core.c>
    Order allow,deny
    Deny from all
</IfModule>
");
    }
    if (is_dir($sessDir) && is_writable($sessDir)) {
        session_save_path($sessDir);
        // Ours to collect now, so the clock must match what we enforce.
        ini_set('session.gc_maxlifetime', (string)VTS_ABSOLUTE_TIMEOUT);
        ini_set('session.gc_probability', '1');
        ini_set('session.gc_divisor', '100');
    }

    // NOTE: we deliberately keep PHP's DEFAULT session name.
    // Renaming it broke every file that calls a plain session_start()
    // (register.php, verify.php, index.php, forgot_password.php,
    //  header.php, csrf_token()...) -> two different sessions -> the
    //  CSRF token never matched -> "Session expired" on a correct login.
    session_start();
}

/* --- Security headers --- */
if (!headers_sent()) {
    header("X-Frame-Options: SAMEORIGIN");
    header("X-Content-Type-Options: nosniff");
    header("Referrer-Policy: strict-origin-when-cross-origin");
    header("Permissions-Policy: geolocation=(), microphone=(), payment=()");

    /* Content-Security-Policy — the directives that cannot break this app.

       These four shut down whole classes of injected-markup attack without
       constraining scripts, styles, images or fonts, so nothing on the site
       renders differently:

         base-uri 'self'     an injected <base href="//evil/"> would otherwise
                             silently repoint every relative script and
                             stylesheet URL on the page at someone else's
                             server — the page keeps working and loads their
                             code instead of ours.
         form-action 'self'  a rewritten <form action> cannot post a password,
                             a reset code or a sign-in OTP anywhere but here.
                             Editing a form's action in the inspector is the
                             single easiest way to steal a credential, and
                             this is what stops it being worth trying.
         object-src 'none'   no <object>/<embed> plugin content, ever.
         frame-ancestors     nobody may frame the site: the version of the
                             X-Frame-Options line above that browsers still
                             actually enforce.

       DELIBERATELY ABSENT: script-src and style-src. Those are the directives
       that stop an injected <script> from RUNNING, and they are the ones this
       app cannot take yet — it has ~400 inline style="" attributes and inline
       onclick= handlers, and a script-src without 'unsafe-inline' would switch
       every one of them off. Adding them is a refactor of the pages, not a
       change to this line. */
    header("Content-Security-Policy: base-uri 'self'; form-action 'self'; object-src 'none'; frame-ancestors 'self'");

    header_remove("X-Powered-By");
    if (!empty($_SERVER['HTTPS']) || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')) {
        header("Strict-Transport-Security: max-age=31536000; includeSubDomains");
    }
    header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
    header("Pragma: no-cache");
    header("Expires: 0");
}

/* ---------------------------------------------------------------------
   Work out the correct URL of the entry page (student_search.php) no matter
   which folder the current page lives in (root pages like account.php, or
   admin/, osa/...). The old hard-coded "../login.php" broke on root pages,
   and login.php itself has since been folded into student_search.php.
   --------------------------------------------------------------------- */
function vts_login_url($msg = '') {
    /* ---- WHY THIS IS NOT A LIST OF FOLDER NAMES ANY MORE ----

       It used to strip a known subfolder off SCRIPT_NAME with
       `(admin|osa|dean|student|guard|headmarshal|auth|api)`. "osa_staff"
       was never in that list, and neither was "reports" -- so signing out
       of an OSA Staff page sent the browser to

           /SAD/osa_staff/student_search.php

       which does not exist. That did not even produce an honest 404: the
       catch-all at the bottom of .htaccess ("not a file, not a directory
       -> index.php") answered it with the LANDING PAGE, at status 200,
       with the address bar still reading /SAD/osa_staff/. Every relative
       URL on that page then resolved against that folder, so the
       stylesheets and the seal came back as more copies of index.php,
       and its own "Look Yourself Up" link pointed at the same dead path.
       Signing out of OSA Staff put you in a loop on an unstyled page.

       A whitelist cannot stay right for long -- it breaks again, silently,
       the next time anyone adds a folder. This file is always at
       <app>/auth/session.php, so the app root is one level up, and that is
       a fact about the tree rather than a guess about names. The old
       stripping stays on as a fallback for the case where the document
       root is not something we can compare against (an Alias, an odd CGI
       setup), with the two missing folders added to it. */
    $appDir  = str_replace('\\', '/', (string)realpath(__DIR__ . '/..'));
    $docRoot = str_replace('\\', '/', rtrim((string)realpath($_SERVER['DOCUMENT_ROOT'] ?? ''), '/'));

    $base = null;
    if ($appDir !== '' && $docRoot !== '') {
        /* realpath() and the request can disagree on case on Windows, so an
           exact match is tried first and a case-blind one only after. */
        if (strpos($appDir, $docRoot) === 0) {
            $base = substr($appDir, strlen($docRoot));
        } elseif (strncasecmp($appDir, $docRoot, strlen($docRoot)) === 0) {
            $base = substr($appDir, strlen($docRoot));
        }
    }

    if ($base === null) {
        $dir  = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/'));
        $base = preg_replace('#/(admin|osa_staff|osa|dean|student|guard|headmarshal|auth|api|reports)$#i', '', $dir);
    }

    if ($base === '' || $base === '.' || $base === false) $base = '/';
    if (substr($base, -1) !== '/') $base .= '/';
    return $base . 'student_search.php' . ($msg !== '' ? '?error=' . urlencode($msg) : '');
}

function vts_kill_session($msg) {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
    header("Location: " . vts_login_url($msg));
    exit();
}

/* --- Session fixation: fresh ID on first use --- */
if (!isset($_SESSION['initiated'])) {
    session_regenerate_id(true);
    $_SESSION['initiated'] = true;
}

/* ---------------------------------------------------------------------
   IMPORTANT: the timeout / fingerprint checks below only apply to a
   LOGGED-IN session. A visitor sitting on the login page must never be
   bounced with "Session expired" before they've even signed in.
   --------------------------------------------------------------------- */
if (isset($_SESSION['user_id'])) {

    // Stolen-cookie check: pin the session to the browser fingerprint.
    $fp = hash('sha256', ($_SERVER['HTTP_USER_AGENT'] ?? '') . '|VTS');
    if (!isset($_SESSION['fp'])) {
        $_SESSION['fp'] = $fp;
    } elseif (!hash_equals($_SESSION['fp'], $fp)) {
        vts_kill_session("Session ended for your security. Please log in again.");
    }

    // Idle timeout
    if (isset($_SESSION['LAST_ACTIVITY']) && (time() - $_SESSION['LAST_ACTIVITY']) > VTS_IDLE_TIMEOUT) {
        vts_kill_session("You were idle too long. Please login again.");
    }

    // Absolute cap
    if (isset($_SESSION['LOGIN_TIME']) && (time() - $_SESSION['LOGIN_TIME']) > VTS_ABSOLUTE_TIMEOUT) {
        vts_kill_session("Session expired. Please login again.");
    }

    $_SESSION['LAST_ACTIVITY'] = time();
}
