<?php
/* Database backup (Admin): one-click full .sql dump of every table, pure PHP (no mysqldump). */
require_once "../auth/auth.php";
require_once "../config/database.php";
require_once "../includes/functions.php";
require_once "../includes/restore.php";   // putting a backup BACK into the system
$HAS_MAIL = @include_once "../includes/mailer.php";

if (!in_array($_SESSION['role'] ?? '', ['Admin', 'OSA'], true)) {
    vts_deny_access();
}

// ---- Full dump (shared helper in functions.php — no duplicate code) ----
function build_dump($conn) {
    return vts_build_sql_dump($conn);
}

/* The Drive folder backups get filed in. Kept separate from the Reports
   folder: a report can be re-exported any time, a backup is the only copy
   of that moment, so it does not belong in with routine paperwork. */
require_once __DIR__ . "/../config/drive.php";
require_once __DIR__ . "/../includes/drive.php";
$backupDriveUrl = vts_drive_folder_url('backups');

$backupDir = __DIR__ . "/../backups";
if (!is_dir($backupDir)) @mkdir($backupDir, 0755, true);

/* ---- Date range (calendar pickers, same as the Reports page) ---- */
$isDate = fn($d) => (bool)preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$d);
$from = $isDate($_GET['from'] ?? '') ? $_GET['from'] : '';
$to   = $isDate($_GET['to']   ?? '') ? $_GET['to']   : '';
if ($from !== '' && $to !== '' && $from > $to) { [$from, $to] = [$to, $from]; }

// What to back up: all | students | violations | accounts
$scopeOptions = ['all' => 'Everything', 'students' => 'Students only',
                 'violations' => 'Violations only', 'accounts' => 'User accounts only'];
$scope = $_GET['scope'] ?? 'all';
if (!isset($scopeOptions[$scope])) $scope = 'all';

// Which backup sheets each scope keeps (by sheet name).
$scopeSheets = [
    'all'        => null,   // keep everything
    'students'   => ['Students', 'Enrolled List'],
    'violations' => ['Violations'],
    'accounts'   => ['User Accounts'],
];

// ---- Excel backup: one sheet each for students / violations / enrolled list / users ----
if (isset($_GET['excel'])) {
    $rangeLabel = ($from !== '' || $to !== '') ? ($from ?: 'Start') . '_to_' . ($to ?: 'Now') : 'AllDates';
    $sheets = vts_build_backup_sheets($conn, $from, $to);
    if ($scopeSheets[$scope] !== null) {
        $keep = $scopeSheets[$scope];
        $sheets = array_values(array_filter($sheets, fn($s) => in_array($s['name'] ?? '', $keep, true)));
    }
    vts_xlsx_download_multi(
        'VTS_Backup_' . ucfirst($scope) . '_' . $rangeLabel . '_' . date('Ymd-Hi') . '.xlsx',
        $sheets
    );
    exit();
}

// ---- Download straight to the browser ----
if (isset($_GET['download'])) {
    header('Content-Type: application/sql');
    header('Content-Disposition: attachment; filename="vts_backup_' . date('Y-m-d_His') . '.sql"');
    echo build_dump($conn);
    exit();
}

// ---- Save a copy ON THE SERVER (backups/ folder) ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_server']) && csrf_verify()) {
    $fname = "vts_backup_" . date('Y-m-d_His') . ".sql";
    $fpath = $backupDir . "/" . $fname;
    file_put_contents($fpath, build_dump($conn));

    /* The Drive upload that used to run here needed a Google Workspace
       account (a service account has no Drive storage of its own), so it
       failed on every backup. The server copy is what this button makes;
       "Open the Drive folder" below is the manual route, and the Email
       Backup option is the off-site copy that actually works today. */
    $msg = "Backup saved on the server: {$fname}";
    header("Location: backup.php?success=" . urlencode($msg));
    exit();
}

