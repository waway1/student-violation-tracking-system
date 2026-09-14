<?php
/* DB connection (PDO). Auto-detects a local dev machine (XAMPP, Laragon,
   WAMP, ...) vs InfinityFree (live); for a live deploy put the credentials in .env
   (see the block below) and import database/infinityfree_import.sql.

   The "am I local?" decision used to be written out here AND again in
   includes/mailer.php, with a note on each asking the next person to keep
   them in sync. They are one function in config/env.php now — this file
   loads first everywhere, so it is the natural place for the pair of them
   to share. */
require_once __DIR__ . '/env.php';   // .env parsing + the one "am I local?" answer
vts_load_env();

/* ---------------------------------------------------------------------
   WHERE THE CREDENTIALS COME FROM.

   Two sources, in this order: .env first, then config/db_credentials.php.

   Both of those files are in .gitignore — on purpose, so a password is
   never committed — and that is exactly what made the live site fail
   before. The deploy pulls from GitHub, so it arrives with NEITHER file,
   and the old `require_once "db_credentials.php"` was a hard fatal on a
   file that could not possibly be there. The page died before it could
   say why.

   So: the credentials file is now OPTIONAL (loaded if present, skipped if
   not), .env can supply the same four values on its own, and a genuinely
   missing setting reports itself instead of crashing.

   On InfinityFree, create ONE file in htdocs — .env — containing:

       APP_ENV=live
       DB_HOST=sql206.infinityfree.com
       DB_PORT=3306
       DB_NAME=if0_42480644_svts
       DB_USER=if0_42480644
       DB_PASS=your-infinityfree-mysql-password

   Nothing else needs editing, and nothing secret ever reaches GitHub.
   --------------------------------------------------------------------- */

/* A setting from .env or the real environment, else $default.
   An empty string counts as "not set": every value here is a hostname, a
   port, a user or a database name, and none of those is legitimately
   blank. The one value that CAN be legitimately blank — the local MySQL
   root password — is handled by passing '' as the default below. */
function vts_db_setting($name, $default = null) {
    if (isset($_ENV[$name]) && trim((string)$_ENV[$name]) !== '') return trim((string)$_ENV[$name]);
    $v = getenv($name);
    if ($v !== false && trim((string)$v) !== '') return trim((string)$v);
    return $default;
}

$db_port = vts_db_setting('DB_PORT', '3306');

/* This used to be decided here from HTTP_HOST alone, which is why the site
   broke the moment it was reached through an ngrok tunnel: the public
   hostname matched none of the local patterns, so a laptop running XAMPP
   concluded it was the live InfinityFree host and tried to connect there.
   See config/env.php for the whole story. */
$IS_LOCAL = vts_is_local_env();

if ($IS_LOCAL) {
    // ---- Local dev machine (XAMPP, Laragon, WAMP, ...) ----
    $host     = vts_db_setting('DB_HOST', '127.0.0.1');
    $username = vts_db_setting('DB_USER', 'root');
    $password = vts_db_setting('DB_PASS', '');          // XAMPP's root really is blank
    $preferredDb = vts_db_setting('DB_NAME', null) ?: (getenv('VTS_DB_NAME') ?: 'svts');
    $fallbackDb  = $preferredDb === 'svts' ? 'student_violation_system' : 'svts';
} else {
    // ---- INFINITYFREE (live site) ----
    // Optional, and only for values .env did not already give.
    $credFile = __DIR__ . '/db_credentials.php';
    if (is_file($credFile)) require_once $credFile;

    /* LIVE_DB_* FIRST, so ONE .env file can serve both machines.
       A single DB_HOST cannot be 127.0.0.1 and sql206.infinityfree.com at
       the same time, so the two sets are kept apart: DB_* holds the XAMPP
       values, LIVE_DB_* the InfinityFree ones, and each side reads only
       its own. The SAME .env can then sit on the laptop and on the server
       without editing, which is what makes uploading the whole folder safe.

       Order per line: LIVE_DB_* (explicit), then DB_* (so a server-only
       .env naming no LIVE_ keys still works), then db_credentials.php. */
    $host     = vts_db_setting('LIVE_DB_HOST', vts_db_setting('DB_HOST', $LIVE_HOST     ?? null));
    $username = vts_db_setting('LIVE_DB_USER', vts_db_setting('DB_USER', $LIVE_USERNAME ?? null));
    $password = vts_db_setting('LIVE_DB_PASS', vts_db_setting('DB_PASS', $LIVE_PASSWORD ?? null));
    $dbname   = vts_db_setting('LIVE_DB_NAME', vts_db_setting('DB_NAME', $LIVE_DBNAME   ?? null));
    $db_port  = vts_db_setting('LIVE_DB_PORT', $db_port);

    /* Say which setting is missing — in the log, not to the visitor. A
       blank page reading "temporarily unavailable" cost hours last time;
       the log line names the four settings and which one is absent. */
    $missing = [];
    foreach (['DB_HOST' => $host, 'DB_USER' => $username, 'DB_NAME' => $dbname] as $k => $v) {
        if ($v === null || $v === '') $missing[] = $k;
    }
    if ($password === null || $password === '' || $password === 'PUT-YOUR-INFINITYFREE-PASSWORD-HERE') {
        $missing[] = 'DB_PASS';
    }
    if ($missing) {
        error_log('VTS: live DB credentials missing/unset: ' . implode(', ', $missing)
                . ' — set them in .env (or config/db_credentials.php) in the web root.');
        die('The service is not configured yet. Please try again later.');
    }
}

