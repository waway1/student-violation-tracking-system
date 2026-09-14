<?php
/* =====================================================================
   WHERE IS THIS RUNNING — one answer, in one place
   =====================================================================

   THE BUG THIS EXISTS TO FIX
   "Am I on a dev machine or the live host?" was decided from HTTP_HOST —
   the name the BROWSER asked for. That works for localhost and
   192.168.x.x, and it breaks the moment the same XAMPP is reached by any
   other name. Through an ngrok tunnel the host is

       a1b2c3d4.ngrok-free.app

   which matches none of the local patterns, so the app decided it was the
   LIVE site, loaded config/db_credentials.php, and tried to reach the
   InfinityFree MySQL server from a laptop that has no business talking to
   it. The connection failed and every page answered

       "The service is temporarily unavailable. Please try again later."

   Nothing was wrong with .env, with DB_HOST, with DB_PORT, or with MySQL.
   The app simply never looked at them, because one line had already
   concluded it was somewhere else.

   THE SIGNAL THAT ACTUALLY ANSWERS THE QUESTION
   A client can ask for any hostname it likes; it cannot change which
   machine Apache is running on. SERVER_ADDR is that machine's own
   address, and a tunnel does not alter it — ngrok forwards to
   127.0.0.1:80, so SERVER_ADDR stays loopback however exotic the public
   URL is. A real InfinityFree deploy has a public SERVER_ADDR and is
   still, correctly, treated as live.

   ORDER: an explicit setting wins over everything, then the old
   host-name test (kept, so nothing that worked before changes), then the
   server's own address. Only if all three say otherwise is this the live
   host.

   TO FORCE IT either way, put this in .env:
       APP_ENV=local     # or: live
   ===================================================================== */

/* Read .env once into $_ENV. Kept here rather than in database.php so the
   mailer and anything else can rely on it too. */
function vts_load_env() {
    static $done = false;
    if ($done) return;
    $done = true;

    $file = __DIR__ . '/../.env';
    if (!is_file($file)) return;

    $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) return;

    foreach ($lines as $line) {
        $line = trim($line);                       // also strips a CRLF \r
        if ($line === '' || $line[0] === '#') continue;
        if (strpos($line, '=') === false) continue;
        list($name, $value) = explode('=', $line, 2);
        $name  = trim($name);
        $value = trim($value);
        // A quoted value keeps its spaces; an unquoted one is already trimmed.
        if (strlen($value) > 1 && (
                ($value[0] === '"' && substr($value, -1) === '"') ||
                ($value[0] === "'" && substr($value, -1) === "'"))) {
            $value = substr($value, 1, -1);
        }
        if ($name !== '') $_ENV[$name] = $value;
    }
}

/* An address belonging to this machine or the private network it is on. */
function vts_is_private_addr($ip) {
    $ip = trim((string)$ip);
    if ($ip === '') return false;
    if ($ip === '::1' || $ip === '127.0.0.1' || strpos($ip, '127.') === 0) return true;
    // Anything the IP stack itself calls private or reserved. filter_var does
    // the whole job for both IPv4 and IPv6, so there is no range table here
    // to drift out of date.
    return filter_var(
        $ip,
        FILTER_VALIDATE_IP,
        FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
    ) === false;
}

function vts_is_local_env() {
    static $cached = null;
    if ($cached !== null) return $cached;

    vts_load_env();

    // 1. An explicit decision always wins.
    $declared = strtolower(trim((string)($_ENV['APP_ENV'] ?? getenv('APP_ENV') ?: '')));
    if ($declared === 'local' || $declared === 'development' || $declared === 'dev') return $cached = true;
    if ($declared === 'live'  || $declared === 'production'  || $declared === 'prod') return $cached = false;

    // 2. The hostname test this app has always used. Unchanged on purpose:
    //    everything that worked before keeps working exactly as it did.
    $h = $_SERVER['HTTP_HOST'] ?? '';
    if (in_array($h, ['localhost', '127.0.0.1'], true)
        || str_starts_with($h, 'localhost:')
        || str_starts_with($h, '127.0.0.1:')
        || str_starts_with($h, '192.168.')
        || preg_match('~\.(test|localhost|local)(:\d+)?$~i', $h) === 1) {
        return $cached = true;
    }

    /* 3. KNOWN SHARED HOSTS, checked BEFORE the address test below.

          InfinityFree serves PHP behind a proxy, so SERVER_ADDR there is
          127.0.0.7 - loopback. The test below reads any 127.x as "this is
          a dev machine", so the live site concluded it was LOCAL, used the
          XAMPP half of .env, and tried to reach 127.0.0.1 as root. That
          fails on a server with no local MySQL, and every page answered
          "The service is temporarily unavailable" - the exact symptom this
          deploy got stuck on, with correct credentials sitting unread in
          .env the whole time.

          The filesystem path is the signal that cannot be proxied: an
          InfinityFree account always lives under
          /home/volNN_N/infinityfree.com/<account>/<domain>/htdocs. A XAMPP
          install is C:/xampp/htdocs, so there is no overlap and no false
          positive on a dev machine. */
    $selfPath = __DIR__ . '|' . ($_SERVER['DOCUMENT_ROOT'] ?? '');
    if (stripos($selfPath, '/infinityfree.com/') !== false) return $cached = false;

    // 4. Which machine is actually answering. This is the part a tunnel
    //    cannot fake, and it is what makes ngrok work.
    if (vts_is_private_addr($_SERVER['SERVER_ADDR'] ?? '')) return $cached = true;

    // 5. CLI (a maintenance script) is never the live web host.
    if (PHP_SAPI === 'cli') return $cached = true;

    return $cached = false;
}