// ---- Email a copy via Gmail (BACKUP) — GDrive has a storage cap, this
//      doesn't. Same scope/date filters as the Excel download above, sized
//      down first so it stays well under Gmail/Brevo attachment limits. ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['email_backup']) && csrf_verify()) {
    $toEmail = trim($_POST['email_to'] ?? '');
    $eScope  = $_POST['email_scope'] ?? 'all';
    if (!isset($scopeOptions[$eScope])) $eScope = 'all';
    $eFrom = $isDate($_POST['email_date_from'] ?? '') ? $_POST['email_date_from'] : '';
    $eTo   = $isDate($_POST['email_date_to']   ?? '') ? $_POST['email_date_to']   : '';

    if (!$HAS_MAIL || !function_exists('vts_send_mail')) {
        header("Location: backup.php?error=" . urlencode("Mail isn't set up on this install yet — ask Admin to configure config/mail.php."));
        exit();
    }
    if (!filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
        header("Location: backup.php?error=" . urlencode("Enter a valid email address to send the backup to."));
        exit();
    }

    $sheets = vts_build_backup_sheets($conn, $eFrom, $eTo);
    if ($scopeSheets[$eScope] !== null) {
        $keep = $scopeSheets[$eScope];
        $sheets = array_values(array_filter($sheets, fn($s) => in_array($s['name'] ?? '', $keep, true)));
    }
    $rangeLabel = ($eFrom !== '' || $eTo !== '') ? ($eFrom ?: 'Start') . '_to_' . ($eTo ?: 'Now') : 'AllDates';
    $fname = 'VTS_Backup_' . ucfirst($eScope) . '_' . $rangeLabel . '_' . date('Ymd-Hi') . '.xlsx';

    $tmp = tempnam(sys_get_temp_dir(), 'vtsmailxlsx');
    $built = vts_xlsx_writable() && vts_xlsx_write_file($tmp, $sheets);
    if (!$built) {
        @unlink($tmp);
        header("Location: backup.php?error=" . urlencode("Couldn't build the Excel file on this server. Try 'Download' instead and attach it yourself."));
        exit();
    }

    // Gmail caps attachments around 25MB and Brevo's free tier is smaller
    // still — keep a wide safety margin so the send doesn't fail silently.
    $bytes = filesize($tmp);
    if ($bytes > 8 * 1024 * 1024) {
        @unlink($tmp);
        header("Location: backup.php?error=" . urlencode("That backup is " . number_format($bytes/1048576,1) . " MB — too big to email. Narrow the date range or scope, or use Download + USB instead."));
        exit();
    }

    $content = file_get_contents($tmp);
    @unlink($tmp);

    $subject = 'VTS Backup — ' . $scopeOptions[$eScope] . ' — ' . date('M j, Y g:i A');
    $body = vts_mail_shell('VTS Backup', '
        <p>Attached: <b>' . htmlspecialchars($fname) . '</b></p>
        <p style="color:var(--text-muted);font-size:.85rem;">Scope: ' . htmlspecialchars($scopeOptions[$eScope]) . '<br>
        Range: ' . htmlspecialchars($eFrom !== '' || $eTo !== '' ? (($eFrom ?: 'Start') . ' to ' . ($eTo ?: 'now')) : 'All dates') . '<br>
        Sent by: ' . htmlspecialchars($_SESSION['fullname'] ?? 'Admin') . ' (' . htmlspecialchars(vts_role_label($_SESSION['role'] ?? '')) . ')</p>
        <p style="color:var(--text-faint);font-size:.78rem;">Keep this alongside your regular Google Drive backups — it isn\'t limited by Drive storage.</p>
    ');
    $ok = vts_send_mail($toEmail, $toEmail, $subject, $body, [
        'content_base64' => base64_encode($content),
        'name' => $fname,
    ]);

    audit_log($conn, "Email Backup", "backups", null, "Emailed {$scopeOptions[$eScope]} backup to {$toEmail}: " . ($ok ? 'sent' : 'FAILED'));

    header("Location: backup.php?" . ($ok
        ? "success=" . urlencode("Backup emailed to {$toEmail}.")
        : "error=" . urlencode("Couldn't send that email right now. Check config/mail.php, or use Download + USB instead.")));
    exit();
}

// ---- Fetch a saved backup ----
if (isset($_GET['get'])) {
    $f = basename($_GET['get']);                       // no path tricks
    /* AUTOMATIC backups live in backups/auto/ and are named auto_*.sql. They
       were real, complete dumps that no page would hand back: the download
       only ever matched vts_backup_*.sql in the parent folder, so the safety
       net the app had been writing all along could not be recovered from the
       UI. Both shapes are downloadable now; the pattern still pins the name
       so nothing outside these two folders can be read. */
    $isAuto = (bool)preg_match('/^auto_[\w\-]+\.sql$/', $f);
    $path = $isAuto ? ($backupDir . "/auto/" . $f) : ($backupDir . "/" . $f);
    if (($isAuto || preg_match('/^vts_backup_[\w\-]+\.sql$/', $f)) && file_exists($path)) {
        header('Content-Type: application/sql');
        header('Content-Disposition: attachment; filename="' . $f . '"');
        readfile($path);
    }
    exit();
}

/* ---- RESTORE a backup back into the live system ----
   A backup you cannot put back is only half a backup: until now the only
   way to use one was to open phpMyAdmin by hand. Restoring overwrites live
   records, so it is gated three ways -- Admin only, CSRF, and the word
   RESTORE typed out -- and vts_restore_sql_dump() snapshots the current
   state before it changes anything, so an accidental restore is undoable. */