try {
    if ($IS_LOCAL) {
        $server = new PDO("mysql:host=$host;port=$db_port;charset=utf8mb4", $username, $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $available = $server->prepare("SELECT s.SCHEMA_NAME,
                EXISTS (SELECT 1 FROM information_schema.TABLES t
                        WHERE t.TABLE_SCHEMA = s.SCHEMA_NAME AND t.TABLE_NAME = 'users') AS has_users
            FROM information_schema.SCHEMATA s
            WHERE s.SCHEMA_NAME IN (:preferred, :fallback)");
        $available->execute([':preferred' => $preferredDb, ':fallback' => $fallbackDb]);
        $ready = [];
        foreach ($available->fetchAll() as $database) {
            if ((int)$database['has_users'] === 1) $ready[] = $database['SCHEMA_NAME'];
        }
        $dbname = in_array($preferredDb, $ready, true) ? $preferredDb : ($ready[0] ?? $fallbackDb);
        unset($server);
    }

    $conn = new PDO(
    "mysql:host=$host;port=$db_port;dbname=$dbname;charset=utf8mb4",
        $username,
        $password
    );
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $conn->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    // Philippine time for NOW()/CURDATE() etc., so stored + displayed
    // timestamps match local wall-clock time no matter where it's hosted.
    try { $conn->exec("SET time_zone = '+08:00'"); } catch (Throwable $tz) {}

    // Older databases may not contain the later user/roster columns. Add them
    // before any CRUD operation so update/insert flows keep working.
    if (!function_exists('vts_ensure_missing_columns')) {
        function vts_ensure_missing_columns($conn, $table, array $columns) {
            foreach ($columns as $col => $ddl) {
                try {
                    $chk = $conn->prepare("SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = :c");
                    $chk->execute([':t' => $table, ':c' => $col]);
                    if (!$chk->fetchColumn()) {
                        $conn->exec("ALTER TABLE `{$table}` ADD COLUMN `{$col}` {$ddl}");
                    }
                } catch (Throwable $e) {
                    // Best effort only; if the DB prevents the change, the app keeps running.
                }
            }
        }
    }

    function vts_ensure_user_columns($conn) {
        static $done = false;
        if ($done) return;
        $done = true;

        vts_ensure_missing_columns($conn, 'users', [
            'firstname' => 'VARCHAR(60) NULL',
            'middlename' => 'VARCHAR(60) NULL',
            'lastname' => 'VARCHAR(60) NULL',
            'suffix' => 'VARCHAR(15) NULL',
            'fullname' => 'VARCHAR(180) NOT NULL DEFAULT ""',
            'contact_number' => 'VARCHAR(20) NULL',
            'course' => 'VARCHAR(150) NULL',
            'year_level' => 'VARCHAR(20) NULL',
            'section' => 'VARCHAR(20) NULL',
            'profile_picture' => 'VARCHAR(255) NULL',
            'qr_code' => 'VARCHAR(255) NULL',
            'status' => "ENUM('Active','Inactive') NOT NULL DEFAULT 'Active'",
            'email_verified' => 'TINYINT(1) NOT NULL DEFAULT 1',
            // Exactly two students may be assigned to the scanner at once.
            // The limit itself is enforced transactionally by the helper below.
            'scanner_access' => 'TINYINT(1) NOT NULL DEFAULT 0',
            'scanner_session_token' => 'CHAR(64) NULL',
            'scanner_session_expires' => 'DATETIME NULL',
            'verify_code' => 'VARCHAR(6) NULL',
            'verify_expires' => 'DATETIME NULL',
        ]);

        vts_ensure_missing_columns($conn, 'student_roster', [
            'middlename' => 'VARCHAR(60) NULL',
            'course' => 'VARCHAR(150) NULL',
            'year_level' => 'VARCHAR(20) NULL',
            'is_used' => 'TINYINT(1) NOT NULL DEFAULT 0',
        ]);
    }

    vts_ensure_user_columns($conn);
} catch (PDOException $e) {
    error_log('VTS database connection failed: ' . $e->getMessage());
    die("The service is temporarily unavailable. Please try again later.");
}
