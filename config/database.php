<?php
/* DB connection (PDO). Auto-detects a local dev machine (XAMPP, Laragon,
   WAMP, ...) vs InfinityFree (live); for a live deploy fill the
   INFINITYFREE block below and import the .sql.

   The "am I local?" decision used to be written out here AND again in
   includes/mailer.php, with a note on each asking the next person to keep
   them in sync. They are one function in config/env.php now — this file
   loads first everywhere, so it is the natural place for the pair of them
   to share. */
require_once __DIR__ . '/env.php';   // .env parsing + the one "am I local?" answer
vts_load_env();

// DB_HOST / DB_PORT come from .env, with the XAMPP defaults behind them.
$db_host = $_ENV['DB_HOST'] ?? '127.0.0.1';
$db_port = $_ENV['DB_PORT'] ?? '3306';

/* This used to be decided here from HTTP_HOST alone, which is why the site
   broke the moment it was reached through an ngrok tunnel: the public
   hostname matched none of the local patterns, so a laptop running XAMPP
   concluded it was the live InfinityFree host and tried to connect there.
   See config/env.php for the whole story. */
$IS_LOCAL = vts_is_local_env();

if ($IS_LOCAL) {
    // ---- Local dev machine (XAMPP, Laragon, WAMP, ...) ----
    $host     = "$db_host";
    $username = "root";
    $password = "";
    $preferredDb = getenv('VTS_DB_NAME') ?: 'svts';
    $fallbackDb  = $preferredDb === 'svts' ? 'student_violation_system' : 'svts';
} else {
    // ---- INFINITYFREE (live site) ----
    require_once __DIR__ . "/db_credentials.php";
    $host     = $LIVE_HOST;
    $username = $LIVE_USERNAME;
    $password = $LIVE_PASSWORD;
    $dbname   = $LIVE_DBNAME;
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