$restoreResult = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['do_restore'])) {
    if (!csrf_verify()) {
        $restoreResult = ['ok' => false, 'error' => 'Session expired. Please try again.'];
    } elseif (($_SESSION['role'] ?? '') !== 'Admin') {
        $restoreResult = ['ok' => false, 'error' => 'Only an Admin account can restore a backup.'];
    } elseif (strtoupper(trim($_POST['confirm'] ?? '')) !== 'RESTORE') {
        $restoreResult = ['ok' => false, 'error' => 'Type RESTORE in the confirmation box to go ahead. Nothing was changed.'];
    } else {
        $sqlText = null;
        $srcName = '';

        // Either a file that was just uploaded, or one already on the server.
        if (isset($_FILES['restore_file']) && ($_FILES['restore_file']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
            $up = $_FILES['restore_file'];
            if (strtolower(pathinfo($up['name'] ?? '', PATHINFO_EXTENSION)) !== 'sql') {
                $restoreResult = ['ok' => false, 'error' => 'Choose a .sql backup file.'];
            } elseif (!is_uploaded_file($up['tmp_name'])) {
                $restoreResult = ['ok' => false, 'error' => 'That upload did not arrive properly. Please try again.'];
            } else {
                $sqlText = (string)@file_get_contents($up['tmp_name']);
                $srcName = $up['name'];
            }
        } elseif (trim($_POST['saved_file'] ?? '') !== '') {
            $f = basename(trim($_POST['saved_file']));
            $isAutoPick = (bool)preg_match('/^auto_[\w\-]+\.sql$/', $f);
            $path = $isAutoPick ? ($backupDir . '/auto/' . $f) : ($backupDir . '/' . $f);
            if (!($isAutoPick || preg_match('/^vts_backup_[\w\-]+\.sql$/', $f)) || !is_file($path)) {
                $restoreResult = ['ok' => false, 'error' => 'That backup file could not be found on the server.'];
            } else {
                $sqlText = (string)@file_get_contents($path);
                $srcName = $f;
            }
        } else {
            $restoreResult = ['ok' => false, 'error' => 'Choose a backup to restore: upload a .sql file, or pick one already saved here.'];
        }

        if ($restoreResult === null && $sqlText !== null) {
            $r = vts_restore_sql_dump($conn, $sqlText);
            $r['source'] = $srcName;
            $restoreResult = $r;
            try {
                audit_log($conn, 'Restore Backup', 'database', null,
                          'Restored from ' . $srcName . ' — ' . (int)$r['statements'] . ' statement(s), '
                          . (int)$r['students']['restored'] . ' student(s) updated, '
                          . (int)$r['students']['created'] . ' re-created');
            } catch (Throwable $e) { /* audit is best-effort */ }
        }
    }
}

// ---- Delete a saved backup ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_file']) && csrf_verify()) {
    $f = basename($_POST['delete_file']);
    if (preg_match('/^vts_backup_[\w\-]+\.sql$/', $f)) @unlink($backupDir . "/" . $f);
    header("Location: backup.php?success=" . urlencode("Backup deleted."));
    exit();
}

/* The manual backups, newest first. Also the list the restore picker and the
   "last backup" clock are built from. */
$allSaved = array_values(array_filter(scandir($backupDir), fn($f) => preg_match('/^vts_backup_.*\.sql$/', $f)));
rsort($allSaved);

/* ---- Filters for the saved list (own params so they don't clash with the
   Excel-backup date range above). The filtering itself happens once the
   manual and automatic files have been merged into a single list. ---- */
$bq    = trim($_GET['bq']      ?? '');                                  // file name contains
$bfrom = $isDate($_GET['bfrom'] ?? '') ? $_GET['bfrom'] : '';           // saved on/after
$bto   = $isDate($_GET['bto']   ?? '') ? $_GET['bto']   : '';           // saved on/before
if ($bfrom !== '' && $bto !== '' && $bfrom > $bto) { [$bfrom, $bto] = [$bto, $bfrom]; }

// ---- Page ----
$counts = [];
$counts['students']   = (int)$conn->query("SELECT COUNT(*) FROM users WHERE role='Student'")->fetchColumn();
$counts['violations'] = (int)$conn->query("SELECT COUNT(*) FROM violations")->fetchColumn();

// Newest saved copy — shown as "Last backup" so it's obvious how current it is.
$lastBackupTime = null;
foreach ($allSaved as $f) {
    $t = @filemtime($backupDir . '/' . $f);
    if ($t && (!$lastBackupTime || $t > $lastBackupTime)) $lastBackupTime = $t;
}
$autoDir = $backupDir . '/auto';
/* The automatic safety net, listed rather than merely counted for the "last
   backup" clock. The panel used to report "0 file(s)" while a dozen real
   dumps sat in this folder -- so the honest answer to "did the backup
   actually happen?" was on disk the whole time and simply never shown. */
$autoBackups = [];
if (is_dir($autoDir)) {
    foreach (scandir($autoDir) as $f) {
        if ($f[0] === '.') continue;
        if (!preg_match('/\.sql$/i', $f)) continue;
        $t = @filemtime($autoDir . '/' . $f);
        $autoBackups[] = ['name' => $f, 'time' => (int)$t, 'size' => (int)@filesize($autoDir . '/' . $f)];
        if ($t && (!$lastBackupTime || $t > $lastBackupTime)) $lastBackupTime = $t;
    }
}
usort($autoBackups, fn($a, $b) => $b['time'] <=> $a['time']);
$autoTotal = count($autoBackups);
$autoShown = array_slice($autoBackups, 0, 15);

/* ---- ONE list of backups, both kinds ----
   These used to be two tables, side by side, saying the same four things
   about files that differ only in who asked for them. "Where is my backup?"
   had two places to look and no single newest-first order. Merged, with the
   kind kept as a column, because that is what it always was: a column. */
$allBackups = [];
foreach ($allSaved as $f) {
    $p = $backupDir . '/' . $f;
    $allBackups[] = ['name' => $f, 'time' => (int)@filemtime($p), 'size' => (int)@filesize($p), 'auto' => false];
}
foreach ($autoBackups as $ab) {
    $allBackups[] = ['name' => $ab['name'], 'time' => $ab['time'], 'size' => $ab['size'], 'auto' => true];
}
usort($allBackups, fn($a, $b) => $b['time'] <=> $a['time']);
$backupsTotal = count($allBackups);

/* Source filter sits alongside the name/date filters already parsed above. */
$bsrc = in_array($_GET['bsrc'] ?? '', ['manual', 'auto'], true) ? $_GET['bsrc'] : '';
$bkFiltered = ($bq !== '' || $bfrom !== '' || $bto !== '' || $bsrc !== '');

$bkList = array_values(array_filter($allBackups, function ($b) use ($bq, $bfrom, $bto, $bsrc) {
    if ($bq !== '' && stripos($b['name'], $bq) === false) return false;
    if ($bsrc === 'manual' && $b['auto'])  return false;
    if ($bsrc === 'auto'   && !$b['auto']) return false;
    $day = date('Y-m-d', $b['time']);
    if ($bfrom !== '' && $day < $bfrom) return false;
    if ($bto   !== '' && $day > $bto)   return false;
    return true;
}));

/* Is automatic filing to Drive actually working? The page used to talk the
   user through dragging files in by hand; now that the app files them
   itself, the one thing worth showing is whether that is switched on. */
/* The Drive CONNECTION status was shown here while backups filed themselves
   to Drive. That upload needed Google Workspace and has been removed, so
   there is no connection to report -- only a folder to open by hand. */

/* Carried into the Excel / .sql / email actions so the scope and date range
   are chosen ONCE at the top instead of three times down the page. */
$qsRange = ($from !== '' ? '&from=' . urlencode($from) : '') . ($to !== '' ? '&to=' . urlencode($to) : '');
$rangeText = ($from !== '' || $to !== '')
           ? (($from !== '' ? vts_date($from) : 'the start') . ' to ' . ($to !== '' ? vts_date($to) : 'today'))
           : 'All dates';

$assetBase = "../";
include "../includes/admin_header.php";
?>
<link rel="stylesheet" href="../assets/css/backup.css?v=<?php echo @filemtime(__DIR__."/../assets/css/backup.css"); ?>">
<div class="bk-page">

<div class="admin-welcome-row">
  <div><h1>Backup</h1><p>Take a copy of the records, or put a saved one back.</p></div>
  <?php if ($lastBackupTime): $stale = (time() - $lastBackupTime) > 7 * 86400; ?>
    <span class="bk-last<?php echo $stale ? ' stale' : ''; ?>">
      <i class="fas fa-<?php echo $stale ? 'clock-rotate-left' : 'circle-check'; ?>"></i>
      Last backup: <?php echo htmlspecialchars(vts_datetime($lastBackupTime)); ?>
    </span>
  <?php else: ?>
    <span class="bk-last stale"><i class="fas fa-triangle-exclamation"></i> No backup taken yet</span>
  <?php endif; ?>
</div>

<?php if (isset($_GET['success'])): ?>
  <div class="alert alert-success u-mb-16"><i class="fas fa-circle-check"></i> <?php echo htmlspecialchars($_GET['success']); ?></div>
<?php endif; ?>
<?php if (isset($_GET['error'])): ?>
  <div class="alert alert-error u-mb-16"><i class="fas fa-circle-exclamation"></i> <?php echo htmlspecialchars($_GET['error']); ?></div>
<?php endif; ?>

<!-- ── At a glance: how much data, and where it is being copied to ───── -->
<div class="bk-strip">
  <div class="bk-tile">
    <span class="bk-tile-ico blue"><i class="fas fa-user-graduate"></i></span>
    <span class="bk-tile-txt"><b><?php echo number_format($counts['students']); ?></b>Students</span>
  </div>
  <div class="bk-tile">
    <span class="bk-tile-ico red"><i class="fas fa-triangle-exclamation"></i></span>
    <span class="bk-tile-txt"><b><?php echo number_format($counts['violations']); ?></b>Violations</span>
  </div>
  <div class="bk-tile">
    <span class="bk-tile-ico slate"><i class="fas fa-box-archive"></i></span>
    <span class="bk-tile-txt"><b><?php echo number_format($backupsTotal); ?></b>Backups kept</span>
  </div>
  <div class="bk-tile">
    <span class="bk-tile-ico slate"><i class="fas fa-clock-rotate-left"></i></span>
    <span class="bk-tile-txt">
      <b><?php echo $lastBackupTime ? htmlspecialchars(vts_date($lastBackupTime)) : '—'; ?></b>
      Newest backup
    </span>
  </div>
</div>

<!-- ══ STEP 1 ═══════════════════════════════════════════════════════════
     Scope and date range, chosen once. The page used to ask for these
     three times over -- once for Excel, again for email, again for the
     saved list -- so the same backup could be described two ways on one
     screen. One choice now feeds every action below it. -->
<div class="bk-step">
  <div class="bk-step-head">
    <span class="bk-num">1</span>
    <div><h3>Choose what to back up</h3><p>Applies to every option in step&nbsp;2.</p></div>
  </div>

  <form method="GET" class="bk-filter">
    <div class="bk-field">
      <label for="bkScope">Records</label>
      <select name="scope" id="bkScope" class="vts-input">
        <?php foreach ($scopeOptions as $k => $lbl): ?>
          <option value="<?php echo $k; ?>" <?php echo $scope === $k ? 'selected' : ''; ?>><?php echo htmlspecialchars($lbl); ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="bk-field">
      <label for="bkFrom">From</label>
      <input type="date" name="from" id="bkFrom" class="vts-input" value="<?php echo htmlspecialchars($from); ?>">
    </div>
    <div class="bk-field">
      <label for="bkTo">To</label>
      <input type="date" name="to" id="bkTo" class="vts-input" value="<?php echo htmlspecialchars($to); ?>">
    </div>
    <div class="bk-field-actions">
      <button class="btn-primary" type="submit"><i class="fas fa-check"></i> Apply</button>
      <a href="backup.php" class="btn-outline"><i class="fas fa-rotate"></i> Reset</a>
    </div>
  </form>

  <p class="bk-summary">
    <i class="fas fa-circle-info"></i>
    Backing up <b><?php echo htmlspecialchars($scopeOptions[$scope]); ?></b> &middot; <b><?php echo htmlspecialchars($rangeText); ?></b>
  </p>
</div>

<!-- ══ STEP 2 ═══════════════════════════════════════════════════════════
     The three ways a backup leaves this server, as three equal cards
     rather than panels of different sizes scattered down the page. -->
<div class="bk-step">
  <div class="bk-step-head">
    <span class="bk-num">2</span>
    <div><h3>Take the backup out</h3><p>Pick whichever suits &mdash; they all contain the same records.</p></div>
  </div>

  <div class="bk-ways">

    <!-- Excel -->
    <div class="bk-way">
      <div class="bk-way-top">
        <span class="bk-way-ico green"><i class="fas fa-file-excel"></i></span>
        <div><h4>Excel workbook</h4><span class="bk-way-sub">.xlsx &mdash; opens in Excel</span></div>
      </div>
      <p>Readable by anyone. One sheet each for students, violations, the enrolled list and user accounts.</p>
      <div class="bk-sheets"><span>Students</span><span>Violations</span><span>Enrolled List</span><span>User Accounts</span></div>
      <a href="backup.php?excel=1&amp;scope=<?php echo urlencode($scope); ?><?php echo str_replace('&', '&amp;', $qsRange); ?>"
         class="btn-primary bk-way-btn js-bk-go" data-busy="Building your Excel backup…">
        <i class="fas fa-download"></i> Download Excel</a>
    </div>

    <!-- SQL -->
    <div class="bk-way">
      <div class="bk-way-top">
        <span class="bk-way-ico slate"><i class="fas fa-database"></i></span>
        <div><h4>Database copy</h4><span class="bk-way-sub">.sql &mdash; the complete one</span></div>
      </div>
      <p>The only format that can be <b>restored</b> in step&nbsp;4. Copy it onto a USB stick and the system can be rebuilt from it.</p>
      <div class="bk-way-facts">
        <span><i class="fas fa-bolt"></i> Saved automatically too</span>
        <span><i class="fas fa-usb"></i> USB-friendly</span>
      </div>
      <div class="bk-way-btns">
        <a href="backup.php?download=1" class="btn-primary bk-way-btn js-bk-go" data-busy="Preparing the .sql file…">
          <i class="fas fa-download"></i> Download .sql</a>
        <form method="POST" class="js-bk-form" data-busy="Saving a copy…">
          <?php echo csrf_field(); ?><input type="hidden" name="save_server" value="1">
          <button type="submit" class="btn-outline bk-way-btn"
                  title="Keeps a .sql copy in this server's backups folder">
            <i class="fas fa-server"></i> Save a copy</button>
        </form>
      </div>
    </div>

    <!-- Email -->
    <div class="bk-way">
      <div class="bk-way-top">
        <span class="bk-way-ico blue"><i class="fas fa-envelope"></i></span>
        <div><h4>Email it</h4><span class="bk-way-sub">Excel, straight to an inbox</span></div>
      </div>
      <p>Useful when you're online with no USB to hand. Kept under 8&nbsp;MB &mdash; narrow the range in step&nbsp;1 if it won't fit.</p>
      <form method="POST" class="js-bk-form bk-way-form" data-busy="Sending the backup…">
        <?php echo csrf_field(); ?>
        <!-- Scope and dates come from step 1, so nobody fills them in twice. -->
        <input type="hidden" name="email_scope"      value="<?php echo htmlspecialchars($scope); ?>">
        <input type="hidden" name="email_date_from"  value="<?php echo htmlspecialchars($from); ?>">
        <input type="hidden" name="email_date_to"    value="<?php echo htmlspecialchars($to); ?>">
        <label for="bkMailTo">Send to</label>
        <input type="email" name="email_to" id="bkMailTo" class="vts-input" placeholder="osa@gwc.edu.ph"
               value="<?php echo htmlspecialchars(defined('OSA_REPORT_EMAIL') ? OSA_REPORT_EMAIL : ''); ?>" required>
        <button type="submit" name="email_backup" value="1" class="btn-primary bk-way-btn">
          <i class="fas fa-paper-plane"></i> Send backup</button>
      </form>
    </div>

  </div>

  <div class="bk-busy" id="bkBusy">
    <i class="fas fa-circle-notch fa-spin"></i>
    <span id="bkBusyText">Working…</span>
    <span class="bk-bar"><span></span></span>
  </div>

  <p class="bk-summary">
    <i class="fab fa-google-drive"></i>
    Drive filing is manual: take a backup above, then
    <a href="<?php echo htmlspecialchars($backupDriveUrl); ?>" target="_blank" rel="noopener">open the Drive folder</a>
    and drag the file in. Keep a USB copy as well &mdash; one copy is never a backup.
  </p>
</div>

<!-- ══ STEP 3 ═══════════════════════════════════════════════════════════ -->
<div class="bk-step">
  <div class="bk-step-head">
    <span class="bk-num">3</span>
    <div><h3>Backups you already have</h3>
      <p>Everything on this server, newest first &mdash; the ones you took and the ones the system took by itself.</p></div>
    <span class="bk-count"><?php echo count($bkList); ?><?php echo $bkFiltered ? ' of ' . $backupsTotal : ''; ?> file(s)</span>
  </div>

  <form method="GET" class="bk-filter compact">
    <?php if ($scope !== 'all'): ?><input type="hidden" name="scope" value="<?php echo htmlspecialchars($scope); ?>"><?php endif; ?>
    <?php if ($from !== ''): ?><input type="hidden" name="from" value="<?php echo htmlspecialchars($from); ?>"><?php endif; ?>
    <?php if ($to   !== ''): ?><input type="hidden" name="to"   value="<?php echo htmlspecialchars($to); ?>"><?php endif; ?>
    <div class="bk-field grow">
      <label for="bkQ">File name</label>
      <input type="text" name="bq" id="bkQ" class="vts-input" placeholder="Search…" value="<?php echo htmlspecialchars($bq); ?>">
    </div>
    <div class="bk-field">
      <label for="bkSrc">Kind</label>
      <select name="bsrc" id="bkSrc" class="vts-input">
        <option value="">All</option>
        <option value="manual" <?php echo $bsrc === 'manual' ? 'selected' : ''; ?>>Taken by a person</option>
        <option value="auto"   <?php echo $bsrc === 'auto'   ? 'selected' : ''; ?>>Taken automatically</option>
      </select>
    </div>
    <div class="bk-field">
      <label for="bkSavedFrom">Saved from</label>
      <input type="date" name="bfrom" id="bkSavedFrom" class="vts-input" value="<?php echo htmlspecialchars($bfrom); ?>">
    </div>
    <div class="bk-field">
      <label for="bkSavedTo">To</label>
      <input type="date" name="bto" id="bkSavedTo" class="vts-input" value="<?php echo htmlspecialchars($bto); ?>">
    </div>
    <div class="bk-field-actions">
      <button class="btn-primary" type="submit"><i class="fas fa-magnifying-glass"></i> Find</button>
      <a href="backup.php" class="btn-outline"><i class="fas fa-rotate"></i> Clear</a>
    </div>
  </form>

  <?php if (count($bkList) > 0): ?>
    <div class="bk-table-wrap table-responsive">
      <table class="data-table bk-table">
        <thead><tr><th>File</th><th>Kind</th><th>Size</th><th>Saved</th><th>Status</th><th class="ta-r">Actions</th></tr></thead>
        <tbody>
        <?php foreach ($bkList as $b):
          /* A dump too small to even hold the schema never really succeeded. */
          $okFile = $b['size'] > 1024;
        ?>
          <tr>
            <td class="bk-fname"><i class="fas fa-file-code"></i> <?php echo htmlspecialchars($b['name']); ?></td>
            <td><span class="pill <?php echo $b['auto'] ? 'bk-pill-auto' : 'bk-pill-manual'; ?>">
              <?php echo $b['auto'] ? 'Automatic' : 'Manual'; ?></span></td>
            <td class="nowrap"><?php echo number_format($b['size'] / 1024, 1); ?> KB</td>
            <td class="nowrap"><?php echo htmlspecialchars(vts_datetime($b['time'])); ?></td>
            <td class="nowrap">
              <?php if ($okFile): ?>
                <span class="pill resolved" title="A complete dump was written to disk">Saved</span>
              <?php else: ?>
                <span class="pill atrisk" title="This file is too small to be a usable dump">Incomplete</span>
              <?php endif; ?>
            </td>
            <td class="nowrap ta-r">
              <a href="backup.php?get=<?php echo urlencode($b['name']); ?>" class="btn-outline btn-sm" title="Download this backup">
                <i class="fas fa-download"></i></a>
              <?php if (!$b['auto']): ?>
                <form method="POST" class="inline-form" style="display:inline;"
                      onsubmit="return confirm('Delete this backup file? This cannot be undone.');">
                  <?php echo csrf_field(); ?>
                  <input type="hidden" name="delete_file" value="<?php echo htmlspecialchars($b['name']); ?>">
                  <button type="submit" class="btn-danger btn-sm" title="Delete"><i class="fas fa-trash"></i></button>
                </form>
              <?php else: ?>
                <span class="bk-lockicon" title="The system manages its own backups"><i class="fas fa-lock"></i></span>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php else: ?>
    <div class="bk-empty">
      <span class="bk-ill"><i class="fas fa-box-open"></i></span>
      <?php if ($bkFiltered): ?>
        <b>No backups match that search</b>
        <a href="backup.php" class="btn-outline u-mt-12"><i class="fas fa-rotate"></i> Clear filter</a>
      <?php else: ?>
        <b>No backups on this server yet</b>
        <p>Use <b>Save a copy</b> in step 2 to make the first one.</p>
      <?php endif; ?>
    </div>
  <?php endif; ?>
</div>

<!-- ══ STEP 4 ═══════════════════════════════════════════════════════════
     Kept last and visually apart: it is the only control on this page that
     CHANGES records rather than copying them. -->
<div class="bk-step danger">
  <div class="bk-step-head">
    <span class="bk-num danger">4</span>
    <div><h3>Restore a backup</h3><p>Puts saved records back into the system.</p></div>
    <span class="bk-count danger">Changes live records</span>
  </div>

  <?php if ($restoreResult !== null): ?>
    <?php if (!empty($restoreResult['ok'])): ?>
      <div class="alert alert-success u-mb-14">
        <i class="fas fa-circle-check"></i>
        <strong>Restored from <?php echo htmlspecialchars($restoreResult['source'] ?? 'backup'); ?>.</strong>
        <?php echo (int)$restoreResult['statements']; ?> statement(s) applied &middot;
        <?php echo (int)$restoreResult['students']['restored']; ?> student(s) put back &middot;
        <?php echo (int)$restoreResult['students']['created']; ?> re-created.
        <?php if (!empty($restoreResult['safety'])): ?>
          <div style="margin-top:4px;font-size:.85rem;">
            The state from just before this restore was saved first &mdash; it is the newest
            <b>Automatic</b> file in step&nbsp;3. Restore that one to undo this.
          </div>
        <?php endif; ?>
      </div>
    <?php else: ?>
      <div class="alert alert-error u-mb-14">
        <i class="fas fa-circle-exclamation"></i>
        <strong>Nothing was restored.</strong>
        <?php echo htmlspecialchars($restoreResult['error'] ?? 'The restore did not run.'); ?>
        <?php if (!empty($restoreResult['failed'])): ?>
          <div style="margin-top:4px;font-size:.85rem;">
            <?php echo (int)$restoreResult['failed']; ?> statement(s) failed out of
            <?php echo (int)$restoreResult['statements'] + (int)$restoreResult['failed']; ?>.
          </div>
        <?php endif; ?>
      </div>
    <?php endif; ?>
  <?php endif; ?>

  <p class="bk-danger-note">
    Records that exist now are overwritten by the backup's version.
    <b>A snapshot of the current state is saved first</b>, so a restore done by mistake can itself be undone.
    Only a <b>.sql</b> file can be restored &mdash; an Excel backup is for reading, not for putting back.
  </p>

  <form method="POST" enctype="multipart/form-data" class="js-bk-form bk-restore"
        data-busy="Restoring the backup&hellip;"
        onsubmit="return confirm('Restore this backup? Records in the system now will be replaced by the ones in the backup.');">
    <?php echo csrf_field(); ?>
    <input type="hidden" name="do_restore" value="1">

    <div class="bk-restore-grid">
      <div class="vts-form-group">
        <label for="savedFile">Pick one saved on this server</label>
        <select name="saved_file" id="savedFile" class="vts-select">
          <option value="">Select a backup&hellip;</option>
          <?php if (!empty($allSaved)): ?>
            <optgroup label="Taken by a person">
              <?php foreach ($allSaved as $sf): ?>
                <option value="<?php echo htmlspecialchars($sf); ?>"><?php echo htmlspecialchars($sf); ?></option>
              <?php endforeach; ?>
            </optgroup>
          <?php endif; ?>
          <?php if (!empty($autoBackups)): ?>
            <optgroup label="Taken automatically (newest first)">
              <?php foreach (array_slice($autoBackups, 0, 20) as $ab): ?>
                <option value="<?php echo htmlspecialchars($ab['name']); ?>">
                  <?php echo htmlspecialchars($ab['name']); ?> &mdash; <?php echo htmlspecialchars(vts_datetime($ab['time'])); ?>
                </option>
              <?php endforeach; ?>
            </optgroup>
          <?php endif; ?>
        </select>
      </div>

      <div class="vts-form-group">
        <label for="restoreFile">&hellip; or upload a .sql file</label>
        <input type="file" name="restore_file" id="restoreFile" class="vts-input" accept=".sql">
        <small class="field-hint">An uploaded file wins over the list.</small>
      </div>

      <div class="vts-form-group">
        <label for="confirmBox">Type <b>RESTORE</b> to confirm</label>
        <input type="text" name="confirm" id="confirmBox" class="vts-input"
               placeholder="RESTORE" autocomplete="off" spellcheck="false" required>
      </div>
    </div>

    <button type="submit" class="btn-danger bk-way-btn">
      <i class="fas fa-rotate-left"></i> Restore this backup
    </button>
  </form>
</div>

</div><!-- /.bk-page -->

<script>
/* Backup actions can take a moment on a big database — show that something is
   happening instead of leaving the page looking frozen. */
(function(){
  var busy = document.getElementById('bkBusy'),
      text = document.getElementById('bkBusyText');
  function start(msg){
    if (!busy) return;
    text.textContent = msg || 'Working…';
    busy.classList.add('on');
    busy.scrollIntoView({block:'nearest', behavior:'smooth'});
    // A download doesn't navigate, so clear it once the file has been handed over.
    setTimeout(function(){ busy.classList.remove('on'); }, 12000);
  }
  document.querySelectorAll('.js-bk-go').forEach(function(a){
    a.addEventListener('click', function(){ start(a.dataset.busy); });
  });
  document.querySelectorAll('.js-bk-form').forEach(function(f){
    f.addEventListener('submit', function(){ start(f.dataset.busy); });
  });
})();
</script>
<?php include "../includes/admin_footer.php"; ?>
