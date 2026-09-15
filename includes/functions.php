<?php
/* Shared helper functions, aligned to the real SVTMS schema
   (tables: users, violations). */

/* Philippine time everywhere (server clock is usually UTC). */
date_default_timezone_set('Asia/Manila');

/* Site-wide date/time format. Accepts a DB string or unix timestamp.
   date -> 07/17/2026   time -> 4:17 PM */
function vts_ts($v)       { return is_numeric($v) ? (int)$v : strtotime((string)$v); }
function vts_date($v)     { return $v ? date('m/d/Y', vts_ts($v)) : '-'; }
function vts_time($v)     { return $v ? date('g:i A', vts_ts($v)) : '-'; }
function vts_datetime($v) { return $v ? date('m/d/Y g:i A', vts_ts($v)) : '-'; }

/* "5m ago" / "3h ago" / "Yesterday" — for the notification panel, where a
   full timestamp on every row is more precision than the eye wants. Falls
   back to the plain date once "ago" stops being a useful way to say it. */
function vts_time_ago($v) {
    $t = vts_ts($v);
    if (!$t) return '';
    $s = time() - $t;
    if ($s < 0)      return vts_date($v);      // clock skew — don't say "-3m ago"
    if ($s < 60)     return 'Just now';
    if ($s < 3600)   return floor($s / 60) . 'm ago';
    if ($s < 86400)  return floor($s / 3600) . 'h ago';
    if ($s < 172800) return 'Yesterday';
    if ($s < 604800) return floor($s / 86400) . 'd ago';
    return vts_date($v);
}

/* Quick report ranges (Today / Yesterday / This Week / This Month /
   This Year / Custom). Returns [$from, $to] as Y-m-d strings ready for
   DATE(date_reported) BETWEEN comparisons; 'custom' keeps the dates the
   user picked, '' (All time) clears both. Shared by every Reports page. */
function vts_report_range($range, $from = '', $to = '') {
    $today = date('Y-m-d');
    switch ($range) {
        case 'today':     return [$today, $today];
        case 'yesterday': $d = date('Y-m-d', strtotime('-1 day')); return [$d, $d];
        case 'week':      return [date('Y-m-d', strtotime('monday this week')), $today];
        case 'month':     return [date('Y-m-01'), $today];
        case 'year':      return [date('Y-01-01'), $today];
        case 'custom':    return [$from, $to];
        default:          return ['', ''];
    }
}

$vtsAutoload = __DIR__ . '/../vendor/autoload.php';
if (file_exists($vtsAutoload)) require_once $vtsAutoload;

function clean($data) {
    return htmlspecialchars(trim($data));
}

/* =====================================================================
   ON-DUTY MARSHAL SYSTEM
   ---------------------------------------------------------------------
   This replaces the old model, where an Admin/OSA staffer hand-picked
   exactly which 2 student accounts were EVER allowed to touch the
   scanner (a permanent `scanner_access` flag, set from a checkbox on the
   Students page). That meant: assigning a marshal was an office chore
   done in advance, an unused slot sat reserved for weeks, and there was
   no way to see who was actually holding the scanner right now.

   Now ANY active Student (or a dedicated Guard account) can go on duty
   through the scanner's own "who's on duty" screen. What's capped is not
   who is ALLOWED, but how many can be ACTIVE AT ONCE: at most 2 concurrent
   duty sessions, system-wide, tracked the same way a duty session was
   already tracked (`scanner_session_token` / `scanner_session_expires`).
   A 3rd distinct person trying to claim a slot while both are taken is
   refused, and the office is notified by name and School ID — the
   "notify unknown if there's a third" ask — rather than silently locked
   out with no record anyone tried.

   A master switch (system_settings.scanning_enabled) lets Admin pause the
   whole feature without touching any account — it is on the Scanner & duty
   card on the Settings page (was a sidebar widget, and was Admin + OSA). */

/** Is the scanning feature turned on system-wide? Default ON so an
 *  upgrading install is not silently locked out until someone visits the
 *  new sidebar setting. */
function vts_scanning_enabled($conn): bool {
    vts_ensure_missing_columns($conn, 'system_settings', [
        'scanning_enabled' => 'TINYINT(1) NOT NULL DEFAULT 1',
    ]);
    try {
        $v = $conn->query("SELECT scanning_enabled FROM system_settings LIMIT 1")->fetchColumn();
        return $v === false ? true : (int)$v === 1;
    } catch (Throwable $e) { return true; }
}

/** The master switch behind Settings → Scanner & duty (Admin only).
 *
 *  TURNING IT OFF ALSO ENDS EVERY SHIFT THAT IS ALREADY RUNNING. It used to
 *  set the flag and nothing else, which only stopped the NEXT person: anyone
 *  already holding a slot kept scanning, because their phone had a live
 *  session token and the flag was never consulted again. "Scanning is off"
 *  therefore meant "off for people who are not currently scanning", which is
 *  the opposite of what an office switching it off in a hurry needs.
 *
 *  Ending a shift is exactly what admin/sign_off_duty.php does one marshal at
 *  a time — clear the token, tell them — so this is that, for everyone. Their
 *  phone's 20-second check then fails and drops them back to the duty screen
 *  with the reason (see api/scanner_session.php).
 */
function vts_set_scanning_enabled($conn, bool $enabled, int $changedBy): array {
    vts_ensure_missing_columns($conn, 'system_settings', [
        'scanning_enabled' => 'TINYINT(1) NOT NULL DEFAULT 1',
    ]);
    try {
        $conn->exec("UPDATE system_settings SET scanning_enabled = " . ($enabled ? 1 : 0));

        $endedNames = [];
        if (!$enabled) {
            $endedNames = vts_end_all_shifts($conn,
                'Scanning was switched off by the Office of Student Affairs, so your shift ended. '
              . 'Any scans still on the phone are safe — they stay on the device and go out in the '
              . 'end-of-shift file. Sign on again once scanning is back on.');
        }

        audit_log($conn, $enabled ? 'Enable Scanning' : 'Disable Scanning', 'system_settings', 0,
            'Changed by user #' . $changedBy
            . ($endedNames ? ' — ended ' . count($endedNames) . ' live shift(s): ' . implode(', ', $endedNames) : ''));

        if ($enabled) return [true, 'Scanning is now enabled.'];

        $msg = 'Scanning is now disabled — nobody can go on duty until this is turned back on.';
        if ($endedNames) {
            $msg .= ' ' . (count($endedNames) === 1 ? 'One marshal was' : count($endedNames) . ' marshals were')
                  . ' signed off and sent back to the sign-in screen: ' . implode(', ', $endedNames) . '.';
        }
        return [true, $msg];
    } catch (Throwable $e) {
        error_log('vts_set_scanning_enabled failed: ' . $e->getMessage());
        return [false, 'Could not change that setting. Please try again.'];
    }
}

/** End every shift that is live right now. Returns the names ended, so the
 *  caller can say who rather than how many — the office needs to know which
 *  phones are about to bounce, and the audit entry should name them.
 *
 *  Notifying is best-effort and per-marshal: a notification that fails must
 *  not leave the slot held, which is the whole thing this is here to prevent. */
function vts_end_all_shifts($conn, string $why): array {
    $ended = [];
    try {
        $live = $conn->query("SELECT id, fullname, COALESCE(NULLIF(student_id,''), username) AS ident
                              FROM users WHERE scanner_session_expires > NOW()")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($live as $m) {
            vts_release_duty_slot($conn, (int)$m['id']);
            $ended[] = $m['fullname'] . ' (' . $m['ident'] . ')';
            try { notify_once($conn, (int)$m['id'], 'Your scanner shift was ended', $why); }
            catch (Throwable $e) { /* the sign-off itself already succeeded */ }
        }
    } catch (Throwable $e) { error_log('vts_end_all_shifts failed: ' . $e->getMessage()); }
    return $ended;
}

/* ---------------------------------------------------------------------
   THE DUTY SCHEDULE — Mon–Sat, 07:30–18:00 by default (config/app.php).

   Two different questions, deliberately kept apart:
     vts_duty_window_open()  may a shift START right now?
     vts_duty_session_end()  when must a shift that starts now END?

   The second is not just the first plus eight hours any more. A session
   used to run 8 hours from whenever it was claimed, so a 5:55pm start ran
   to 1:55am — past closing, on an unstaffed gate. It now ends when the
   window does, plus DUTY_SYNC_GRACE minutes so the marshal closing up can
   still sync and export. Claiming inside the grace is still refused: the
   grace exists to finish a shift, not to begin one.
   --------------------------------------------------------------------- */

/* WHERE THE HOURS LIVE.

   They started as constants in config/app.php, which meant changing them
   was a code edit — no use to an office that wants to close early on an
   exam week. They are stored in system_settings now and edited from the
   sidebar's On duty panel. The constants stayed on as the DEFAULTS: a
   database that has never been edited answers exactly as before, and a
   value that is missing or corrupt falls back to them rather than to
   "closed forever", which would silently strand every marshal. */
function vts_ensure_duty_columns($conn): void {
    static $done = false;
    if ($done) return;
    $done = true;
    vts_ensure_missing_columns($conn, 'system_settings', [
        'duty_days'  => "VARCHAR(20) NULL",
        'duty_start' => "VARCHAR(5) NULL",
        'duty_end'   => "VARCHAR(5) NULL",
    ]);
}

/** The stored schedule, read once per request. Falls back to config/app.php.
 *  $reload re-reads after a write — see vts_duty_config_reset(). */
function vts_duty_config(bool $reload = false): array {
    static $cfg = null;
    if ($cfg !== null && !$reload) return $cfg;

    $defDays  = defined('DUTY_DAYS')  ? (array)DUTY_DAYS : [1, 2, 3, 4, 5, 6];
    $defStart = defined('DUTY_START') ? DUTY_START : '07:30';
    $defEnd   = defined('DUTY_END')   ? DUTY_END   : '18:00';

    $row = [];
    global $conn;
    if (isset($conn)) {
        try {
            vts_ensure_duty_columns($conn);
            $row = $conn->query("SELECT duty_days, duty_start, duty_end FROM system_settings LIMIT 1")
                        ->fetch(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) { $row = []; }
    }

    $days = $defDays;
    if (!empty($row['duty_days'])) {
        $parsed = array_filter(array_map('intval', explode(',', (string)$row['duty_days'])),
                               fn($d) => $d >= 1 && $d <= 7);
        if ($parsed) $days = array_values(array_unique($parsed));
    }
    sort($days);

    $cfg = [
        'days'  => $days ?: $defDays,
        'start' => !empty($row['duty_start']) ? (string)$row['duty_start'] : $defStart,
        'end'   => !empty($row['duty_end'])   ? (string)$row['duty_end']   : $defEnd,
    ];
    return $cfg;
}

/** The configured days, as date('N') numbers (Mon=1 … Sun=7). */
function vts_duty_days(): array {
    $days = vts_duty_config()['days'];
    return $days ?: [1, 2, 3, 4, 5, 6];
}

/** "07:30" -> seconds since midnight. Bad values fall back rather than throw. */
function vts_duty_seconds(string $hhmm, int $fallback): int {
    if (!preg_match('/^(\d{1,2}):(\d{2})$/', trim($hhmm), $m)) return $fallback;
    $h = (int)$m[1]; $i = (int)$m[2];
    if ($h > 23 || $i > 59) return $fallback;
    return $h * 3600 + $i * 60;
}

function vts_duty_start_seconds(): int {
    return vts_duty_seconds(vts_duty_config()['start'], 7 * 3600 + 1800);
}
function vts_duty_end_seconds(): int {
    return vts_duty_seconds(vts_duty_config()['end'], 18 * 3600);
}

/** Save a new schedule. Returns [ok, message]. Validates hard, because a
 *  window whose end is before its start would close the gate permanently. */
function vts_set_duty_schedule($conn, array $days, string $start, string $end, int $changedBy): array {
    vts_ensure_duty_columns($conn);

    $days = array_values(array_unique(array_filter(array_map('intval', $days), fn($d) => $d >= 1 && $d <= 7)));
    sort($days);
    if (!$days) return [false, 'Pick at least one day — with none, nobody could ever go on duty.'];

    $isTime = fn($t) => (bool)preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', trim($t));
    if (!$isTime($start) || !$isTime($end)) return [false, 'Enter both times as HH:MM (for example 07:30).'];

    $s = vts_duty_seconds($start, -1);
    $e = vts_duty_seconds($end, -1);
    if ($s < 0 || $e < 0)  return [false, 'Those times could not be read. Use HH:MM.'];
    if ($e <= $s)          return [false, 'The closing time has to be after the opening time.'];

    try {
        $conn->prepare("UPDATE system_settings SET duty_days = :d, duty_start = :s, duty_end = :e")
             ->execute([':d' => implode(',', $days), ':s' => trim($start), ':e' => trim($end)]);
    } catch (Throwable $e2) {
        error_log('vts_set_duty_schedule failed: ' . $e2->getMessage());
        return [false, 'Could not save the schedule. Please try again.'];
    }

    // The static cache was populated from the OLD row earlier in this request.
    vts_duty_config_reset();
    audit_log($conn, 'Duty Schedule Changed', 'system_settings', 0,
        'Now ' . vts_duty_window_label() . ' — changed by user #' . $changedBy);
    return [true, 'Duty hours saved — now ' . vts_duty_window_label() . '.'];
}

/** Drop the per-request cache after a write, so the page that just saved
 *  renders (and audit-logs) the NEW hours rather than the ones it read on
 *  the way in. */
function vts_duty_config_reset(): void {
    vts_duty_config(true);
}

/** May a shift start at this moment? */
function vts_duty_window_open(?int $ts = null): bool {
    $ts  = $ts ?? time();
    $day = (int)date('N', $ts);
    if (!in_array($day, vts_duty_days(), true)) return false;
    $secs = (int)date('G', $ts) * 3600 + (int)date('i', $ts) * 60 + (int)date('s', $ts);
    return $secs >= vts_duty_start_seconds() && $secs < vts_duty_end_seconds();
}

/** When a shift claimed now must end, as 'Y-m-d H:i:s' (window close + grace). */
function vts_duty_session_end(?int $ts = null): string {
    $ts    = $ts ?? time();
    $grace = (defined('DUTY_SYNC_GRACE') ? (int)DUTY_SYNC_GRACE : 120) * 60;
    $close = strtotime(date('Y-m-d', $ts) . ' 00:00:00') + vts_duty_end_seconds();
    return date('Y-m-d H:i:s', $close + $grace);
}

/** "Mon–Sat, 7:30 AM – 6:00 PM" — one place so the scanner, the sidebar and
 *  the refusal message cannot describe the schedule three different ways. */
function vts_duty_window_label(): string {
    $days  = vts_duty_days();
    $names = [1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun'];
    sort($days);
    // Contiguous run (the normal case) reads as a range; anything else lists.
    $isRun = count($days) > 1 && ($days[count($days) - 1] - $days[0] + 1) === count($days);
    $dayPart = $isRun
        ? $names[$days[0]] . '–' . $names[$days[count($days) - 1]]
        : implode(', ', array_map(fn($d) => $names[$d], $days));
    $fmt = fn(int $secs) => date('g:i A', strtotime('today') + $secs);
    return $dayPart . ', ' . $fmt(vts_duty_start_seconds()) . ' – ' . $fmt(vts_duty_end_seconds());
}

/** "today at 7:30 AM" / "Monday at 7:30 AM" — for the refusal message, so a
 *  marshal knows when to come back instead of just being told no. */
function vts_duty_next_open(?int $ts = null): string {
    $ts    = $ts ?? time();
    $days  = vts_duty_days();
    $start = vts_duty_start_seconds();
    $open  = date('g:i A', strtotime('today') + $start);

    for ($i = 0; $i <= 7; $i++) {
        $day  = strtotime("+{$i} day", $ts);
        if (!in_array((int)date('N', $day), $days, true)) continue;
        // Today only counts if the window has not already opened.
        $secsNow = (int)date('G', $ts) * 3600 + (int)date('i', $ts) * 60;
        if ($i === 0 && $secsNow >= $start) continue;
        if ($i === 0) return 'today at ' . $open;
        if ($i === 1) return 'tomorrow at ' . $open;
        return date('l', $day) . ' at ' . $open;
    }
    return $open;
}

/** Everyone currently on duty: a live scanner session that has not expired.
 *  This IS the roster admin/OSA see in the sidebar — no separate table to
 *  drift out of sync with reality, because reality is exactly "who has a
 *  session right now". */
function vts_on_duty_roster($conn): array {
    try {
        return $conn->query("SELECT id, fullname, student_id, username, role, scanner_session_expires
                              FROM users
                              WHERE scanner_session_expires > NOW()
                              ORDER BY scanner_session_expires DESC")
                    ->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { return []; }
}

/** Try to put one account on duty. Returns [ok, message, sessionToken|null].
 *  $accountId must already be a verified, active Guard or Student — this
 *  function only decides whether a SLOT is available, it does not itself
 *  check who is allowed to hold the scanner at all (api/scanner_session.php
 *  does that lookup first, same as before). */
function vts_claim_duty_slot($conn, int $accountId, string $accountName, string $schoolId): array {
    if (!vts_scanning_enabled($conn)) {
        return [false, 'Scanning is currently turned off by the office. Try again later.', null];
    }
    /* Outside the posted hours nobody starts a shift — including inside the
       sync grace, which exists to FINISH one. Says when it opens next, so
       this reads as a schedule rather than a fault. */
    if (!vts_duty_window_open()) {
        return [false, 'The gate is scanned ' . vts_duty_window_label()
                     . '. You can go on duty again ' . vts_duty_next_open() . '.', null];
    }
    try {
        $conn->beginTransaction();
        // Lock every row that could count toward the cap so two claims
        // arriving at the same instant cannot both slip through as slot #2.
        $active = $conn->query("SELECT id FROM users WHERE scanner_session_expires > NOW() FOR UPDATE")
                       ->fetchAll(PDO::FETCH_COLUMN);
        $othersActive = array_values(array_diff($active, [$accountId]));

        if (count($othersActive) >= 2) {
            $conn->rollBack();
            // The refused person by name/ID, so the office can see WHO was
            // turned away rather than just that a limit was hit.
            vts_notify_overseers($conn, 'Scanner: a third marshal was turned away',
                $accountName . ' (School ID ' . $schoolId . ') tried to go on duty, but 2 marshals are already active. '
                . 'Sign one of them off under Settings → Scanner & duty if this one should take over.',
                null, ['Admin']);
            return [false, 'Two marshals are already on duty. Ask the office to sign one off, or try again once a shift ends.', null];
        }

        $token = bin2hex(random_bytes(32));
        /* Ends when the window does (+ the sync grace), NOT 8 hours from now:
           a 5:55pm start used to run to 1:55am on a gate nobody was at. */
        $conn->prepare("UPDATE users SET scanner_session_token=:t, scanner_session_expires=:e WHERE id=:id")
             ->execute([':t' => $token, ':e' => vts_duty_session_end(), ':id' => $accountId]);
        $conn->commit();

        // Best-effort visibility ping — notify_once collapses duplicates
        // inside 60s, so re-claiming (e.g. a phone reconnecting) does not spam.
        try {
            vts_notify_overseers($conn, 'Marshal on duty',
                $accountName . ' (School ID ' . $schoolId . ') is now on duty at the scanner.');
        } catch (Throwable $e) {}

        return [true, 'On duty.', $token];
    } catch (Throwable $e) {
        if ($conn->inTransaction()) $conn->rollBack();
        error_log('vts_claim_duty_slot failed: ' . $e->getMessage());
        return [false, 'Could not start your shift right now. Please try again.', null];
    }
}

/** Explicitly free a slot — called when a marshal finishes a shift or
 *  switches out mid-device, so the other slot doesn't sit reserved for the
 *  rest of the 8-hour window after someone is actually done. */
function vts_release_duty_slot($conn, int $accountId): void {
    try {
        $conn->prepare("UPDATE users SET scanner_session_token=NULL, scanner_session_expires=NULL WHERE id=:id")
             ->execute([':id' => $accountId]);
    } catch (Throwable $e) { error_log('vts_release_duty_slot failed: ' . $e->getMessage()); }
}

/* ---------------- Real Excel (.xlsx) export / import (shared) ---------------- */

/**
 * Stream a CSV file (UTF-8 with a BOM so Excel opens it cleanly) and exit.
 * CSV is the interchange format across the WHOLE system: it imports on any server
 * — no PHP zip extension needed, unlike .xlsx, which can't even be READ here — and
 * still opens in Excel. The filename is always normalised to end in .csv.
 */
function vts_csv_download($filename, array $headers, array $rows) {
    $filename = preg_replace('/\.(xlsx|xls)$/i', '', (string)$filename);
    if (!preg_match('/\.csv$/i', $filename)) $filename .= '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-store');
    $out = fopen('php://output', 'w');
    fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF));   // UTF-8 BOM
    fputcsv($out, $headers);
    foreach ($rows as $r) fputcsv($out, $r);
    fclose($out);
    exit();
}

/**
 * True when a real .xlsx can be WRITTEN on this server. Only PhpSpreadsheet is
 * required — its Xlsx writer uses the bundled ZipStream, so it produces a valid
 * .xlsx even on hosts without PHP's ext-zip. (Reading .xlsx still needs ext-zip;
 * that's a separate concern handled in vts_xlsx_read_rows.)
 */
function vts_xlsx_writable() {
    return class_exists('PhpOffice\PhpSpreadsheet\Spreadsheet')
        && class_exists('PhpOffice\PhpSpreadsheet\Writer\Xlsx');
}

/**
 * Stream a genuine single-sheet .xlsx and exit. Falls back to CSV (same data)
 * when this server can't write .xlsx, so a caller never breaks.
 */
function vts_xlsx_download($filename, array $headers, array $rows) {
    if (!vts_xlsx_writable()) { vts_csv_download($filename, $headers, $rows); return; }
    vts_xlsx_download_multi($filename, [[
        'name' => 'Violations', 'headers' => $headers, 'rows' => $rows,
    ]]);
}

/**
 * Stream a genuine multi-sheet .xlsx and exit. Each sheet is
 * ['name'=>string, 'headers'=>array, 'rows'=>array-of-arrays].
 * Falls back to a CSV of the first sheet when .xlsx can't be written here.
 */
/**
 * Write sheets to an .xlsx file on disk. Same sheet shape as the download
 * helpers (name / title / headers / rows). Returns false if it couldn't.
 * Split out so a file can be built without sending it to the browser —
 * that's how the per-course copies get filed in Drive.
 */
function vts_xlsx_write_file($path, array $sheets) {
    if (!vts_xlsx_writable()) return false;
    try {
        $ss = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $ss->removeSheetByIndex(0);
        $idx = 0;
        foreach ($sheets as $sheet) {
            $ws = new \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet($ss, mb_substr($sheet['name'] ?? ('Sheet' . ($idx + 1)), 0, 31));
            $ss->addSheet($ws, $idx);
            $r = 1;
            $headers = $sheet['headers'] ?? [];
            $lastCol = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(max(1, count($headers)));

            /* Optional caption above the table — e.g. the report name, the
               course/department it covers and the date range it was run for.
               Each entry is one merged, bold line. */
            foreach (($sheet['title'] ?? []) as $line) {
                $ws->setCellValue('A' . $r, $line);
                $ws->mergeCells("A{$r}:{$lastCol}{$r}");
                $ws->getStyle("A{$r}")->getFont()->setBold(true);
                if ($r === 1) $ws->getStyle("A{$r}")->getFont()->setSize(13);
                $r++;
            }
            if ($r > 1) $r++;                     // blank spacer row under the caption

            if ($headers) {
                $hRow = $r;
                $ws->fromArray($headers, null, 'A' . $hRow);
                $ws->getStyle("A{$hRow}:{$lastCol}{$hRow}")->getFont()->setBold(true);
                $ws->getStyle("A{$hRow}:{$lastCol}{$hRow}")->getFill()
                   ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
                   ->getStartColor()->setRGB('1A3A6B');
                $ws->getStyle("A{$hRow}:{$lastCol}{$hRow}")->getFont()->getColor()->setRGB('FFFFFF');
                foreach (range(1, count($headers)) as $c) {
                    $ws->getColumnDimensionByColumn($c)->setAutoSize(true);
                }
                $r = $hRow + 1;
            }
            foreach (($sheet['rows'] ?? []) as $row) {
                $ws->fromArray(array_values($row), null, 'A' . $r);
                $r++;
            }
            $idx++;
        }
        $ss->setActiveSheetIndex(0);
        (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($ss))->save($path);
        $ss->disconnectWorksheets();
        return true;
    } catch (Throwable $e) {
        error_log('VTS xlsx write failed: ' . $e->getMessage());
        return false;
    }
}

/**
 * $drive (optional) files a copy in Google Drive before the download starts:
 *   ['kind' => 'reports'|'violations'|'students'|'marshal', 'course' => 'BSIT']
 * The course becomes a sub-folder, so each course's files stay together.
 * Silently skipped when Drive isn't connected — the download always happens.
 */
function vts_xlsx_download_multi($filename, array $sheets, array $drive = null) {
    $sheets = array_values(array_filter($sheets, fn($s) => is_array($s)));
    if (!$sheets) $sheets = [['name' => 'Sheet1', 'headers' => [], 'rows' => []]];

    if (!vts_xlsx_writable()) {
        $first = $sheets[0];
        vts_csv_download($filename, $first['headers'] ?? [], $first['rows'] ?? []);
        return;
    }

    $filename = preg_replace('/\.(csv|xls)$/i', '', (string)$filename);
    if (!preg_match('/\.xlsx$/i', $filename)) $filename .= '.xlsx';

    // Build + write to a TEMP file first: if anything throws, we can still fall
    // back to CSV cleanly because no headers/body have been sent yet.
    $tmp = tempnam(sys_get_temp_dir(), 'vtsxlsx');
    if (!vts_xlsx_write_file($tmp, $sheets)) {
        @unlink($tmp);
        $first = $sheets[0];
        vts_csv_download($filename, $first['headers'] ?? [], $first['rows'] ?? []);
        return;
    }

    /* A Drive copy used to be filed here on every export. It needed a Google
       Workspace account the school does not have, so it failed every time --
       silently, because the upload was best-effort and the download carried
       on regardless. Removed rather than left failing on each export. */

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . filesize($tmp));
    header('Cache-Control: no-store');
    readfile($tmp);
    @unlink($tmp);
    exit();
}

/**
 * Build the two categorized sheets — "Major Violations" (Major + Grave) and
 * "Minor Violations" — as a per-student pivot that mirrors the OSA's BSCRIM
 * tracking sheet: one row per student, up to four offenses across the columns.
 * Returns [$majorSheet, $minorSheet] ready for vts_xlsx_download_multi().
 */
/* Shared report filters (course / set / violation type). Appends the SQL to a
   query that already has `violations v INNER JOIN users u` and a WHERE, and
   fills $params by reference. One place so the on-screen report, the Excel
   export and the printed list all filter identically. */
function vts_report_filter_sql(array $filters, array &$params) {
    $sql = '';
    $course     = trim((string)($filters['course']     ?? ''));
    $section    = trim((string)($filters['section']    ?? ''));
    $yearLevel  = trim((string)($filters['year_level'] ?? ''));
    $violation  = trim((string)($filters['violation']  ?? ''));
    $college    = trim((string)($filters['college_id'] ?? $filters['college'] ?? ''));   // department (CITE / CRIM / …)
    if ($course !== '')    { $sql .= " AND u.course = :f_course";      $params[':f_course']  = $course; }
    if ($section !== '')   { $sql .= " AND u.section = :f_section";    $params[':f_section'] = $section; }
    if ($yearLevel !== '') { $sql .= " AND u.year_level = :f_year";    $params[':f_year']    = $yearLevel; }
    if ($violation !== '') { $sql .= " AND v.violation = :f_viol";     $params[':f_viol']    = $violation; }
    if ($college !== '')   { $sql .= " AND u.college_id = :f_college"; $params[':f_college'] = (int)$college; }
    return $sql;
}

/* Caption lines printed above an exported sheet: what the sheet is, which
   course/department it covers and the date range it was run for. */
function vts_export_caption($what, array $filters = [], $from = '', $to = '') {
    $course  = trim((string)($filters['course']  ?? ''));
    $section = trim((string)($filters['section'] ?? ''));
    $dept    = trim((string)($filters['dept']    ?? ''));
    // Top of every exported sheet: the DEPARTMENT name first, then the date
    // range — that's what the office reads at a glance when filing.
    $lines = [];
    $lines[] = ($dept !== '' ? strtoupper($dept) : strtoupper($what));
    $lines[] = 'GOLDEN WEST COLLEGES — ' . $what;
    $who = [];
    $who[] = 'COURSE: ' . ($course !== '' ? $course : 'All courses');
    if ($section !== '') $who[] = 'SET: ' . $section;
    $lines[] = implode('     ', $who);
    $lines[] = 'DATE: ' . (($from !== '' || $to !== '')
        ? (($from !== '' ? vts_date($from) : 'Start') . ' to ' . ($to !== '' ? vts_date($to) : 'Today'))
        : 'All dates')
        . '     GENERATED: ' . vts_datetime(time());
    return $lines;
}

/* $filters accepts the same report filters the Reports page offers:
   ['course' => ..., 'section' => ..., 'violation' => ...]. Empty = no filter. */
function vts_build_categorized_sheets($conn, $from = '', $to = '', $student = '', array $filters = []) {
    $sql = "SELECT u.student_id, u.lastname, u.firstname, u.middlename,
                   u.year_level, u.section, u.course,
                   COALESCE(col.college_name, u.course, '') AS dept,
                   v.violation, v.severity, v.offense, v.date_reported
            FROM violations v
            INNER JOIN users u ON v.student_id = u.id
            LEFT JOIN colleges col ON col.id = u.college_id
            WHERE u.role = 'Student'";
    $params = [];
    if ($from !== '')    { $sql .= " AND DATE(v.date_reported) >= :from"; $params[':from'] = $from; }
    if ($to !== '')      { $sql .= " AND DATE(v.date_reported) <= :to";   $params[':to'] = $to; }
    if ($student !== '') { $sql .= " AND u.fullname LIKE :student";       $params[':student'] = "%{$student}%"; }
    $sql .= vts_report_filter_sql($filters, $params);
    $sql .= " ORDER BY u.lastname, u.firstname, v.date_reported ASC";

    $stmt = $conn->prepare($sql);
    $stmt->execute($params);

    // Split by category, then pivot each into the official one-row-per-student sheet.
    $split = ['Major' => [], 'Minor' => []];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $split[($r['severity'] === 'Minor') ? 'Minor' : 'Major'][] = $r;   // Grave rolls up with Major
    }

    $headers = vts_official_flat_headers();
    $caption = fn($what) => vts_export_caption($what, $filters, $from, $to);
    return [
        ['name' => 'Major Violations', 'headers' => $headers, 'title' => $caption('Major Violations'),
         'rows' => vts_official_row_cells(vts_official_group_students($split['Major']))],
        ['name' => 'Minor Violations', 'headers' => $headers, 'title' => $caption('Minor Violations'),
         'rows' => vts_official_row_cells(vts_official_group_students($split['Minor']))],
    ];
}

/* ONE SHEET PER DEPARTMENT (or per course), in the official-sheet layout and
   honouring the same date range + filters as the on-screen report. This is
   what replaced the Google Drive upload: export the workbook, then file it
   wherever it needs to go.
   $by = 'dept' | 'course'. */
function vts_build_split_sheets($conn, $by = 'dept', $from = '', $to = '', $student = '', array $filters = []) {
    $sql = "SELECT u.student_id, u.lastname, u.firstname, u.middlename,
                   u.year_level, u.section, u.course,
                   COALESCE(col.college_name, u.course, '') AS dept,
                   v.violation, v.severity, v.offense, v.date_reported
            FROM violations v
            INNER JOIN users u ON v.student_id = u.id
            LEFT JOIN colleges col ON col.id = u.college_id
            WHERE u.role = 'Student'";
    $params = [];
    if ($from !== '')    { $sql .= " AND DATE(v.date_reported) >= :from"; $params[':from'] = $from; }
    if ($to !== '')      { $sql .= " AND DATE(v.date_reported) <= :to";   $params[':to'] = $to; }
    if ($student !== '') { $sql .= " AND u.fullname LIKE :student";       $params[':student'] = "%{$student}%"; }
    $sql .= vts_report_filter_sql($filters, $params);
    $sql .= " ORDER BY u.lastname, u.firstname, v.date_reported ASC";

    $stmt = $conn->prepare($sql);
    $stmt->execute($params);

    $key = ($by === 'course') ? 'course' : 'dept';
    $buckets = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $k = trim((string)($r[$key] ?? '')) ?: 'Unassigned';
        $buckets[$k][] = $r;
    }
    ksort($buckets, SORT_NATURAL | SORT_FLAG_CASE);

    $headers = vts_official_flat_headers();
    $sheets  = [];
    foreach ($buckets as $name => $rows) {
        $sheets[] = [
            'name'    => vts_sheet_name($name),
            'headers' => $headers,
            'title'   => vts_export_caption($name, $filters, $from, $to),
            'rows'    => vts_official_row_cells(vts_official_group_students($rows)),
        ];
    }
    if (!$sheets) {
        $sheets[] = ['name' => 'No Records', 'headers' => $headers,
                     'title' => vts_export_caption('No records', $filters, $from, $to), 'rows' => []];
    }
    return $sheets;
}

/* Excel sheet names: max 31 chars and none of : \ / ? * [ ] */
function vts_sheet_name($name) {
    $n = preg_replace('/[:\\\\\/\?\*\[\]]/', '-', (string)$name);
    $n = trim($n) !== '' ? trim($n) : 'Sheet';
    return function_exists('mb_substr') ? mb_substr($n, 0, 31) : substr($n, 0, 31);
}

/* Shared BSCRIM-style header + per-student pivot row builder (used by the
   categorized and by-department exports). Given rows already ordered by
   student then date, returns [$headers, fn($studentsBucket) => rows]. */
function vts_bscrim_headers() {
    return ['NO','LAST NAME','FIRST NAME','M.I.','YEAR','SET','DEPT','WARNING',
            '1ST VIOLATION','1ST DATE','2ND VIOLATION','2ND DATE',
            '3RD VIOLATION','3RD DATE','4TH VIOLATION','4TH DATE','REMARKS'];
}
function vts_bscrim_rows(array $students) {
    $rows = []; $n = 0;
    foreach ($students as $s) {
        $n++; $info = $s['info'];
        $mi = !empty($info['middlename']) ? mb_strtoupper(mb_substr($info['middlename'], 0, 1)) . '.' : '';
        $cells = [
            $n, $info['lastname'] ?? '', $info['firstname'] ?? '', $mi,
            $info['year_level'] ?? '', $info['section'] ?? '', $info['course'] ?? '', '',
        ];
        for ($i = 0; $i < 4; $i++) {
            $it = $s['items'][$i] ?? null;
            $cells[] = $it ? $it['violation'] : '';
            $cells[] = $it ? $it['date'] : '';
        }
        $cells[] = count($s['items']) > 4 ? ('+' . (count($s['items']) - 4) . ' more offense(s)') : '';
        $rows[] = $cells;
    }
    return $rows;
}

/**
 * Build one sheet PER DEPARTMENT (course/program), each a BSCRIM-style
 * student-violation pivot. Optional date filter. Returns sheets ready for
 * vts_xlsx_download_multi().
 */
function vts_build_department_sheets($conn, $from = '', $to = '', $violationFilter = '') {
    $sql = "SELECT u.student_id, u.lastname, u.firstname, u.middlename,
                   u.year_level, u.section, u.course,
                   v.violation, v.severity, v.offense, v.date_reported
            FROM violations v INNER JOIN users u ON v.student_id = u.id
            WHERE u.role = 'Student'";
    $params = [];
    if ($from !== '')            { $sql .= " AND DATE(v.date_reported) >= :from"; $params[':from'] = $from; }
    if ($to !== '')              { $sql .= " AND DATE(v.date_reported) <= :to";   $params[':to'] = $to; }
    if ($violationFilter !== '') { $sql .= " AND v.violation = :vf";              $params[':vf'] = $violationFilter; }
    $sql .= " ORDER BY u.course, u.lastname, u.firstname, v.date_reported ASC";
    $stmt = $conn->prepare($sql);
    $stmt->execute($params);

    $byDept = [];   // course => [studentKey => bucket]
    while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $dept = $r['course'] ?: 'Unspecified';
        $key  = $r['student_id'] ?: ($r['lastname'] . '|' . $r['firstname']);
        if (!isset($byDept[$dept][$key])) $byDept[$dept][$key] = ['info' => $r, 'items' => []];
        $byDept[$dept][$key]['items'][] = [
            'violation' => $r['violation'], 'severity' => $r['severity'], 'date' => vts_date($r['date_reported']),
        ];
    }
    ksort($byDept);
    $headers = vts_bscrim_headers();
    $sheets = [];
    foreach ($byDept as $dept => $students) {
        // Sheet names: <=31 chars, no illegal chars.
        $name = preg_replace('/[\\\\\/\?\*\[\]:]/', ' ', (string)$dept);
        $sheets[] = ['name' => mb_substr($name, 0, 31), 'headers' => $headers, 'rows' => vts_bscrim_rows($students)];
    }
    if (!$sheets) $sheets[] = ['name' => 'No Data', 'headers' => $headers, 'rows' => []];
    return $sheets;
}

/* =====================================================================
   OFFICIAL VIOLATION SHEET — the OSA's own paper layout, one row per
   student:

     NO | LAST NAME | FIRST NAME | MIDDLE NAME | YEAR | SET | DEPT | WARNING
        | VIOLATION 1..4 | OFFENSE 1..4 | ATTACHED VIOLATION SLIP 1..3
        | REMARKS / ACTION 1..3

   WARNING, the slip columns and the remarks columns are intentionally
   blank — the system stores nothing for them, they are written in by hand
   on the printed sheet. Used by the Reports page, the Violations page and
   the exports so all three read identically.
   ===================================================================== */

/* Query rows (violations INNER JOIN users, ordered by student then date)
   -> one bucket per student: ['info' => firstRow, 'items' => [...]]. */
function vts_official_group_students(array $rows) {
    $students = [];
    foreach ($rows as $r) {
        $key = ($r['student_id'] ?? '') !== ''
            ? $r['student_id']
            : (($r['lastname'] ?? '') . '|' . ($r['firstname'] ?? ''));
        if (!isset($students[$key])) $students[$key] = ['info' => $r, 'items' => []];
        $students[$key]['items'][] = [
            'id'        => $r['id'] ?? null,
            'violation' => $r['violation'] ?? '',
            // Major/Minor lives in its OWN column, never appended to the
            // violation text, so the office can sort and total on it.
            'kind'      => (($r['severity'] ?? 'Minor') === 'Major') ? 'Major' : 'Minor',
            // The OFFENSE columns are narrow number columns on the printed
            // sheet — store just the digit, not "Violation #N", or the text
            // wraps one letter per line and the sheet becomes unreadable.
            'offense'   => (string)offense_number($r['offense'] ?? ''),
            'date'      => vts_date($r['date_reported'] ?? ''),
        ];
    }
    return $students;
}

/* One system_settings row, read once per request. Used for the school details
   and the Google Drive folders the exported files are filed in. */
function vts_setting($conn, $key, $default = '') {
    static $row = null;
    if ($row === null) {
        try {
            $row = $conn->query("SELECT * FROM system_settings LIMIT 1")->fetch(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) { $row = []; }
        // Older installs have no Drive-folder columns yet — add + seed them once
        // so the buttons work without having to open Settings first.
        if ($row && !array_key_exists('reports_drive_url', $row)) {
            vts_ensure_drive_settings($conn);
            try {
                $row = $conn->query("SELECT * FROM system_settings LIMIT 1")->fetch(PDO::FETCH_ASSOC) ?: $row;
            } catch (Throwable $e) { /* keep what we had */ }
        }
    }
    $v = $row[$key] ?? '';
    return ($v === null || $v === '') ? $default : $v;
}

/* Adds the Google Drive folder columns and fills in the folders the school
   gave us. Safe to call repeatedly; never overwrites an edited value. */
function vts_ensure_drive_settings($conn) {
    foreach (['reports_drive_url', 'violations_drive_url', 'students_drive_url', 'marshal_drive_url'] as $col) {
        try { $conn->exec("ALTER TABLE system_settings ADD COLUMN {$col} VARCHAR(500) NULL"); }
        catch (Throwable $e) { /* already there */ }
    }
    try {
        $conn->exec("UPDATE system_settings SET
            reports_drive_url    = COALESCE(NULLIF(reports_drive_url,''),    'https://drive.google.com/drive/folders/1-0hsBstQAv15YuqAnerYDUO3uAh9Pkdx'),
            violations_drive_url = COALESCE(NULLIF(violations_drive_url,''), 'https://drive.google.com/drive/folders/1G_wpi4dK-sxtjsUpE2KzGD6EnCBVJfCh'),
            students_drive_url   = COALESCE(NULLIF(students_drive_url,''),   'https://drive.google.com/drive/folders/1cN8_fnVxZeGYzmnXzSY3mObf33glHkuf'),
            marshal_drive_url    = COALESCE(NULLIF(marshal_drive_url,''),    'https://drive.google.com/drive/folders/1NHC3CLmWvunJ0M1dsEjcrOud20djGIHG')
            WHERE id = 1");
    } catch (Throwable $e) { /* non-fatal */ }
}

/* "Open the Drive folder" button — only rendered when that folder is set. */
function vts_drive_button($conn, $key, $label = 'Drive folder') {
    $url = vts_setting($conn, $key);
    if ($url === '') return '';
    return '<a href="' . htmlspecialchars($url) . '" target="_blank" rel="noopener" class="btn-outline"'
         . ' title="Opens the Google Drive folder — drop the downloaded file in there">'
         . '<i class="fas fa-folder-open"></i> ' . htmlspecialchars($label) . '</a>';
}

/* ---- Cold→hot scale: 1st offence green, climbing to red for repeat ones.
   Used for offence numbers, violation counts and anything else that gets
   worse the higher it goes. Returns the CSS class only. ---- */
function vts_heat_class($n) {
    $n = (int)$n;
    if ($n <= 1) return 'heat-1';
    if ($n === 2) return 'heat-2';
    if ($n === 3) return 'heat-3';
    if ($n === 4) return 'heat-4';
    return 'heat-5';
}

/* A coloured pill for an offence/violation count. $label defaults to the
   "Violation #N" wording used across the app. */
function vts_heat_pill($n, $label = null) {
    $n = (int)$n;
    if ($n <= 0) return '';
    $label = $label ?? ('Violation #' . $n);
    return '<span class="heat ' . vts_heat_class($n) . '">' . htmlspecialchars($label) . '</span>';
}

/* Two-row grouped header: [label, colspan] — colspan 1 = a rowspan-2 column. */
function vts_official_header_groups() {
    return [
        ['NO', 1], ['LAST NAME', 1], ['FIRST NAME', 1], ['MIDDLE NAME', 1],
        ['YEAR', 1], ['SET', 1], ['DEPT', 1], ['WARNING', 1],
        ['VIOLATION', 4], ['KIND', 4], ['OFFENSE', 4],
        ['ATTACHED VIOLATION SLIP', 3], ['REMARKS / ACTION', 3],
    ];
}

/* Flat one-line version of the same columns (CSV / plain sheets). */
function vts_official_flat_headers() {
    $h = ['NO','LAST NAME','FIRST NAME','MIDDLE NAME','YEAR','SET','DEPT','WARNING'];
    for ($i = 1; $i <= 4; $i++) $h[] = "VIOLATION $i";
    for ($i = 1; $i <= 4; $i++) $h[] = "KIND $i";
    for ($i = 1; $i <= 4; $i++) $h[] = "OFFENSE $i";
    for ($i = 1; $i <= 3; $i++) $h[] = "ATTACHED VIOLATION SLIP $i";
    for ($i = 1; $i <= 3; $i++) $h[] = "REMARKS / ACTION $i";
    return $h;
}

/* Student buckets -> flat cell rows in the order above. */
function vts_official_row_cells(array $students) {
    $rows = []; $n = 0;
    foreach ($students as $s) {
        $n++; $i = $s['info'];
        $cells = [
            $n,
            $i['lastname']   ?? '',
            $i['firstname']  ?? '',
            $i['middlename'] ?? '',
            $i['year_level'] ?? '',
            $i['section']    ?? '',
            $i['dept'] ?? $i['course'] ?? '',
            '',                                   // WARNING — filled in by hand
        ];
        // Order MUST match vts_official_header_groups()/flat_headers():
        // VIOLATION 1-4, KIND 1-4, OFFENSE 1-4, SLIP 1-3, REMARKS 1-3.
        for ($k = 0; $k < 4; $k++) $cells[] = $s['items'][$k]['violation'] ?? '';
        for ($k = 0; $k < 4; $k++) $cells[] = $s['items'][$k]['kind']      ?? '';
        for ($k = 0; $k < 4; $k++) $cells[] = $s['items'][$k]['offense']   ?? '';
        for ($k = 0; $k < 3; $k++) $cells[] = '';   // ATTACHED VIOLATION SLIP 1..3
        for ($k = 0; $k < 3; $k++) $cells[] = '';   // REMARKS / ACTION 1..3
        $rows[] = $cells;
    }
    return $rows;
}

/* Renders the sheet as an HTML table with the merged two-row header.
   $emptyHtml replaces the bare "no records" line when the caller can say
   WHICH filters emptied the sheet (see vts_empty_filter_html). */
function vts_official_table_html(array $students, string $emptyHtml = '') {
    $groups = vts_official_header_groups();
    // Percent widths (sum 100) so all 22 columns fit the page without a
    // sideways scroll — the identity columns get the room, the write-in
    // columns stay narrow.
    // Columns that carry TEXT get the room; the six always-blank write-in
    // columns (SLIP 1-3, REMARKS 1-3) are squeezed to a hand-writable minimum.
    // Before this, the blank columns were as wide as the names and the text
    // columns had to shrink their font to fit — unreadable.
    $widths = array_merge(
        [2.0, 6.6, 6.6, 3.2, 3.4, 2.4, 8.6, 3.0],   // NO … WARNING
        array_fill(0, 4, 6.6),                       // VIOLATION 1..4 (longest text)
        array_fill(0, 4, 3.4),                       // KIND 1..4 (Major/Minor)
        array_fill(0, 4, 2.0),                       // OFFENSE 1..4 (a single digit)
        array_fill(0, 3, 2.0),                       // SLIP 1..3 (blank, hand-filled)
        array_fill(0, 3, 2.1)                        // REMARKS 1..3 (blank, hand-filled)
    );
    $out  = '<table class="data-table official-sheet">';
    $out .= '<colgroup>';
    foreach ($widths as $w) $out .= '<col style="width:' . $w . '%">';
    $out .= '</colgroup>';
    $out .= '<thead><tr>';
    foreach ($groups as [$label, $span]) {
        $out .= $span === 1
            ? '<th rowspan="2">' . htmlspecialchars($label) . '</th>'
            : '<th colspan="' . $span . '" class="grp">' . htmlspecialchars($label) . '</th>';
    }
    $out .= '</tr><tr>';
    foreach ($groups as [$label, $span]) {
        if ($span === 1) continue;
        for ($i = 1; $i <= $span; $i++) $out .= '<th class="sub">' . $i . '</th>';
    }
    $out .= '</tr></thead><tbody>';

    $rows = vts_official_row_cells($students);
    $studentList = array_values($students);   // parallel, same order — for pulling violation ids (SLIP links) without touching what CSV/Excel export reads
    if (!$rows) {
        $cols = 8 + 4 + 4 + 4 + 3 + 3;      // + KIND 1..4
        $out .= '<tr><td colspan="' . $cols . '" class="table-empty">'
              . ($emptyHtml !== '' ? $emptyHtml : 'No records match these filters.')
              . '</td></tr>';
    }
    // Column map (0-based): 0-7 identity, 8-11 VIOLATION, 12-15 KIND,
    // 16-19 OFFENSE, 20-22 SLIP, 23-25 REMARKS.
    foreach ($rows as $rowIdx => $cells) {
        $rowItems = $studentList[$rowIdx]['items'] ?? [];
        $out .= '<tr>';
        foreach ($cells as $i => $c) {
            $cell = htmlspecialchars((string)$c);
            if ($i === 6 && $c !== '') {
                // DEPT: drop the "Department of " prefix so the name fits on
                // one or two lines instead of stacking one word per line.
                $short = preg_replace('/^\s*(Department|Dept\.?)\s+of\s+/i', '', (string)$c);
                $out .= '<td class="deptcell" title="' . htmlspecialchars((string)$c) . '">'
                      . htmlspecialchars($short) . '</td>';
            } elseif ($i >= 12 && $i <= 15 && $c !== '') {
                // KIND — Major/Minor chip in its own column.
                $isMajor = strcasecmp(trim((string)$c), 'Major') === 0;
                $out .= '<td class="kindcell"><span class="sev-chip '
                      . ($isMajor ? 'sev-major' : 'sev-minor') . '">'
                      . htmlspecialchars(strtoupper((string)$c)) . '</span></td>';
            } elseif ($i >= 16 && $i <= 19 && $c !== '') {
                $n = (int)preg_replace('/\D/', '', (string)$c);
                $out .= '<td class="' . vts_heat_class($n ?: ($i - 15)) . '">' . $cell . '</td>';
            } elseif ($i >= 20 && $i <= 22) {
                // SLIP 1..3 — an on-screen "open the slip" link for the
                // matching violation (item k = i-20), so staff can
                // print/attach it the moment a student asks for it in
                // person. This reads from $rowItems (never from $cells,
                // which is exactly what the CSV/Excel export reads too) so
                // the exported sheet's SLIP column stays blank as before —
                // only this HTML view gets the link.
                $vid = $rowItems[$i - 20]['id'] ?? null;
                $out .= '<td class="slipcell">';
                if ($vid) {
                    $out .= '<a href="../reports/student_violation_slip.php?id=' . (int)$vid
                          . '" target="_blank" class="no-print slip-link" title="Open this violation\'s slip to print/attach">'
                          . '<i class="fas fa-file-signature"></i></a>';
                }
                $out .= '</td>';
            } else {
                $out .= '<td>' . $cell . '</td>';
            }
        }
        $out .= '</tr>';
    }
    return $out . '</tbody></table>';
}

/* =====================================================================
   EXCEL BACKUP — one workbook, one sheet per kind of record so each set of
   data stays separate: Students · Violations · Enrolled List · User
   Accounts. Optional date range (applies to violations by the date they
   were recorded, and to accounts by the date they were created).
   ===================================================================== */
function vts_build_backup_sheets($conn, $from = '', $to = '') {
    $dateWhere = function ($col, &$params) use ($from, $to) {
        $sql = '';
        if ($from !== '') { $sql .= " AND DATE($col) >= :bkfrom"; $params[':bkfrom'] = $from; }
        if ($to   !== '') { $sql .= " AND DATE($col) <= :bkto";   $params[':bkto']   = $to; }
        return $sql;
    };
    $fetch = function ($sql, $params) use ($conn) {
        $st = $conn->prepare($sql);
        $st->execute($params);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    };
    $sheets = [];

    // ---- Students (registered accounts) ----
    $p = [];
    $sql = "SELECT u.student_id, u.lastname, u.firstname, u.middlename, u.suffix,
                   u.email, u.contact_number, u.gender, u.course, u.year_level, u.section,
                   COALESCE(c.college_name,'') AS dept, u.status, u.created_at
            FROM users u LEFT JOIN colleges c ON c.id = u.college_id
            WHERE u.role = 'Student'" . $dateWhere('u.created_at', $p) . "
            ORDER BY u.lastname, u.firstname";
    $sheets[] = ['name' => 'Students', 'title' => vts_export_caption('Students', [], $from, $to),
        'headers' => ['SCHOOL ID','LAST NAME','FIRST NAME','MIDDLE NAME','SUFFIX','EMAIL','CONTACT',
                      'GENDER','COURSE','YEAR','SET','DEPT','STATUS','REGISTERED'],
        'rows' => array_map(fn($r) => array_values($r), $fetch($sql, $p))];

    // ---- Violations ----
    $p = [];
    $sql = "SELECT u.student_id, u.fullname, u.course, u.year_level, u.section,
                   v.violation, v.offense, v.status, v.description,
                   COALESCE(r.fullname, v.scanner_name, '') AS recorded_by, v.date_reported
            FROM violations v
            INNER JOIN users u ON v.student_id = u.id
            LEFT JOIN users r ON v.reported_by = r.id
            WHERE 1=1" . $dateWhere('v.date_reported', $p) . "
            ORDER BY v.date_reported DESC";
    $rows = [];
    foreach ($fetch($sql, $p) as $r) {
        $rows[] = [$r['student_id'], $r['fullname'], $r['course'], $r['year_level'], $r['section'],
                   $r['violation'], offense_display($r['offense'] ?? ''), $r['status'],
                   $r['description'], $r['recorded_by'], $r['date_reported']];
    }
    $sheets[] = ['name' => 'Violations', 'title' => vts_export_caption('Violations', [], $from, $to),
        'headers' => ['SCHOOL ID','STUDENT NAME','COURSE','YEAR','SET','VIOLATION','OFFENSE',
                      'STATUS','DESCRIPTION','RECORDED BY','DATE RECORDED'],
        'rows' => $rows];

    // ---- Enrolled list (roster) ----
    try {
        $p = [];
        $sql = "SELECT school_id, lastname, firstname, course, year_level, is_used, created_at
                FROM student_roster WHERE 1=1" . $dateWhere('created_at', $p) . "
                ORDER BY lastname, firstname";
        $rows = [];
        foreach ($fetch($sql, $p) as $r) {
            $rows[] = [$r['school_id'], $r['lastname'], $r['firstname'], $r['course'],
                       $r['year_level'], $r['is_used'] ? 'Registered' : 'Not yet registered', $r['created_at']];
        }
        $sheets[] = ['name' => 'Enrolled List', 'title' => vts_export_caption('Enrolled List', [], $from, $to),
            'headers' => ['SCHOOL ID','LAST NAME','FIRST NAME','COURSE','YEAR','ACCOUNT','ADDED'],
            'rows' => $rows];
    } catch (Throwable $e) { /* roster table may not exist yet */ }

    // ---- User accounts (staff) — never any passwords ----
    $p = [];
    $sql = "SELECT u.fullname, u.username, u.email, u.role, u.contact_number,
                   u.status, u.created_at
            FROM users u WHERE u.role <> 'Student'" . $dateWhere('u.created_at', $p) . "
            ORDER BY u.role, u.fullname";
    $sheets[] = ['name' => 'User Accounts', 'title' => vts_export_caption('User Accounts', [], $from, $to),
        'headers' => ['FULL NAME','USERNAME','EMAIL','ROLE','CONTACT','STATUS','CREATED'],
        'rows' => array_map(fn($r) => array_values($r), $fetch($sql, $p))];

    return $sheets;
}

/**
 * Read an uploaded .xlsx file's first sheet into an array-of-arrays (same shape
 * str_getcsv rows already take). Returns null if unreadable or PhpSpreadsheet
 * isn't installed.
 */
function vts_xlsx_read_rows($tmpPath) {
    if (!class_exists('PhpOffice\PhpSpreadsheet\IOFactory')) return null;
    // Best-effort only — many free hosts (InfinityFree included) block raising
    // these via ini_set, in which case these calls just silently no-op.
    @ini_set('memory_limit', '256M');
    @set_time_limit(60);
    try {
        $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReaderForFile($tmpPath);
        $reader->setReadDataOnly(true);   // skip styles/formatting — the big memory cost
        $ss = $reader->load($tmpPath);
        $rows = $ss->getActiveSheet()->toArray(null, true, true, false);
        $ss->disconnectWorksheets();
        unset($ss);
        return $rows;
    } catch (Throwable $e) {
        error_log('vts_xlsx_read_rows failed: ' . $e->getMessage());
        return null;
    }
}

/* =====================================================================
   SECURE SCANNER FILE  (.vtsl)
   The students-list file the offline scanner imports is ENCRYPTED so a
   Marshall (or anyone) can't open it in Excel and read student data or the
   staff password hashes. It carries students + the Guard/OSA/Admin accounts
   (bcrypt hashes) the scanner needs to verify logins offline.

   Format: a small JSON envelope {v,kdf,iter,salt,iv,ct} where `ct` is
   base64( AES-256-GCM ciphertext || 16-byte tag ). The AES key is derived
   (PBKDF2-SHA256) from an app secret shared with the scanner's JS. This is
   an "app key" scheme (see VTS_SCANNER_FILE_SECRET): strong against casual
   access, not against someone who reverse-engineers the app. The scanner
   ALSO still requires an Admin/OSA login (verified against the accounts
   inside the file) before it will import.
   MUST stay in sync with VTS_FILE_SECRET / vtsDecryptEnvelope() in
   spck_scanner.html.
   ===================================================================== */
if (!defined('VTS_SCANNER_FILE_SECRET')) {
    define('VTS_SCANNER_FILE_SECRET', 'GWC-VTS::students-list::v1::7Qm2Zx9Rk4Lp8Bn1Hs5Dy0Aa3Ce6Wu-tF');
}
if (!defined('VTS_SCANNER_KDF_ITER')) {
    define('VTS_SCANNER_KDF_ITER', 120000);
}

/** Encrypt a UTF-8 string into the .vtsl JSON envelope (or null if crypto unavailable). */
function vts_scanner_encrypt(string $plaintext): ?string {
    if (!function_exists('openssl_encrypt')) return null;
    $salt = random_bytes(16);
    $iv   = random_bytes(12);
    $key  = hash_pbkdf2('sha256', VTS_SCANNER_FILE_SECRET, $salt, VTS_SCANNER_KDF_ITER, 32, true);
    $tag  = '';
    $ct   = openssl_encrypt($plaintext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, '', 16);
    if ($ct === false) return null;
    return json_encode([
        'app'  => 'VTS-SCANNER', 'fmt' => 'vtsl', 'v' => 1,
        'kdf'  => 'PBKDF2-SHA256', 'iter' => VTS_SCANNER_KDF_ITER,
        'salt' => base64_encode($salt),
        'iv'   => base64_encode($iv),
        'ct'   => base64_encode($ct . $tag),
    ], JSON_UNESCAPED_SLASHES);
}

/** Decrypt a .vtsl JSON envelope back to its UTF-8 string (or null on failure).
 *  Mirror of vts_scanner_encrypt() — lets the admin import the ENCRYPTED
 *  violations file the scanner exports at end of shift. */
function vts_scanner_decrypt(string $envelopeJson): ?string {
    if (!function_exists('openssl_decrypt')) return null;
    $env = json_decode($envelopeJson, true);
    if (!is_array($env) || !isset($env['salt'], $env['iv'], $env['ct'])) return null;

    $salt = base64_decode((string)$env['salt'], true);
    $iv   = base64_decode((string)$env['iv'], true);
    $blob = base64_decode((string)$env['ct'], true);
    if ($salt === false || $iv === false || $blob === false || strlen($blob) < 17) return null;

    // ct = ciphertext || 16-byte GCM tag  (matches vts_scanner_encrypt).
    $tag = substr($blob, -16);
    $ct  = substr($blob, 0, -16);
    $iter = (int)($env['iter'] ?? VTS_SCANNER_KDF_ITER) ?: VTS_SCANNER_KDF_ITER;
    $key  = hash_pbkdf2('sha256', VTS_SCANNER_FILE_SECRET, $salt, $iter, 32, true);

    $plain = openssl_decrypt($ct, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
    return $plain === false ? null : $plain;
}

/** True if a blob looks like our .vtsl envelope (encrypted JSON). */
function vts_is_vtsl_envelope(string $raw): bool {
    $raw = ltrim($raw);
    if ($raw === '' || $raw[0] !== '{') return false;
    return strpos($raw, '"ct"') !== false
        && strpos($raw, '"iv"') !== false
        && strpos($raw, '"salt"') !== false;
}

/**
 * Build + stream the encrypted students-list (.vtsl) the scanner imports, then exit.
 * Contains active students + the Guard/OSA/Admin accounts (bcrypt hashes) so the
 * scanner can verify logins and unlock offline. Call after the page's own role check.
 */
function vts_export_scanner_file(PDO $conn, string $exportedBy = ''): void {
    $students = [];
    $guards = [];
    $accounts = [];
    try {
        $students = $conn->query(
            "SELECT student_id, fullname, section, year_level, course, scanner_access
             FROM users
             WHERE role='Student' AND status='Active' AND student_id IS NOT NULL
             ORDER BY fullname ASC")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {}
    try {
        $guards = $conn->query(
            "SELECT student_id, fullname FROM users
             WHERE role='Guard' AND status='Active' AND student_id IS NOT NULL
             ORDER BY fullname ASC")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {}
    try {
        $accounts = $conn->query(
            "SELECT username, fullname, role, password
             FROM users
             WHERE role IN ('Guard','OSA Staff','OSA','Admin') AND status='Active' AND username IS NOT NULL
             ORDER BY role, fullname ASC")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {}

    $payload = json_encode([
        'app'         => 'VTS-SCANNER',
        'type'        => 'students-list',
        'exported_at' => date('c'),
        'exported_by' => $exportedBy,
        'students'    => $students,
        'guards'      => $guards,
        'accounts'    => $accounts,
    ], JSON_UNESCAPED_UNICODE);

    $envelope = vts_scanner_encrypt($payload);
    $fname    = 'Imported_Student_List_' . date('Y-m-d_H-i') . '.vtsl';

    if ($envelope === null) {
        // Crypto unavailable on this server — fail loudly rather than leak plaintext.
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
        echo "Secure export unavailable: PHP OpenSSL is required to encrypt the students-list file.";
        exit();
    }

    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . $fname . '"');
    header('Content-Length: ' . strlen($envelope));
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    echo $envelope;
    exit();
}

function totalStudents($conn) {
    return (int)$conn->query("SELECT COUNT(*) FROM users WHERE role='Student'")->fetchColumn();
}

function totalUsers($conn) {
    return (int)$conn->query("SELECT COUNT(*) FROM users")->fetchColumn();
}

function totalViolations($conn) {
    return (int)$conn->query("SELECT COUNT(*) FROM violations")->fetchColumn();
}

function pendingCases($conn) {
    return (int)$conn->query("SELECT COUNT(*) FROM violations WHERE DATE(date_reported)=CURDATE()")->fetchColumn();
}

function approvedCases($conn) {
    return (int)$conn->query("SELECT COUNT(*) FROM violations WHERE status IN ('Approved','Resolved')")->fetchColumn();
}

function archivedCases($conn) {
    return (int)$conn->query("SELECT COUNT(*) FROM violations WHERE status='Resolved'")->fetchColumn();
}

function todayViolations($conn) {
    return (int)$conn->query("SELECT COUNT(*) FROM violations WHERE DATE(date_reported)=CURDATE()")->fetchColumn();
}

/* At-risk students: 3 or more recorded violations */
function atRiskStudents($conn) {
    return (int)$conn->query("SELECT COUNT(*) FROM (SELECT student_id FROM violations GROUP BY student_id HAVING COUNT(*) >= 3) t")->fetchColumn();
}

/* ---------------- CSRF protection ---------------- */
/* Send a signed-in user who hit a page their role isn't allowed to use back
   to THEIR OWN dashboard with a visible reason. "../student_search.php"
   immediately re-redirects anyone already logged in to their own dashboard
   (see the top of that file) and drops the ?error= message in the process
   -- so the old pattern here just silently bounced people with zero
   explanation. Routing to the user's own dashboard (which DOES render
   ?error= as a toast, via includes/footer.php) fixes that. */
function vts_deny_access($msg = "You don't have permission to access that page.") {
    $dest = [
        'Admin'     => '../admin/dashboard.php',
        'OSA'       => '../admin/dashboard.php',
        'OSA Staff' => '../osa_staff/dashboard.php',
        'Guard'     => '../spck_scanner.html',
        'Student'   => '../student/dashboard.php',
    ][$_SESSION['role'] ?? ''] ?? '../student_search.php';
    header("Location: " . $dest . "?error=" . urlencode($msg));
    exit();
}

function csrf_token() {
    if (session_status() === PHP_SESSION_NONE) session_start();
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}
function csrf_field() {
    return '<input type="hidden" name="csrf_token" value="' . csrf_token() . '">';
}
function csrf_verify() {
    $sent = $_POST['csrf_token'] ?? '';
    return is_string($sent) && $sent !== '' && hash_equals($_SESSION['csrf_token'] ?? '', $sent);
}

/* ---------------- UI helpers ---------------- */
function status_badge($status) {
    $map = ['Recorded' => 'badge-blue'];
    $cls = $map[$status] ?? 'badge-gray';
    return '<span class="badge ' . $cls . '">' . htmlspecialchars($status) . '</span>';
}
/* Safe filename for uploads: <time>_<random>.<ext> */
function safe_upload_name($original) {
    $ext = strtolower(pathinfo($original, PATHINFO_EXTENSION));
    return time() . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
}

/* =====================================================================
   PROFILE PICTURE UPLOADS, CHECKED IN ONE PLACE.

   account.php and student/profile.php each carried their own copy of the
   same forty lines of upload checking. Two copies of a security check is
   one check and one liability: a rule tightened in one file is a rule still
   missing from the other, and nothing tells you which is which. Both call
   this now.

   WHAT IT WILL ACCEPT, and why each rule is here:

   - A real JPEG or PNG. The type is read from the FILE, with getimagesize(),
     not from the name the browser sent and not from the Content-Type header
     — both of those are typed by whoever is uploading. mime_content_type()
     is only a fallback because it is disabled on some hosts, which once made
     every upload fail.
   - Saved under a name this function invents — <time>_<random>.jpg|png. The
     original name is never used for anything. That is what stops "..\..\x.php"
     and "shell.php.jpg" from mattering: the extension is not taken from the
     upload, it is decided by the image type that was actually found.
   - Within the size cap, AND within a sane pixel count. A 40-megapixel PNG
     can be a few hundred KB on disk and still exhaust memory the moment
     anything decodes it, so the byte cap alone does not cover it.
   - Something PHP itself received as an upload. is_uploaded_file() is the
     check that makes the tmp_name trustworthy; without it, a $_FILES array
     that ever came from somewhere other than a real upload could name any
     file on the server and have it copied into a web-readable folder.

   Note the belt-and-braces: uploads/.htaccess already refuses to execute
   anything in that folder. This is the other half of the same protection,
   so neither one being misconfigured is on its own enough.

   Returns ['ok' => bool, 'name' => saved filename or null, 'error' => message].
   ===================================================================== */
function vts_accept_image_upload(array $f, string $destDir, int $maxBytes = 3145728): array {
    $fail = fn($msg) => ['ok' => false, 'name' => null, 'error' => $msg];

    if (($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return $fail("The picture upload failed — please try a different file.");
    }
    if (empty($f['tmp_name']) || !is_uploaded_file($f['tmp_name'])) {
        return $fail("That file could not be read — please choose the picture again.");
    }
    if (($f['size'] ?? 0) > $maxBytes) {
        return $fail("Profile picture must be " . (int)round($maxBytes / 1048576) . "MB or smaller.");
    }

    $forceExt = null;
    $info = @getimagesize($f['tmp_name']);
    if ($info !== false) {
        if     ($info[2] === IMAGETYPE_JPEG) $forceExt = 'jpg';
        elseif ($info[2] === IMAGETYPE_PNG)  $forceExt = 'png';

        // Decompression guard: 50MP is far past any profile photo, and well
        // under what a crafted PNG can claim.
        if ($forceExt !== null && ((int)$info[0] * (int)$info[1]) > 50000000) {
            return $fail("That image is too large in dimensions — please use a smaller photo.");
        }
    } elseif (function_exists('mime_content_type')) {
        $mime = @mime_content_type($f['tmp_name']);
        if     ($mime === 'image/jpeg') $forceExt = 'jpg';
        elseif ($mime === 'image/png')  $forceExt = 'png';
    }
    if ($forceExt === null) {
        return $fail("Profile picture must be a JPG or PNG image.");
    }

    if (!is_dir($destDir)) { @mkdir($destDir, 0777, true); }

    $name = time() . '_' . bin2hex(random_bytes(6)) . '.' . $forceExt;
    if (!@move_uploaded_file($f['tmp_name'], rtrim($destDir, '/\\') . DIRECTORY_SEPARATOR . $name)) {
        return $fail("Could not save the picture — please check that the uploads/profile folder exists and is writable.");
    }
    return ['ok' => true, 'name' => $name, 'error' => ''];
}


/* ---------------- Input formatting & policy ---------------- */
/* "JUAN DELA CRUZ" / "juan dela cruz" -> "Juan Dela Cruz".
   Name suffixes are preserved correctly: "juan cruz iii" -> "Juan Cruz III",
   "jr" / "jr." -> "Jr." (plain title-casing used to mangle these). */
/* First given name for a friendly greeting — skips a leading honorific
   (Mr./Mrs./Ms./Dr./Engr./Atty./Prof.) so "Mrs. Alma Viray" greets "Alma",
   not "Mrs.". Falls back to $fallback when nothing usable is present. */
function vts_first_name($fullname, $fallback = 'there') {
    $parts = preg_split('/\s+/', trim((string)$fullname));
    $parts = array_values(array_filter($parts, fn($p) => $p !== ''));
    if (!$parts) return $fallback;
    $honorifics = ['mr','mrs','ms','mr.','mrs.','ms.','dr','dr.','engr','engr.','atty','atty.','prof','prof.','sir','maam','ma\'am'];
    $first = $parts[0];
    if (in_array(mb_strtolower($first), $honorifics, true) && count($parts) > 1) {
        $first = $parts[1];
    }
    return $first !== '' ? $first : $fallback;
}

function name_case($name) {
    $name = trim(preg_replace('/\s+/', ' ', (string)$name));
    if ($name === '') return '';

    /* Tidy the CASING the user did not choose, never the casing they did.
       mb_convert_case(MB_CASE_TITLE) used to run over the whole string, and it
       only treats a SPACE as the start of a word — so every letter after a
       period, apostrophe or hyphen was forced lowercase and "Mr.Denzel" came
       back as "Mr.denzel" no matter how many times it was retyped. Same for
       O'Brien, McDonald and Anne-Marie.

       So: a word typed in ONE case ("jose", "JOSE") is sloppy input and gets
       title-cased; a word that already carries inner capitals was typed that
       way ON PURPOSE and is left exactly as it is. */
    $words = explode(' ', $name);
    foreach ($words as $i => $w) {
        if ($w === '') continue;
        $lower = mb_strtolower($w, 'UTF-8');
        $upper = mb_strtoupper($w, 'UTF-8');
        if ($w !== $lower && $w !== $upper) continue;   // deliberate — hands off

        // Capitalise the first letter of each PART of the word, so the letter
        // after a period / apostrophe / hyphen is a start too.
        $words[$i] = preg_replace_callback('/(^|[.\'-])(\p{L})/u',
            fn($m) => $m[1] . mb_strtoupper($m[2], 'UTF-8'), $lower);
    }
    $name = implode(' ', $words);

    // Roman-numeral suffixes back to uppercase (II, III, IV, V ...)
    $name = preg_replace_callback('/\b(Ii|Iii|Iv|V|Vi)\b\.?$/u',
        fn($m) => strtoupper($m[1]), $name);
    // Jr / Sr always written with the trailing period
    $name = preg_replace('/\b(Jr|Sr)\.?$/u', '$1.', $name);
    return $name;
}
/* Strong password: 8+ chars with upper/lowercase letters, a number, and a symbol.
   Returns an error string, or '' if the password is acceptable. */
function password_policy_error($pw) {
    if (mb_strlen($pw) < 8)          return "Password must be at least 8 characters.";
    if (!preg_match('/[A-Z]/', $pw)) return "Password must contain at least one capital letter.";
    if (!preg_match('/[a-z]/', $pw)) return "Password must contain at least one small letter.";
    if (!preg_match('/[0-9]/', $pw)) return "Password must contain at least one number.";
    if (!preg_match('/[^A-Za-z0-9]/', $pw)) return "Password must contain at least one special character.";
    return '';
}

/* Shared validation helpers used across profile, registration, and CRUD forms.
   These keep the app consistent and avoid one-off checks that fail on older schemas. */
function vts_validate_full_name($value) {
    $value = trim((string)$value);
    if ($value === '' || mb_strlen($value) < 4) {
        return 'Full Name looks too short — please enter your complete name.';
    }
    if (!preg_match("/^[\p{L} .'-]+$/u", $value)) {
        return 'Full Name may only contain letters, spaces, periods, and hyphens.';
    }
    return '';
}

function vts_validate_email_address($value) {
    $value = strtolower(trim((string)$value));
    if (!filter_var($value, FILTER_VALIDATE_EMAIL)) {
        return 'Please enter a valid email address.';
    }
    return '';
}

function vts_validate_phone_number($value) {
    $value = trim((string)$value);
    if ($value !== '' && !preg_match('/^[0-9+\-() ]{7,20}$/', $value)) {
        return 'Contact number format looks wrong (digits only, 7–20 characters).';
    }
    return '';
}

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
                // Best effort only: if an older database is locked down, the app keeps
                // working but the actual backend error is logged elsewhere.
            }
        }
    }
}

/* Birthday sanity check for student registration: must be a real past date,
   and the age it implies must be plausible for an enrolled student (not a
   newborn, not a 100-year-old). Returns an error string, or '' if OK. */
function birthday_age_error($birthday) {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$birthday)) return "Enter a valid birthdate.";
    try { $bd = new DateTime($birthday); } catch (Throwable $e) { return "Enter a valid birthdate."; }
    $today = new DateTime('today');
    if ($bd > $today) return "Birthday cannot be today or in the future.";
    $age = $today->diff($bd)->y;
    if ($age < 10)  return "That birthdate makes you younger than 10 years old — please double-check it.";
    if ($age > 60)  return "That birthdate makes you older than 60 years old — please double-check it.";
    return '';
}

/* Section must be exactly one uppercase letter, A through J (no numbers,
   no lowercase, no multi-word "Set A"-style free text). */
function section_format_error($section) {
    if (!preg_match('/^[A-J]$/', (string)$section)) {
        return "Section must be a single letter from A to J.";
    }
    return '';
}

/* ---------------- Compact numbers & heat-scale colors ---------------- */
/* 1234 -> "1.2K", 2500000 -> "2.5M" — keeps big KPI counters from
   overflowing their cards once real enrollment numbers pile up. */
function vts_compact_number($n) {
    $n = (float)$n;
    $neg = $n < 0; $n = abs($n);
    if ($n >= 1000000000)      $s = rtrim(rtrim(number_format($n / 1000000000, 1), '0'), '.') . 'B';
    elseif ($n >= 1000000)     $s = rtrim(rtrim(number_format($n / 1000000, 1), '0'), '.') . 'M';
    elseif ($n >= 1000)        $s = rtrim(rtrim(number_format($n / 1000, 1), '0'), '.') . 'K';
    else                       $s = number_format($n);
    return ($neg ? '-' : '') . $s;
}

/* Hot (highest / rank 0) -> cold (lowest) color scale: violet-red -> red ->
   orange -> yellow -> green. $t is 0..1 (0 = hottest). Used to color-code
   ranked violation lists instead of the old manual "severity" field. */
function vts_heat_color($t) {
    $t = max(0.0, min(1.0, (float)$t));
    $stops = [
        [0x8e, 0x0e, 0x6b], // violet-red — highest
        [0xe0, 0x35, 0x35], // red
        [0xe8, 0x86, 0x2d], // orange
        [0xe8, 0xc9, 0x2d], // yellow
        [0x1b, 0x7f, 0x46], // green — lowest
    ];
    $segments = count($stops) - 1;
    $pos = $t * $segments;
    $i = (int)min(floor($pos), $segments - 1);
    $frac = $pos - $i;
    $c1 = $stops[$i]; $c2 = $stops[$i + 1];
    $r = (int)round($c1[0] + ($c2[0] - $c1[0]) * $frac);
    $g = (int)round($c1[1] + ($c2[1] - $c1[1]) * $frac);
    $b = (int)round($c1[2] + ($c2[2] - $c1[2]) * $frac);
    return sprintf('#%02x%02x%02x', $r, $g, $b);
}
/* Convenience: color for item at $rank (0 = highest) out of $total items. */
function vts_heat_color_rank($rank, $total) {
    if ($total <= 1) return vts_heat_color(0);
    return vts_heat_color($rank / ($total - 1));
}


/* Insert a notification unless an identical one for the same user
   was created in the last 60 seconds (prevents doubles from double-taps). */
function notify_once($conn, $userId, $title, $message, $violationId = null) {
    $chk = $conn->prepare("SELECT id FROM notifications
                           WHERE user_id = :u AND message = :m
                             AND created_at > DATE_SUB(NOW(), INTERVAL 60 SECOND)
                           LIMIT 1");
    $chk->execute([':u' => $userId, ':m' => $message]);
    if ($chk->fetch()) return false;                    // duplicate — skip
    $ins = $conn->prepare("INSERT INTO notifications (user_id, title, message, violation_id)
                           VALUES (:u, :t, :m, :v)");
    $ins->execute([':u' => $userId, ':t' => $title, ':m' => $message, ':v' => $violationId]);
    return true;
}


/* Absolute base URL of the app — correct on localhost AND when hosted online.
   e.g. "http://localhost/SAD" or "https://vts.gwc.edu.ph" */
/* THE APP'S PATH ROOT, with no scheme and no host: "/SAD", or "" at a
   document root. This is what every REDIRECT should be built from.

   WHY IT EXISTS. base_url() below pins a scheme and a host onto the front,
   taken from $_SERVER['HTTP_HOST'] - the Host header the request arrived
   with. Behind a tunnel that header is not necessarily the address the phone
   typed. The usual XAMPP + ngrok recipe is

       ngrok http --host-header=localhost 80

   which rewrites Host to "localhost", so base_url() returned
   http://localhost/SAD and every post-login redirect sent the PHONE to
   localhost - itself. The browser reported "This site can't be reached" and
   it looked like a login failure, an account problem or a session clash,
   which is exactly how it was reported.

   A path-only Location header cannot have that bug: the browser keeps
   whatever origin it is already on, so the answer is right on localhost, on
   a LAN IP, through ngrok, and behind any future proxy, without the server
   needing to know its own public name. RFC 7231 allows a relative Location
   and every browser has followed one for decades.

   It still solves the problem the absolute URL was introduced for - see
   login_goto() in auth/login_process.php - because an absolute PATH resolves
   identically whether the request ran at /auth/login_process.php or at
   /student_search.php. Only the host part was ever the dangerous half. */
function base_path() {
    $root = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
    $root = preg_replace('~/(auth|admin|student|guard|osa_staff|osa|includes|api)$~', '', $root);
    return $root === '/' ? '' : $root;
}

/* FULL absolute URL, scheme and host included. Correct ONLY where a bare path
   is meaningless - an emailed link, which is opened outside any page of ours.
   Never use it for a redirect: see base_path() above.

   APP_URL in .env overrides the guess, and on a tunnel it has to: a link
   emailed while ngrok rewrites Host would otherwise point the recipient at
   their own machine. Set it to the address people actually type, e.g.
   APP_URL=https://abc123.ngrok-free.app/SAD */
function base_url() {
    $configured = trim((string)($_ENV['APP_URL'] ?? getenv('APP_URL') ?: ''));
    if ($configured !== '') return rtrim($configured, '/');

    $https  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
           || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $scheme = $https ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return $scheme . '://' . $host . base_path();
}


/* NOTE: Dean role removed (2026-08 restructure) — college-level dean
   notifications are no longer applicable. Oversight now lives entirely
   with OSA/Admin, who see every violation as it's recorded. */

/* =====================================================================
   AUTOMATION HELPERS — record → log → notify, and autosave backups.
   One place so EVERY path (manual add, offline import, future live scan)
   records the same way and always logs WHO / WHAT / WHEN.
   ===================================================================== */

/**
 * Ordinal label for a student's Nth overall violation:
 *   1 => "First Offense", 2 => "Second Offense", ... 10 => "Tenth Offense",
 *   then "11th Offense", "12th Offense", ... for the rare high counts.
 * First/Second/Third are kept word-for-word so existing rows, the report
 * summaries, and any historical data keep matching for the common cases —
 * only the cap at "Third Offense" is lifted so repeat offenders keep counting.
 */
function offense_label($n) {
    $n = max(1, (int)$n);
    $words = [
        1 => 'First',  2 => 'Second',  3 => 'Third',   4 => 'Fourth', 5 => 'Fifth',
        6 => 'Sixth',  7 => 'Seventh', 8 => 'Eighth',  9 => 'Ninth', 10 => 'Tenth',
    ];
    if (isset($words[$n])) return $words[$n] . ' Offense';
    // 11th and beyond: numeric ordinal (handles the 11/12/13 "th" special case).
    $mod100 = $n % 100;
    $mod10  = $n % 10;
    if ($mod100 >= 11 && $mod100 <= 13)      $suffix = 'th';
    elseif ($mod10 === 1)                    $suffix = 'st';
    elseif ($mod10 === 2)                    $suffix = 'nd';
    elseif ($mod10 === 3)                    $suffix = 'rd';
    else                                     $suffix = 'th';
    return $n . $suffix . ' Offense';
}

/**
 * Parse a stored offense value back to its number.
 * Accepts "First Offense".."Tenth Offense", "11th Offense", or a bare number.
 * Returns 0 when it can't be parsed (e.g. legacy "Recorded").
 */
function offense_number($offense) {
    $offense = trim((string)$offense);
    if ($offense === '') return 0;
    $words = ['first'=>1,'second'=>2,'third'=>3,'fourth'=>4,'fifth'=>5,
              'sixth'=>6,'seventh'=>7,'eighth'=>8,'ninth'=>9,'tenth'=>10];
    $lc = strtolower($offense);
    foreach ($words as $w => $num) {
        if (strncmp($lc, $w, strlen($w)) === 0) return $num;
    }
    if (preg_match('/^(\d+)/', $offense, $m)) return (int)$m[1];   // "11th Offense"
    return 0;
}

/**
 * User-facing label for a stored offense: a simple running count per student —
 * "Violation #1", "Violation #2", … Falls back to the raw stored text when it
 * isn't a countable offense (so legacy values like "Recorded" still show).
 */
/**
 * Re-derive the stored offense numbers for ONE student from what is actually
 * in the table, in chronological order.
 *
 * Why this exists: the offense number used to be stamped once at insert time
 * and never touched again. Delete a violation (or clear a Minor) and every
 * later record for that student kept its old number — the ladder drifted and
 * the official sheet, the slips and the student's own page disagreed.
 * Call this after ANY insert / delete / clear / import so the number is always
 * derived from the data rather than remembered from the past.
 *
 * Cleared Minors keep their historical number but stop advancing the ladder,
 * which matches active_violation_count().
 *
 * Returns the number of rows corrected.
 */
function vts_renumber_offenses($conn, $studentRowId) {
    $sid = (int)$studentRowId;
    if ($sid <= 0) return 0;
    if (function_exists('vts_ensure_proof_columns')) vts_ensure_proof_columns($conn);
    try {
        $q = $conn->prepare("SELECT id, offense, severity, cleared_at, proof_status
                             FROM violations WHERE student_id = :s
                             ORDER BY date_reported ASC, id ASC");
        $q->execute([':s' => $sid]);
        $rows = $q->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        // cleared_at / proof_status may not exist on very old schemas — fall back.
        $q = $conn->prepare("SELECT id, offense FROM violations WHERE student_id = :s
                             ORDER BY date_reported ASC, id ASC");
        $q->execute([':s' => $sid]);
        $rows = $q->fetchAll(PDO::FETCH_ASSOC);
    }

    $up = $conn->prepare("UPDATE violations SET offense = :o WHERE id = :id");
    $n = 0; $fixed = 0;
    foreach ($rows as $r) {
        $isClearedMinor = (($r['severity'] ?? '') === 'Minor') && !empty($r['cleared_at']);
        /* Proof reviewed and rejected: an Admin read the photo against the
           reason and found it does not support the record. The row stays in
           history but never advances the ladder, whatever its severity — which
           is why this is not limited to Minor the way a "cleared" offense is.
           See vts_decide_proof(). Pending and Approved both count, so an
           unreviewed row is never held back. */
        $isProofRejected = (($r['proof_status'] ?? 'Pending') === 'Rejected');
        $counts         = !$isClearedMinor && !$isProofRejected;
        if ($counts) $n++;                          // only active rows advance
        $should = offense_label($counts ? $n : max(1, $n));
        if ((string)($r['offense'] ?? '') !== $should) {
            $up->execute([':o' => $should, ':id' => $r['id']]);
            $fixed++;
        }
    }
    return $fixed;
}

/** Renumber EVERY student. Used by the one-off repair and after bulk imports. */
function vts_renumber_all_offenses($conn) {
    $ids = $conn->query("SELECT DISTINCT student_id FROM violations")->fetchAll(PDO::FETCH_COLUMN);
    $total = 0;
    foreach ($ids as $id) $total += vts_renumber_offenses($conn, (int)$id);
    return $total;
}

function offense_display($offense) {
    $n = offense_number($offense);
    return $n > 0 ? 'Violation #' . $n : (string)$offense;
}

/**
 * ROLE-BASED VISIBILITY of "who recorded a violation".
 *
 * OSA and Admin (the oversight roles) may see WHICH Guard/Marshal recorded a
 * given violation. OSA Staff and Students see the violation itself but NOT
 * the recorder, so the marshal on the gate is never exposed to lower tiers.
 *
 * Change this array if the policy ever needs to include OSA Staff.
 */
function vts_can_see_recorder($role = null) {
    $role = $role ?? ($_SESSION['role'] ?? '');
    return in_array($role, ['OSA', 'Admin'], true);
}

/**
 * Display label for a role. Guard's internal DB value stays "Guard" (so
 * every existing query/foreign key/session check keeps working) but the
 * system is presented to people as "Guard/Marshal" everywhere in the UI.
 */
function vts_role_label($role) {
    return $role === 'Guard' ? 'Guard/Marshal' : $role;
}

/**
 * Pill class for a role, so the badge on the user list and the one on the
 * account record are the same colour for the same role. This lived as a
 * private roleBadge() inside admin/users.php until view_user.php needed the
 * identical mapping; two copies of a colour key drift apart.
 */
function vts_role_pill($role) {
    switch (strtolower((string)$role)) {
        case 'admin': return 'review';
        case 'guard': return 'resolved';
        case 'dean':  return 'warning';
        default:      return 'atrisk';
    }
}

/**
 * Policy: only MAX_GUARD_ACCOUNTS active Guard/Marshal accounts at a time
 * (leader + assistant). Change the constant below if that ever needs to
 * flex — nothing else needs to change.
 * $excludeUserId lets an edit-in-place (same account, different field)
 * check without counting itself.
 */
function vts_guard_slot_available($conn, $excludeUserId = 0) {
    $max = 2; // MAX_GUARD_ACCOUNTS — leader + assistant
    $sql = "SELECT COUNT(*) FROM users WHERE role = 'Guard' AND status = 'Active'";
    $params = [];
    if ($excludeUserId > 0) { $sql .= " AND id != :ex"; $params[':ex'] = $excludeUserId; }
    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    return (int)$stmt->fetchColumn() < $max;
}

/**
 * Validate a School ID and REJECT the obviously-fake ones people type to slip
 * past registration — 1234567890, 0000000000, 1212121212, etc.
 *
 * Rules:
 *   - exactly 10 digits (the school's ID format)
 *   - not all the same digit          (1111111111)
 *   - not a pure ascending/descending run (1234567890 / 9876543210 and wraps)
 *   - not a short repeated block        (1212121212, 1231231231, 1234512345)
 *
 * Returns [ok(bool), reason(string)].
 */
function vts_validate_school_id($id) {
    $id = trim((string)$id);
    if ($id === '')                 return [false, 'School ID is required.'];
    if (!preg_match('/^\d{10}$/', $id)) return [false, 'School ID must be exactly 10 digits.'];

    // all identical digits
    if (preg_match('/^(\d)\1{9}$/', $id)) return [false, 'That School ID looks invalid. Please enter your real 10-digit ID.'];

    // pure sequential ascending / descending (with 0-9 wrap), e.g. 1234567890
    $asc = $desc = true;
    for ($i = 1; $i < 10; $i++) {
        $prev = (int)$id[$i-1]; $cur = (int)$id[$i];
        if ($cur !== ($prev + 1) % 10) $asc = false;
        if ($cur !== ($prev + 9) % 10) $desc = false;
    }
    if ($asc || $desc) return [false, 'That School ID looks invalid. Please enter your real 10-digit ID.'];

    // short repeated block (period 1..5 that tiles the whole 10 digits)
    foreach ([1,2,3,4,5] as $p) {
        if (10 % $p !== 0) continue;
        $block = substr($id, 0, $p);
        if (str_repeat($block, intdiv(10, $p)) === $id && $p < 10) {
            return [false, 'That School ID looks invalid. Please enter your real 10-digit ID.'];
        }
    }
    return [true, ''];
}

/**
 * Permanently delete a student EVERYWHERE, so they can no longer be searched or
 * logged in. Deleting only from `users` left the `student_roster` row behind,
 * and the login lookup re-created the account from it — that was the "deleted
 * student still exists" bug. This purges the account, its violations,
 * notifications, scan logs AND the roster row (matched by School ID), atomically.
 *
 * Returns [ok(bool), message(string)].
 */
function vts_purge_student($conn, $userId) {
    $userId = (int)$userId;
    $st = $conn->prepare("SELECT id, student_id FROM users WHERE id = :id AND role = 'Student'");
    $st->execute([':id' => $userId]);
    $stu = $st->fetch(PDO::FETCH_ASSOC);
    if (!$stu) return [false, 'Student not found.'];
    $schoolId = (string)($stu['student_id'] ?? '');

    try {
        $conn->beginTransaction();
        $conn->prepare("DELETE FROM violations    WHERE student_id = :id")->execute([':id' => $userId]);
        $conn->prepare("DELETE FROM notifications WHERE user_id    = :id")->execute([':id' => $userId]);
        // Tables that may or may not exist on a given install — never fatal.
        foreach (["DELETE FROM scan_logs WHERE student_id = :id"] as $sql) {
            try { $conn->prepare($sql)->execute([':id' => $userId]); } catch (Throwable $e) {}
        }
        // The critical one: remove from the enrolled roster by School ID so the
        // login lookup can never resurrect the account.
        if ($schoolId !== '') {
            try { $conn->prepare("DELETE FROM student_roster WHERE school_id = :sid")->execute([':sid' => $schoolId]); }
            catch (Throwable $e) {}
        }
        $conn->prepare("DELETE FROM users WHERE id = :id AND role = 'Student'")->execute([':id' => $userId]);
        $conn->commit();
    } catch (Throwable $e) {
        if ($conn->inTransaction()) $conn->rollBack();
        // Raw driver text (table/column names, constraint names) used to go
        // straight back to admin/delete_student.php's page. Logged in full;
        // the office sees a message that tells them what to do next instead.
        error_log('vts_purge_student failed for user #' . $userId . ': ' . $e->getMessage());
        return [false, 'Could not delete this student right now. Please try again.'];
    }

    try { audit_log($conn, "Delete Student", "users", $userId, "School ID " . $schoolId . " purged (account + roster)"); }
    catch (Throwable $e) {}
    return [true, 'Student permanently deleted — removed from accounts and the enrolled roster.'];
}

/**
 * "Who recorded this violation" as a full, human-readable string — never a raw
 * id. Prefers the staff account's full name (and role, when joined in);
 * mentions the on-device scanner/Marshall label when it differs; falls back to
 * that label, then to "System".
 *
 * Pass a joined violations row that ideally carries:
 *   reporter_name  (users.fullname via LEFT JOIN ON v.reported_by = users.id)
 *   reporter_role  (users.role, optional)
 *   scanner_name   (the label captured on the recording device)
 */
function violation_recorder(array $row): string {
    $name  = trim((string)($row['reporter_name'] ?? ''));
    $role  = trim((string)($row['reporter_role'] ?? ''));
    $label = trim((string)($row['scanner_name'] ?? ''));

    if ($name !== '') {
        $who = $role !== '' ? "{$name} ({$role})" : $name;
        // If the device label is a different person (e.g. a Marshall on duty
        // under a Guard's account), show it too so nothing is lost.
        if ($label !== '' && strcasecmp($label, 'System') !== 0
            && stripos($label, $name) === false) {
            $who .= ' · ' . $label;
        }
        return $who;
    }
    if ($label !== '' && strcasecmp($label, 'System') !== 0) return $label;
    return 'System';
}

/**
 * Record a violation automatically.
 *   - Computes the offense number (1st/2nd/3rd/…) from the student's history.
 *   - Computes points from the type's max_points (unless overridden).
 *   - Inserts the violation.
 *   - Writes a scan_log row  => WHO recorded, for WHICH student, WHEN.
 *   - Notifies the student and their college dean(s).
 * Returns the new violation id, or 0 on failure.
 *
 * $opts (all optional):
 *   'description' string, 'status' string (default 'Recorded'),
 *   'offense' string  (override auto offense),
 *   'points' int      (override auto points),
 *   'max_points' int  (used to auto-compute points when 'points' not given),
 *   'log' bool        (default true — write the scan_log)
 */
/* Make sure the 2026-07d categorization columns exist even if the admin
   hasn't run upgrade_2026-07d_categorization.sql yet. Best-effort, cached per
   request, and swallows errors so a missing CREATE/ALTER privilege never
   breaks recording. */
function vts_ensure_categorization_columns($conn) {
    static $done = false;
    if ($done) return;
    $done = true;
    $adds = [
        ['violations',      'cleared_at',     'DATETIME NULL'],
        ['violations',      'cleared_by',     'INT UNSIGNED NULL'],
        ['violation_types', 'critical_alert', 'TINYINT(1) NOT NULL DEFAULT 0'],
        // Base schema always had this column, but a live DB that was set up
        // by hand or from a partial/older SQL import can be missing it — that
        // was the "Unknown column 'max_points'" crash during scan import.
        ['violation_types', 'max_points',     'INT UNSIGNED NOT NULL DEFAULT 1'],
    ];
    foreach ($adds as [$tbl, $col, $ddl]) {
        try {
            $chk = $conn->prepare("SELECT 1 FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = :c");
            $chk->execute([':t' => $tbl, ':c' => $col]);
            if (!$chk->fetchColumn()) $conn->exec("ALTER TABLE `{$tbl}` ADD COLUMN `{$col}` {$ddl}");
        } catch (Throwable $e) { /* ignore — feature degrades, app keeps working */ }
    }
}

/* Provision a fully-usable, scannable STUDENT account from an enrollment record
   — the "auto-registered on enrollment" flow. Idempotent: if a users row already
   exists for the School ID it's returned untouched. Otherwise a passwordless
   account is created (unusable random password; the student signs in by name +
   School ID), a QR is generated, and the roster row is marked used.
   $opts: lastname, firstname, course, year_level, section, email, gender.
   Returns ['id'=>int, 'created'=>bool, 'qr'=>?string]. */
function vts_provision_student_account($conn, $schoolId, array $opts = []) {
    vts_ensure_role_enum($conn);
    $schoolId = strtoupper(trim((string)$schoolId));
    if ($schoolId === '') return ['id' => 0, 'created' => false, 'qr' => null];

    // Already have an account? Return it.
    $ex = $conn->prepare("SELECT id, qr_code FROM users WHERE student_id = :s AND role='Student' LIMIT 1");
    $ex->execute([':s' => $schoolId]);
    if ($row = $ex->fetch(PDO::FETCH_ASSOC)) {
        return ['id' => (int)$row['id'], 'created' => false, 'qr' => $row['qr_code'] ?? null];
    }

    $firstname  = name_case($opts['firstname'] ?? '');
    $middlename = name_case($opts['middlename'] ?? '');
    $lastname   = name_case($opts['lastname'] ?? '');
    $suffix     = trim((string)($opts['suffix'] ?? ''));
    $fullname   = trim(preg_replace('/\s+/', ' ', "$firstname $middlename $lastname $suffix"));
    if ($fullname === '') $fullname = 'Student ' . $schoolId;
    $course = trim($opts['course'] ?? '');
    $year   = trim($opts['year_level'] ?? '');
    $section= trim($opts['section'] ?? '');
    $collegeId = ($opts['college_id'] ?? '') !== '' ? (int)$opts['college_id'] : null;
    // Real email if the enrollment record has one; else a unique placeholder.
    $email  = filter_var($opts['email'] ?? '', FILTER_VALIDATE_EMAIL)
        ? trim($opts['email'])
        : strtolower(preg_replace('/[^a-z0-9]/i', '', $schoolId)) . '@student.gwc.local';
    $username = 'stu_' . strtolower(preg_replace('/[^a-z0-9]/i', '', $schoolId));
    $gender = in_array($opts['gender'] ?? '', ['Male','Female','Other'], true) ? $opts['gender'] : null;
    $hash = password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT);

    try {
        $ins = $conn->prepare("INSERT INTO users
            (student_id, firstname, middlename, lastname, suffix, fullname, username, email, password, role,
             college_id, course, year_level, section, gender, status, email_verified)
            VALUES (:sid,:fn,:mn,:ln,:sfx,:full,:un,:em,:pw,'Student',:college,:course,:year,:sec,:gender,'Active',1)");
        $ins->execute([
            ':sid' => $schoolId, ':fn' => ($firstname ?: null),
            ':mn' => ($middlename ?: null), ':ln' => ($lastname ?: null),
            ':sfx' => ($suffix ?: null),
            ':full' => $fullname, ':un' => $username, ':em' => $email, ':pw' => $hash,
            ':college' => $collegeId, ':course' => ($course ?: null),
            ':year' => ($year ?: null), ':sec' => ($section ?: null),
            ':gender' => $gender,
        ]);
        $newId = (int)$conn->lastInsertId();
    } catch (Throwable $e) {
        // Unique clash (username/email/student_id) — treat as "already exists".
        $ex->execute([':s' => $schoolId]);
        $row = $ex->fetch(PDO::FETCH_ASSOC);
        return ['id' => $row ? (int)$row['id'] : 0, 'created' => false, 'qr' => $row['qr_code'] ?? null];
    }

    // Mark the roster row used (best-effort).
    try { $conn->prepare("UPDATE student_roster SET is_used=1 WHERE school_id=:s")->execute([':s' => $schoolId]); }
    catch (Throwable $e) {}

    // Generate the QR now (per "100% may QR code na agad after enrollment").
    $qrName = null;
    if (@include_once __DIR__ . '/qr_helper.php' && function_exists('ensure_student_qr_file')) {
        try {
            $qrPath = ensure_student_qr_file($schoolId, $schoolId . '|' . $fullname . '|' . $year . '|' . $course);
            if ($qrPath) {
                $qrName = basename($qrPath);
                $conn->prepare("UPDATE users SET qr_code=:qr WHERE id=:id")->execute([':qr' => $qrName, ':id' => $newId]);
            }
        } catch (Throwable $e) { /* non-fatal */ }
    }
    return ['id' => $newId, 'created' => true, 'qr' => $qrName];
}

/* Save an uploaded evidence file (image/pdf) to uploads/evidence/ and return
   the stored filename, or null if there was no file / it was rejected. Never
   throws — a bad upload just means "no evidence attached". */
function vts_save_evidence_upload($file, $maxBytes = 5242880) {
    if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return null;
    if (($file['error'] ?? 1) !== UPLOAD_ERR_OK) return null;
    if (($file['size'] ?? 0) <= 0 || $file['size'] > $maxBytes) return null;
    if (empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) return null;

    $ext = strtolower(pathinfo($file['name'] ?? '', PATHINFO_EXTENSION));
    $allowed = ['jpg','jpeg','png','gif','webp','pdf'];
    if (!in_array($ext, $allowed, true)) return null;

    /* CHECK THE CONTENT, not just the name. The extension whitelist above is
       what actually stops a webshell (the stored name is rebuilt from $ext, so
       "shell.php.jpg" can only ever land as .jpg, and uploads/.htaccess
       refuses to run scripts from there anyway). But a file is still taken on
       the word of whoever named it -- the profile-photo paths verify the bytes
       and this one did not. An image must really decode as the image type it
       claims, and a PDF must start with %PDF-. */
    $realExt = null;
    if ($ext === 'pdf') {
        $head = @file_get_contents($file['tmp_name'], false, null, 0, 5);
        if ($head === '%PDF-') $realExt = 'pdf';
    } else {
        $info = @getimagesize($file['tmp_name']);
        if ($info !== false) {
            $byType = [
                IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png',
                IMAGETYPE_GIF  => 'gif', IMAGETYPE_WEBP => 'webp',
            ];
            $realExt = $byType[$info[2]] ?? null;
        }
    }
    if ($realExt === null) return null;      // not the file it says it is
    $ext = $realExt;                         // store it under what it ACTUALLY is

    $dir = __DIR__ . '/../uploads/evidence';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $name = 'ev_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    if (!@move_uploaded_file($file['tmp_name'], $dir . '/' . $name)) return null;
    return $name;
}

/**
 * Save a photo the SCANNER captured, handed over as a data: URL.
 * Returns the stored filename, or null if it is not a usable image.
 *
 * The gate scanner has no file input to post — it grabs a frame from the
 * camera it is already running and sends it as base64, both live
 * (api/scan_submit.php) and inside an offline .vtsl that gets imported
 * later. Same destination and same naming as vts_save_evidence_upload(),
 * so a photo from the gate is indistinguishable from one attached at a
 * desk once it is on disk.
 *
 * The checks are the same in substance, for the same reason: the bytes
 * arrive from a device anyone can open and edit, so nothing here is taken
 * on trust. The declared mime type in the data: URL is IGNORED entirely —
 * the extension is decided by what the bytes actually decode as.
 */
function vts_save_evidence_base64($dataUrl, $maxBytes = 5242880) {
    if (!is_string($dataUrl) || $dataUrl === '') return null;

    /* Accept a bare base64 blob as well as a full data: URL — a client that
       strips the prefix is not wrong, and failing on it silently loses the
       only proof of a violation. */
    $b64 = $dataUrl;
    if (preg_match('~^data:([a-z0-9.+/-]+)?;base64,~i', $dataUrl)) {
        $b64 = substr($dataUrl, strpos($dataUrl, ',') + 1);
    }
    $b64 = preg_replace('/\s+/', '', $b64);
    if ($b64 === '' || strlen($b64) > (int)ceil($maxBytes * 4 / 3) + 1024) return null;

    $bytes = base64_decode($b64, true);          // strict: reject anything malformed
    if ($bytes === false || $bytes === '') return null;
    if (strlen($bytes) > $maxBytes) return null;

    /* Must really BE an image. getimagesizefromstring() parses the header
       rather than believing a label, which is what stops a script being
       stored under a .jpg name. */
    $info = @getimagesizefromstring($bytes);
    if ($info === false) return null;
    $byType = [
        IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png',
        IMAGETYPE_GIF  => 'gif', IMAGETYPE_WEBP => 'webp',
    ];
    $ext = $byType[$info[2]] ?? null;
    if ($ext === null) return null;              // a real image, but not a web one

    $dir = __DIR__ . '/../uploads/evidence';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $name = 'ev_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    if (@file_put_contents($dir . '/' . $name, $bytes) === false) return null;
    return $name;
}

/* Make sure the violations table can actually store what the app writes:
   `offense` must be VARCHAR (the old ENUM capped at "Third Offense", so a 4th
   violation was silently blanked — bug 40), and severity/points/evidence/
   remarks must exist (bug 38). Best-effort, cached per request. */
function vts_ensure_violation_columns($conn) {
    static $done = false;
    if ($done) return;
    $done = true;
    // Widen offense if it's still an ENUM.
    try {
        $t = $conn->query("SELECT COLUMN_TYPE FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='violations' AND COLUMN_NAME='offense'")->fetchColumn();
        if ($t && stripos($t, 'enum') !== false) {
            $conn->exec("ALTER TABLE violations MODIFY COLUMN offense VARCHAR(30) NOT NULL DEFAULT 'First Offense'");
        }
    } catch (Throwable $e) { /* ignore */ }
    // Ensure the supporting columns exist.
    $adds = [
        ['severity', "ENUM('Minor','Major','Grave') NOT NULL DEFAULT 'Minor'"],
        ['points',   "INT UNSIGNED NOT NULL DEFAULT 1"],
        ['evidence', "VARCHAR(255) NULL"],
        ['remarks',  "TEXT NULL"],
    ];
    foreach ($adds as [$col, $ddl]) {
        try {
            $chk = $conn->prepare("SELECT 1 FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='violations' AND COLUMN_NAME=:c");
            $chk->execute([':c' => $col]);
            if (!$chk->fetchColumn()) $conn->exec("ALTER TABLE violations ADD COLUMN `{$col}` {$ddl}");
        } catch (Throwable $e) { /* ignore */ }
    }
}

/* Whether a violation may be Served/Cleared. Rule (per handbook + July review):
   a MINOR offense can be cleared once — but only if it is the student's SOLE
   minor. A REPEATED minor (the student has 2+ minors on record) and any
   Major/Grave offense are PERMANENT and can never be removed. Returns
   [bool $ok, string $reason]. */
function violation_is_clearable($conn, $violationId) {
    $st = $conn->prepare("SELECT severity, student_id, cleared_at FROM violations WHERE id = :id");
    $st->execute([':id' => (int)$violationId]);
    $v = $st->fetch(PDO::FETCH_ASSOC);
    if (!$v)                              return [false, 'Violation not found.'];
    if ($v['severity'] !== 'Minor')       return [false, 'Only Minor offenses can be cleared. Major/Grave records are permanent.'];
    if (!empty($v['cleared_at']))         return [false, 'That violation is already cleared.'];
    // Repeated minor? Count ALL of this student's minors (this one included).
    $cnt = $conn->prepare("SELECT COUNT(*) FROM violations WHERE student_id = :sid AND severity = 'Minor'");
    $cnt->execute([':sid' => (int)$v['student_id']]);
    if ((int)$cnt->fetchColumn() > 1)     return [false, 'Repeated minor offense — this student has more than one minor on record, so it can no longer be removed.'];
    return [true, ''];
}

/* THE ONE-MAJOR RULE — a student carries at most ONE Major offense, ever.
   The first Major is the end of the ladder, so a SECOND Major (of any type,
   not just a repeat of the same one) is refused at every path that files a
   record: the Admin/OSA forms, the live scanner, the open-device sync and the
   offline file import. Minor offenses are untouched — they still stack and
   still escalate 1st/2nd/3rd. "Grave" is folded in with Major here exactly as
   it is everywhere else in the system. Returns [bool $blocked, string $why]. */
function major_violation_blocked($conn, $studentRowId, $severity) {
    // Anything that is not Minor counts as Major-tier; a blank severity is
    // treated as Minor so a half-filled row can never trip the block.
    $sev = trim((string)$severity);
    if ($sev === '' || strcasecmp($sev, 'Minor') === 0) return [false, ''];

    $studentRowId = (int)$studentRowId;
    if ($studentRowId <= 0) return [false, ''];

    try {
        $q = $conn->prepare("SELECT violation, date_reported FROM violations
                             WHERE student_id = :sid AND severity <> 'Minor'
                             ORDER BY date_reported ASC, id ASC LIMIT 1");
        $q->execute([':sid' => $studentRowId]);
        $existing = $q->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        // History unreadable (column missing on a half-migrated DB) — never
        // block a recording because of a DB error; let the record through.
        return [false, ''];
    }
    if (!$existing) return [false, ''];

    $when = !empty($existing['date_reported'])
          ? date('M j, Y', strtotime((string)$existing['date_reported'])) : '';
    return [true, 'This student already has a Major offense on record — "'
        . $existing['violation'] . '"' . ($when !== '' ? ' (' . $when . ')' : '')
        . '. A Major offense is recorded once only, so a second one cannot be added.'];
}

/* Why the last record_violation() call refused to file a record, so the page
   that called it can show the real reason instead of a generic "could not be
   recorded". Pass a string to set it; call with no argument to read it. */
function vts_last_violation_error($set = null) {
    static $msg = '';
    if (is_string($set)) $msg = $set;
    return $msg;
}

/* Make sure a staff id can legally be used as violations.reported_by.

   This database carries a legacy `people_registry` table that owns a shared
   id space for every kind of person, and violations.reported_by has a foreign
   key to it -- but no PHP in this app has ever written to that table. So any
   account created through the app itself has a `users` row and NO registry
   row, and every violation that account tried to file was rejected by the
   foreign key. record_violation() caught the exception and returned 0, the
   importer turned that into "Skipped - could not record one row", and the
   import still reported OK: a whole shift could be imported, be silently
   discarded, and look like a success. Registering the id repairs the cause
   and keeps the attribution. Returns true when the id is safe to use. */
function vts_ensure_person_registered($conn, $userId, $role = null): bool {
    $userId = (int)$userId;
    if ($userId <= 0) return false;
    static $checked = [];
    if (isset($checked[$userId])) return $checked[$userId];

    try {
        $t = $conn->query("SHOW TABLES LIKE 'people_registry'")->fetchColumn();
        if (!$t) return $checked[$userId] = true;    // no such table: nothing to satisfy

        $q = $conn->prepare("SELECT 1 FROM people_registry WHERE id = :id");
        $q->execute([':id' => $userId]);
        if ($q->fetchColumn()) return $checked[$userId] = true;

        if ($role === null) {
            $r = $conn->prepare("SELECT role FROM users WHERE id = :id");
            $r->execute([':id' => $userId]);
            $role = $r->fetchColumn() ?: 'Admin';
        }
        $conn->prepare("INSERT INTO people_registry (id, role) VALUES (:id, :r)")
             ->execute([':id' => $userId, ':r' => $role]);
        return $checked[$userId] = true;
    } catch (Throwable $e) {
        return $checked[$userId] = false;
    }
}

/* ACTIVE offense count for a student: every violation on file EXCEPT Minor
   offenses that an admin already marked Served/Cleared. This is the number the
   1st/2nd/3rd escalation is built on, so clearing a minor genuinely resets the
   ladder while the record itself stays in history. Major/Grave always count. */
function active_violation_count($conn, $studentRowId) {
    vts_ensure_categorization_columns($conn);
    if (function_exists('vts_ensure_proof_columns')) vts_ensure_proof_columns($conn);
    try {
        $q = $conn->prepare("SELECT COUNT(*) FROM violations
                             WHERE student_id = :sid
                               AND NOT (severity = 'Minor' AND cleared_at IS NOT NULL)
                               /* Proof reviewed and found not to support the
                                  record (admin/proof.php). Pending is NOT
                                  excluded: an unreviewed violation counts
                                  exactly as it always did, so nothing is held
                                  up waiting for a reviewer. */
                               AND proof_status <> 'Rejected'");
        $q->execute([':sid' => (int)$studentRowId]);
        return (int)$q->fetchColumn();
    } catch (Throwable $e) {
        // Columns not present yet — fall back to the raw total.
        $q = $conn->prepare("SELECT COUNT(*) FROM violations WHERE student_id = :sid");
        $q->execute([':sid' => (int)$studentRowId]);
        return (int)$q->fetchColumn();
    }
}

/* Severity breakdown for a student (all-time, permanent). Used to warn a guard
   when a repeat offender is scanned. Returns ['total','major','grave']. */
function violation_severity_counts($conn, $studentRowId) {
    try {
        $q = $conn->prepare("SELECT
                COUNT(*) AS total,
                SUM(severity = 'Major') AS major,
                SUM(severity = 'Grave') AS grave
              FROM violations WHERE student_id = :sid");
        $q->execute([':sid' => (int)$studentRowId]);
        $r = $q->fetch(PDO::FETCH_ASSOC) ?: [];
        return [
            'total' => (int)($r['total'] ?? 0),
            'major' => (int)($r['major'] ?? 0),
            'grave' => (int)($r['grave'] ?? 0),
        ];
    } catch (Throwable $e) {
        return ['total' => 0, 'major' => 0, 'grave' => 0];
    }
}

/* Immediate, urgent alert to every Admin when a critical violation (deadly
   weapon, explosives, etc.) is recorded — separate from the routine per-
   violation notice the student/deans get. */
function notify_critical($conn, $studentRowId, $violationName, $violationId = null) {
    try {
        $q = $conn->prepare("SELECT fullname, student_id FROM users WHERE id = :id");
        $q->execute([':id' => (int)$studentRowId]);
        $stu = $q->fetch(PDO::FETCH_ASSOC) ?: ['fullname' => 'A student', 'student_id' => ''];
        $admins = $conn->query("SELECT id FROM users WHERE role='Admin' AND status='Active'")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($admins as $aid) {
            notify_once($conn, (int)$aid, '🚨 CRITICAL Violation',
                'CRITICAL: ' . $violationName . ' recorded for ' . $stu['fullname']
                . ' (' . $stu['student_id'] . '). Immediate attention required.',
                $violationId);
        }
    } catch (Throwable $e) { /* non-fatal */ }
}

/* True when a violation type is flagged for an immediate critical Admin alert. */
function violation_is_critical($conn, $violationName) {
    vts_ensure_categorization_columns($conn);
    try {
        $q = $conn->prepare("SELECT MAX(critical_alert) FROM violation_types WHERE violation_name = :v");
        $q->execute([':v' => $violationName]);
        return (int)$q->fetchColumn() === 1;
    } catch (Throwable $e) {
        return false;
    }
}

function record_violation($conn, $studentRowId, $violationName, $severity, $reportedBy, $scannerLabel = '', array $opts = []) {
    vts_last_violation_error('');          // fresh call — drop the previous reason
    $studentRowId = (int)$studentRowId;
    $violationName = trim((string)$violationName);
    if ($studentRowId <= 0 || $violationName === '') return 0;
    vts_ensure_violation_columns($conn);   // offense must be VARCHAR (bug 40) + columns exist (bug 38)

    /* PER-TYPE ESCALATION WAS REMOVED HERE (2026-09).
       A violation type used to carry an `escalate_after` number that silently
       promoted a Minor to Major on the Nth offense of that same type. It made
       the severity on a record depend on a setting nobody could see from the
       record, and the office did not use it. A type is Minor or Major, full
       stop, and the 1st/2nd/3rd ladder is what tracks repetition. */

    /* ONE-MAJOR RULE — the backstop. Every caller is expected to check first so
       it can show a proper message, but the rule is enforced here too so no
       path (present or future) can slip a second Major past it. */
    [$majorBlocked, $majorWhy] = major_violation_blocked($conn, $studentRowId, $severity);
    if ($majorBlocked) {
        vts_last_violation_error($majorWhy);
        return 0;
    }

    // Offense # (auto) from this student's ACTIVE prior violations — a cleared
    // Minor no longer counts, so the 1st/2nd/3rd ladder resets after it's served.
    $prior = active_violation_count($conn, $studentRowId);

    $offense = $opts['offense'] ?? offense_label($prior + 1);
    /* POINTS WERE REMOVED (2026-09). A violation used to score
       max_points × the offense number, which gave every record a number
       nothing in the app displayed, nobody set deliberately, and no rule in
       the handbook referred to. Severity (Minor/Major) and the 1st/2nd/3rd
       ladder are what decide anything.

       The column stays at 1 rather than being dropped: the per-course views
       in database/student_violation_system.sql SUM() it, so removing it would
       break them, and at 1 that sum is simply the violation count. */
    $points = 1;
    $status = $opts['status'] ?? 'Recorded';
    $desc   = (string)($opts['description'] ?? '');
    $remarks  = isset($opts['remarks'])  && trim((string)$opts['remarks'])  !== '' ? trim((string)$opts['remarks'])  : null;
    $evidence = isset($opts['evidence']) && trim((string)$opts['evidence']) !== '' ? trim((string)$opts['evidence']) : null;
    $label  = trim((string)$scannerLabel) !== '' ? trim((string)$scannerLabel) : 'System';
    $reportedBy = (int)$reportedBy;

    /* A violation must be filed at the moment it was SCANNED, not the moment
       an offline file happened to be imported — otherwise a whole day of gate
       scans lands with the import timestamp, the times on the slips are wrong,
       and the offense order is decided by import order instead of real order.
       Callers pass opts['date_reported'] when they know the true scan time. */
    $when = null;
    if (!empty($opts['date_reported'])) {
        $t = is_numeric($opts['date_reported'])
            ? (int)$opts['date_reported']
            : strtotime((string)$opts['date_reported']);
        // Ignore nonsense/future stamps from a phone with a bad clock.
        if ($t !== false && $t > 0 && $t <= time() + 300) $when = date('Y-m-d H:i:s', $t);
    }

    // The reporter must satisfy the reported_by foreign key, or the whole
    // INSERT is rejected (see vts_ensure_person_registered).
    if ($reportedBy > 0) vts_ensure_person_registered($conn, $reportedBy);

    $sql = "INSERT INTO violations
            (student_id, violation, description, severity, offense, points, status, reported_by, scanner_name, remarks, evidence, date_reported)
            VALUES (:sid,:v,:d,:sev,:off,:pts,:st,:rb,:sn,:rem,:evi," . ($when === null ? "NOW()" : ":dt") . ")";
    $bind = [
        ':sid' => $studentRowId, ':v' => $violationName, ':d' => $desc, ':sev' => $severity,
        ':off' => $offense, ':pts' => $points, ':st' => $status,
        ':rb' => $reportedBy ?: null, ':sn' => $label, ':rem' => $remarks, ':evi' => $evidence,
    ];
    if ($when !== null) $bind[':dt'] = $when;

    try {
        $ins = $conn->prepare($sql);
        $ins->execute($bind);
        $vid = (int)$conn->lastInsertId();
    } catch (Throwable $e) {
        /* A VIOLATION MUST NOT BE LOST OVER WHO FILED IT. If the record is
           still refused because of the reporter, file it unattributed rather
           than dropping a real offence on the floor -- the student, the
           violation and the time are the part that matters, and a silently
           discarded scan is far worse than a missing reporter name. */
        $why = $e->getMessage();
        if ($reportedBy > 0 && stripos($why, 'foreign key') !== false) {
            try {
                $bind[':rb'] = null;
                $ins = $conn->prepare($sql);
                $ins->execute($bind);
                $vid = (int)$conn->lastInsertId();
                error_log('record_violation: filed without reporter #' . $reportedBy . ' (' . $why . ')');
            } catch (Throwable $e2) {
                /* The raw exception ($e2->getMessage()) used to be handed
                   straight to vts_last_violation_error(), whose caller
                   (import_scan_file()) puts it verbatim into the "skipped"
                   line the SCANNER reads back as api/scan_submit.php's JSON
                   `note` field — a raw SQLSTATE/column-name string sitting in
                   a phone's network tab. Logged in full server-side; the
                   caller gets a message with nothing to act on maliciously. */
                error_log('record_violation: insert failed even without reporter: ' . $e2->getMessage());
                vts_last_violation_error('The record could not be saved. Please try again or tell the OSA if this keeps happening.');
                return 0;
            }
        } else {
            error_log('record_violation: insert failed: ' . $why);
            vts_last_violation_error('The record could not be saved. Please try again or tell the OSA if this keeps happening.');
            return 0;
        }
    }

    // AUTO-LOG: who / for whom / when. (scan_logs.scanned_by has a FK, so only
    // log when we know a real staff user id.)
    if (($opts['log'] ?? true) && $reportedBy > 0) {
        try {
            $conn->prepare("INSERT INTO scan_logs (student_id, scanned_by, scanner_name, scan_time)
                            VALUES (:s,:g,:n,NOW())")
                 ->execute([':s' => $studentRowId, ':g' => $reportedBy, ':n' => $label]);
        } catch (Throwable $e) { /* logging is best-effort */ }
    }

    // Notify the student + their dean(s) — one notification each, no duplicates.
    // Dean role removed — no more college-level dean notification here.
    try {
        notify_once($conn, $studentRowId, 'New Violation Recorded',
            'A violation was recorded: ' . $violationName . ' (' . offense_display($offense) . '). It has been added to your record.',
            $vid);
    } catch (Throwable $e) {}

    // Critical (deadly weapon / explosives / etc.) — alert every Admin at once.
    if (violation_is_critical($conn, $violationName)) {
        notify_critical($conn, $studentRowId, $violationName, $vid);
    }

    // Tell the student: in-app/PWA warning always; the EXTERNAL email is skipped
    // during bulk file import (opts['email'] === false) so a slow mail API can't
    // stall the import row-by-row. Imports queue the emails to send afterwards.
    $sendEmail = !array_key_exists('email', $opts) || $opts['email'] !== false;
    if ($sendEmail) {
        vts_notify_student_of_violation($conn, $studentRowId, $violationName, $severity, $prior + 1, $vid);
    } else {
        // still create the in-app three-strike warning, just no external email
        try { notify_three_strike_warning($conn, $studentRowId, $prior + 1, $vid); } catch (Throwable $e) {}
    }

    return $vid;
}

/* Make sure the role enum is on the current list (Student, Guard, OSA Staff,
   OSA, Admin) even if the 2026-08 upgrade SQL hasn't been run yet.
   Best-effort, cached per request. */
function vts_ensure_role_enum($conn) {
    static $done = false;
    if ($done) return;
    try {
        $col = $conn->query("SELECT COLUMN_TYPE FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME='users' AND COLUMN_NAME='role'")->fetchColumn();
        if ($col && (stripos($col, "'OSA Staff'") === false
            || stripos($col, "'Head Marshal'") !== false
            || stripos($col, "'Dean'") !== false)) {
            // Widen first so any leftover Head Marshal/Dean rows have somewhere
            // to land, then move them and shrink to the final list.
            $conn->exec("ALTER TABLE users MODIFY COLUMN role
                ENUM('Student','Guard','Head Marshal','OSA Staff','OSA','Dean','Admin') NOT NULL DEFAULT 'Student'");
            $conn->exec("UPDATE users SET role = 'Guard' WHERE role = 'Head Marshal'");
            $conn->exec("UPDATE users SET role = 'OSA Staff', status = 'Inactive' WHERE role = 'Dean'");
            $conn->exec("ALTER TABLE users MODIFY COLUMN role
                ENUM('Student','Guard','OSA Staff','OSA','Admin') NOT NULL DEFAULT 'Student'");
        }
            $done = true;
    } catch (Throwable $e) { /* ignore */ }
}

/* Safety net for vts_guard_slot_available()'s cousin — same idea as
   vts_ensure_role_enum() but for the 2026-08b migration (per-college OSA
   contact). Adds the columns if the upgrade SQL hasn't been run yet, so a
   fresh feature never hard-crashes an install that skipped the script. */
function vts_ensure_college_osa_columns($conn) {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        $col = $conn->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME='colleges' AND COLUMN_NAME='osa_email'")->fetchColumn();
        if (!$col) {
            $conn->exec("ALTER TABLE colleges
                ADD COLUMN osa_email VARCHAR(190) NULL AFTER short_name,
                ADD COLUMN osa_name  VARCHAR(150) NULL AFTER osa_email");
        }
    } catch (Throwable $e) { /* ignore */ }
}

/* Final report recipients: the fixed OSA inbox PLUS every active Admin/OSA
   email on file (so it arrives even when no admin is on duty). Deduped;
   placeholder student.gwc.local addresses are skipped. Returns [[email,name],…]. */
function report_recipients($conn) {
    $out = [];
    if (defined('OSA_REPORT_EMAIL') && filter_var(OSA_REPORT_EMAIL, FILTER_VALIDATE_EMAIL)) {
        $out[strtolower(OSA_REPORT_EMAIL)] = [OSA_REPORT_EMAIL, defined('OSA_REPORT_NAME') ? OSA_REPORT_NAME : 'OSA'];
    }
    try {
        // OSA + Admin (they receive/act on it) plus OSA Staff as a copy.
        $staff = $conn->query("SELECT fullname, email FROM users
            WHERE role IN ('Admin','OSA','OSA Staff') AND status='Active' AND email IS NOT NULL AND email <> ''")
            ->fetchAll(PDO::FETCH_ASSOC);
        foreach ($staff as $s) {
            $e = strtolower(trim($s['email']));
            if ($e === '' || strpos($e, '@student.gwc.local') !== false) continue;
            if (!isset($out[$e])) $out[$e] = [$s['email'], $s['fullname']];
        }
    } catch (Throwable $e) { /* ignore */ }
    return array_values($out);
}

/* Send a strongly-worded warning notification when a student's ACTIVE violation
   count reaches the 3-strike threshold. Called from every recording path so the
   warning fires whether a violation is added by the API, the scanner, or an
   import. The student also sees this pushed via the installed web app. */
/* One call that tells the student about a new violation BOTH ways:
   the in-app/PWA warning AND an email to the address on file. The email is
   best-effort — it silently skips students who only have the auto-generated
   placeholder address, and never breaks recording if mail is unconfigured. */
function vts_notify_student_of_violation($conn, $studentRowId, $violationName, $severity, $newActiveCount, $violationId = null) {
    notify_three_strike_warning($conn, $studentRowId, $newActiveCount, $violationId);
    if (!function_exists('send_student_violation_email')) {
        @include_once __DIR__ . '/mailer.php';
    }
    if (function_exists('send_student_violation_email')) {
        try { send_student_violation_email($conn, $studentRowId, $violationName, $severity, $newActiveCount); }
        catch (Throwable $e) { /* mail must never break a scan */ }
    }
}

function notify_three_strike_warning($conn, $studentRowId, $newActiveCount, $violationId = null) {
    $n = (int)$newActiveCount;
    if ($n < 1) return;
    // Escalating warnings: 1st → 3rd are warnings, the 4th is the limit/escalation.
    [$title, $msg] = vts_offense_warning_text($n);
    try { notify_once($conn, (int)$studentRowId, $title, $msg, $violationId); } catch (Throwable $e) {}
}

/* The escalating warning copy for a given active offense count. One place so
   the notification, the dashboard banner, and the pop-up all say the same thing.
   Returns [title, message, level] where level is warn|caution|final|escalation. */
function vts_offense_warning_text($n) {
    $n = (int)$n;
    if ($n <= 1) return [' 1st Offense — Warning',
        'This is your 1st recorded violation. Consider this a warning — please follow school policies to avoid further offenses.', 'warn'];
    if ($n === 2) return ['2nd Offense — Caution',
        'You now have 2 recorded violations. Please be careful — a 3rd offense is your final warning before disciplinary escalation.', 'caution'];
    if ($n === 3) return [' 3rd Offense — FINAL Warning',
        'You now have 3 recorded violations. This is your FINAL warning. One more offense will lead to disciplinary escalation — please see the OSA.', 'final'];
    return [' Escalation — Limit Exceeded',
        'You now have ' . $n . ' recorded violations and have exceeded the 3-strike limit. Your case is subject to disciplinary escalation. Please report to the OSA immediately.', 'escalation'];
}

/**
 * Build a .sql backup of STUDENTS + VIOLATIONS only — no staff accounts,
 * passwords, profile pictures, notifications, or logs. Shared by the Backup
 * page and the autosave helper, so every backup path stays scoped the same
 * way. Student rows are exported with the sensitive account columns
 * (username, password, profile_picture, qr_code, verify_code/expires)
 * dropped, and land in a `students_backup` table so the live `users` table
 * is never overwritten by an accidental restore.
 */
function vts_build_sql_dump($conn) {
    vts_ensure_violation_columns($conn);   // so the dump never trips on a missing column (bug 38)
    ob_start();
    echo "-- VTS Backup — Students + Violations only — " . date('Y-m-d H:i:s') . "\n";
    echo "-- Staff accounts, passwords, profile pictures, notifications, and logs are NOT included.\n";
    echo "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS = 0;\n\n";

    // ---- Students (role='Student'), safe columns only ----
    $studentCols = ['id','student_id','firstname','middlename','lastname','suffix','fullname',
                    'email','contact_number','gender','birthday','college_id','course','year_level',
                    'section','status','email_verified','created_at'];
    $colList = implode(',', array_map(fn($c) => "`{$c}`", $studentCols));
    echo "-- ---------------- students ----------------\n";
    echo "DROP TABLE IF EXISTS `students_backup`;\n";
    echo "CREATE TABLE `students_backup` LIKE `users`;\n";
    foreach (['username','password','profile_picture','qr_code','verify_code','verify_expires'] as $drop) {
        echo "ALTER TABLE `students_backup` DROP COLUMN `{$drop}`;\n";
    }
    $rows = $conn->query("SELECT {$colList} FROM `users` WHERE role='Student'");
    while ($row = $rows->fetch(PDO::FETCH_ASSOC)) {
        $cols = array_map(fn($c) => "`{$c}`", array_keys($row));
        $vals = array_map(fn($v) => $v === null ? "NULL" : $conn->quote($v), array_values($row));
        echo "INSERT INTO `students_backup` (" . implode(",", $cols) . ") VALUES (" . implode(",", $vals) . ");\n";
    }
    echo "\n";

    // ---- Violations (full table) ----
    echo "-- ---------------- violations ----------------\n";
    echo "DROP TABLE IF EXISTS `violations`;\n";
    $create = $conn->query("SHOW CREATE TABLE `violations`")->fetch(PDO::FETCH_NUM);
    echo $create[1] . ";\n\n";
    $rows = $conn->query("SELECT * FROM `violations`");
    while ($row = $rows->fetch(PDO::FETCH_ASSOC)) {
        $cols = array_map(fn($c) => "`{$c}`", array_keys($row));
        $vals = array_map(fn($v) => $v === null ? "NULL" : $conn->quote($v), array_values($row));
        echo "INSERT INTO `violations` (" . implode(",", $cols) . ") VALUES (" . implode(",", $vals) . ");\n";
    }
    echo "\nSET FOREIGN_KEY_CHECKS = 1;\n-- End of backup\n";
    return ob_get_clean();
}

/**
 * Autosave: write a timestamped .sql snapshot to backups/auto/ so the admin
 * never has to remember to tap "Backup". Keeps only the most recent $keep
 * files so the folder doesn't grow forever. Best-effort (never throws).
 */
function vts_auto_backup($conn, $tag = 'auto', $keep = 12) {
    try {
        $dir = __DIR__ . "/../backups/auto";
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
        $tag  = preg_replace('/[^a-z0-9\-]/i', '', $tag) ?: 'auto';
        $name = "auto_{$tag}_" . date('Y-m-d_His') . ".sql";
        @file_put_contents($dir . "/" . $name, vts_build_sql_dump($conn));

        // Prune old autosaves
        $files = glob($dir . "/auto_*.sql");
        if ($files && count($files) > $keep) {
            usort($files, fn($a, $b) => filemtime($a) <=> filemtime($b));
            foreach (array_slice($files, 0, count($files) - $keep) as $old) @unlink($old);
        }
        return $name;
    } catch (Throwable $e) {
        return '';
    }
}

/**
 * Auto-archive students who registered $years ago or more: export them to a
 * dated .xlsx (falls back to .csv) under backups/archived_students/, then
 * flip their account to Inactive (soft — never deletes). Best-effort, cheap
 * no-op when nobody currently qualifies. Called opportunistically from the
 * Admin/OSA dashboards so nobody has to remember to run it by hand.
 */
function vts_auto_archive_old_students($conn, $years = 6) {
    $years = (int)$years;
    try {
        $rows = $conn->query("SELECT id, student_id, fullname, email, contact_number, course, year_level, section, created_at
                               FROM users WHERE role='Student' AND status='Active'
                                 AND created_at <= NOW() - INTERVAL {$years} YEAR")
                     ->fetchAll(PDO::FETCH_ASSOC);
        if (!$rows) return 0;

        $dir = __DIR__ . "/../backups/archived_students";
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
        $headers = ['Student ID','Full Name','Email','Contact Number','Course','Year Level','Section','Registered On'];
        $out = array_map(fn($r) => [
            $r['student_id'], $r['fullname'], $r['email'], $r['contact_number'],
            $r['course'], $r['year_level'], $r['section'], $r['created_at'],
        ], $rows);

        if (class_exists('PhpOffice\PhpSpreadsheet\Spreadsheet')) {
            $fname = "archived_students_" . date('Y-m-d_His') . ".xlsx";
            $ss = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
            $sheet = $ss->getActiveSheet();
            $sheet->fromArray($headers, null, 'A1');
            $sheet->fromArray($out, null, 'A2');
            (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($ss))->save($dir . "/" . $fname);
        } else {
            $fname = "archived_students_" . date('Y-m-d_His') . ".csv";
            $fp = fopen($dir . "/" . $fname, 'w');
            fputcsv($fp, $headers);
            foreach ($out as $line) fputcsv($fp, $line);
            fclose($fp);
        }

        $ids = array_column($rows, 'id');
        $in  = implode(',', array_fill(0, count($ids), '?'));
        $conn->prepare("UPDATE users SET status='Inactive' WHERE id IN ({$in})")->execute($ids);

        foreach ($rows as $r) {
            audit_log($conn, "Auto-Archive Student", "users", $r['id'],
                "Registered {$years}+ years ago ({$r['created_at']}); exported to {$fname} and set Inactive.");
        }
        return count($rows);
    } catch (Throwable $e) { return 0; }
}

/**
 * Import an offline "violators" file (CSV or JSON) exported by the scanner
 * and record every scan via record_violation(). Shared by the Violations
 * page and the standalone import endpoint so there is ONE importer.
 * Returns ['ok'=>bool, 'error'=>string, 'results'=>['imported','skipped','lines','scanner']].
 */
/**
 * Keep a copy of every scanner file that was imported.
 *
 * Last step of the offline chain: once Records.csv has been turned into
 * database rows, the file itself is filed under backups/imported_scans/ with a
 * timestamped name, so the office can always produce the exact file a record
 * came from. Never fatal — a failed archive must not fail the import.
 */
function vts_archive_import_file($tmpPath, $origName, $imported, $skipped) {
    try {
        if (!is_file($tmpPath)) return '';
        $dir = __DIR__ . '/../backups/imported_scans';
        if (!is_dir($dir)) @mkdir($dir, 0775, true);
        if (!is_dir($dir)) return '';

        $base = preg_replace('/[^A-Za-z0-9._-]+/', '_', (string)$origName);
        if ($base === '' || $base === '.') $base = 'scans';
        $dest = $dir . '/' . date('Y-m-d_His') . '__' . $base;

        // move_uploaded_file for real uploads, copy for anything else.
        if (is_uploaded_file($tmpPath)) { @move_uploaded_file($tmpPath, $dest); }
        else                            { @copy($tmpPath, $dest); }
        if (!is_file($dest)) return '';

        @file_put_contents($dir . '/index.log',
            date('c') . "\t" . basename($dest) . "\timported=" . (int)$imported
            . "\tskipped=" . (int)$skipped . "\n", FILE_APPEND);
        return $dest;
    } catch (Throwable $e) { return ''; }
}

function import_scan_file($conn, $tmpPath, $origName, $adminId) {
    // Self-heal violation_types/violations columns before anything else touches
    // them — not every page that can trigger an import (OSA, Guard/Marshal) was
    // calling this first, which is how a live DB missing max_points crashed here.
    vts_ensure_categorization_columns($conn);
    $fname = strtolower($origName ?? '');
    $ext   = strtolower(pathinfo($fname, PATHINFO_EXTENSION));
    $data  = [];
    $list  = null;
    $rows  = null;

    if ($ext === 'xlsx') {
        // Check BEFORE ever touching PhpSpreadsheet's zip reader. On a host
        // without the ZIP extension (e.g. InfinityFree), letting IOFactory::load()
        // attempt the read anyway is what crashed the whole request — a memory-
        // exhaustion / low-level fatal inside the library that try/catch(Throwable)
        // in vts_xlsx_read_rows() cannot catch, so the page died with no response
        // ("page not working") instead of showing this message.
        if (!class_exists('ZipArchive')) {
            return ['ok'=>false, 'error'=>"This server can't open .xlsx files (ZIP support is off). Please export/save as CSV and import that instead — a CSV opens in Excel too.", 'results'=>null];
        }
        // 3MB is already a lot of scan rows; PhpSpreadsheet can use 10-20x a
        // file's size in memory, which is how a small-looking file still blows a
        // shared host's memory_limit. Fail with a clear message instead of dying.
        $size = @filesize($tmpPath);
        if ($size !== false && $size > 3 * 1024 * 1024) {
            return ['ok'=>false, 'error'=>"That .xlsx file is too large to read on this server (" . round($size / 1048576, 1) . "MB). Please export/save as CSV instead — it's lighter and imports the same data.", 'results'=>null];
        }
        $rows = vts_xlsx_read_rows($tmpPath);
        if (!is_array($rows)) {
            // Could still fail for other reasons (corrupt file, wrong format).
            return ['ok'=>false, 'error'=>"Could not read that .xlsx file. Export/save as CSV from the scanner and import that instead — a CSV opens in Excel too.", 'results'=>null];
        }
    } else {
        $raw = @file_get_contents($tmpPath);
        if ($raw === false || $raw === '') return ['ok'=>false, 'error'=>'The uploaded file was empty.', 'results'=>null];
        if (substr($raw, 0, 3) === "\xEF\xBB\xBF") $raw = substr($raw, 3);  // strip UTF-8 BOM (Excel-friendly CSV)

        // ENCRYPTED .vtsl the scanner exports at end of shift — decrypt first.
        if ($ext === 'vtsl' || vts_is_vtsl_envelope($raw)) {
            $dec = vts_scanner_decrypt($raw);
            if ($dec === null) {
                return ['ok'=>false, 'error'=>'That .vtsl file could not be decrypted. Make sure it was exported by this system\'s scanner.', 'results'=>null];
            }
            $raw = $dec;   // now plain JSON — falls through to the JSON parser below
            $ext = 'json';
        }

        // CSV by extension, otherwise try JSON.
        if ($ext !== 'csv') {
            $data = json_decode($raw, true);
            if (is_array($data)) {
                /* A STUDENT LIST is not a scan file. Both are .vtsl exported by
                   this system, so they are indistinguishable in a file picker --
                   and this importer used to accept either. With no 'scans' key it
                   fell back to treating the ENVELOPE ITSELF as the list of scans,
                   walked its handful of top-level keys (app, type, exported_at,
                   students, types), skipped every one of them for having no
                   student_id, and STILL returned ok. That is the "0 imported /
                   6 skipped / OK" with a student list sitting in the scan
                   history. Recognise it and say what it is instead. */
                $envType = strtolower(trim((string)($data['type'] ?? '')));
                $looksLikeList = ($envType === 'lists')
                    || (isset($data['students']) && !isset($data['scans']) && !isset($data['records']));
                if ($looksLikeList) {
                    $n = isset($data['students']) && is_array($data['students']) ? count($data['students']) : 0;
                    return ['ok'=>false, 'kind'=>'students', 'results'=>null,
                        'error'=>'That is a STUDENT LIST file'
                            . ($n ? ' (' . $n . ' students)' : '')
                            . ', not a scan file. It lists the students to scan AGAINST and contains no recorded violations, so there is nothing in it to add to the records. This file belongs on the scanner, under "Import file". The file to import here is the one the marshal exports at the END of a shift.'];
                }
                $list = isset($data['scans']) ? $data['scans']
                      : (isset($data['records']) ? $data['records'] : $data);
                /* An envelope from this system that carries neither key is not a
                   list of scans either -- do not walk its keys as if it were. */
                if (!isset($data['scans']) && !isset($data['records']) && isset($data['app'])) {
                    return ['ok'=>false, 'results'=>null,
                        'error'=>'That file came from this system but contains no scans. If the shift recorded nothing, there is nothing to import; otherwise re-export it from the scanner with Finish session.'];
                }
            }
        }
        if ($list === null) {
            $rows = array_map('str_getcsv', preg_split('/\r\n|\r|\n/', trim($raw)));
        }
    }
    // Row-based parser (CSV or .xlsx): header row maps columns; student_id + violation_name required.
    if ($list === null && is_array($rows) && count($rows) >= 2) {
        $head = array_map(fn($h) => strtolower(trim((string)$h)), $rows[0]);
        $idx  = fn($n) => array_search($n, $head, true);
        $ci = $idx('student_id'); if ($ci === false) $ci = $idx('sid');
        $cn = $idx('violation_name'); if ($cn === false) $cn = $idx('violation');
        $ct = $idx('violation_type'); $cs = $idx('scanner_name'); $cr = $idx('scanner_role');
        // The scanner has always written this column; the parser ignored it, so
        // the only thing identifying the marshal on import was a free-text name.
        $cx = $idx('scanner_school_id');
        $cd = $idx('violation_detail');
        $cv = $idx('severity');
        // WHEN the scan happened. Without this every row imported from a CSV
        // was filed at import time, so a whole shift landed on one timestamp,
        // the offense order followed file order instead of real order, and the
        // duplicate guard had nothing to match on.
        $ca = $idx('scanned_at');
        if ($ca === false) $ca = $idx('at');
        if ($ca === false) $ca = $idx('date_reported');
        if ($ca === false) $ca = $idx('date');
        $list = [];
        for ($i = 1; $i < count($rows); $i++) {
            $r = $rows[$i];
            if (count($r) === 1 && trim((string)$r[0]) === '') continue;
            // The scanner appends a blank line + a "SESSION" footer row; it is
            // not a scan, so skip it rather than reporting it as skipped data.
            if (strcasecmp(trim((string)($r[0] ?? '')), 'SESSION') === 0) continue;
            $list[] = [
                'student_id'     => $ci !== false ? ($r[$ci] ?? '') : '',
                'violation_name' => $cn !== false ? ($r[$cn] ?? '') : '',
                'violation_type' => $ct !== false ? ($r[$ct] ?? 0)  : 0,
                'severity'       => $cv !== false ? ($r[$cv] ?? '') : '',
                'scanner_name'   => $cs !== false ? ($r[$cs] ?? '') : '',
                'scanner_role'   => $cr !== false ? ($r[$cr] ?? '') : '',
                'scanner_school_id' => $cx !== false ? ($r[$cx] ?? '') : '',
                'violation_detail'  => $cd !== false ? ($r[$cd] ?? '') : '',
                'at'             => $ca !== false ? ($r[$ca] ?? '') : '',
            ];
        }
    }
    if (!is_array($list) || count($list) === 0) {
        return ['ok'=>false, 'error'=>"That file isn't a valid violators file (no scans found). Use a .csv, .xlsx, or .json from the scanner.", 'results'=>null];
    }

    $scannerName = trim($data['scanner_name'] ?? ($data['scanner']['name'] ?? 'Imported (offline file)'));
    $imported = 0; $skipped = 0; $lines = []; $touchedStudents = []; $importedRecords = [];

    $sevPoints = ['Minor' => 1, 'Major' => 2, 'Grave' => 3];
    foreach ($list as $s) {
        $sid    = trim(explode('|', trim((string)($s['student_id'] ?? $s['sid'] ?? '')))[0]);
        $typeId = (int)($s['violation_type'] ?? $s['typeId'] ?? 0);
        $typeNm = trim((string)($s['violation_name'] ?? ''));
        $typeSev = trim((string)($s['severity'] ?? ''));
        if (!isset($sevPoints[$typeSev])) $typeSev = 'Minor';
        /* WHOSE SCAN IS THIS?
           A file can be edited before it is handed in, so the name written
           in it is a claim, not a fact. Where the row carries a School ID we
           resolve it against the staff list and file the scan under the name
           on that record -- the same rule the live sync applies, so both
           routes in produce the same label for the same person.

           A row that does not resolve is kept but marked, never silently
           trusted: dropping it would lose a real scan the office may need,
           while presenting an unchecked name as fact is how this went wrong
           in the first place. */
        $sn    = trim((string)($s['scanner_name'] ?? '')) ?: $scannerName;
        $srole = trim((string)($s['scanner_role'] ?? ''));
        $ssid  = trim((string)($s['scanner_school_id'] ?? ''));
        /* An "Others" carries the marshal's own words. Without it the office
           gets a record that says a rule was broken and nothing about which. */
        $vdetail = trim((string)($s['violation_detail'] ?? $s['detail'] ?? ''));
        if ($ssid === '' && $srole !== '' && preg_match('/School ID\s*([A-Za-z0-9._-]+)/i', $srole, $mm)) {
            $ssid = $mm[1];                       // live-sync rows carry it inside the role string
        }
        if ($ssid !== '') {
            $marshalRec = vts_resolve_marshal($conn, $ssid, $sn);
            if ($marshalRec) {
                $sn    = (string)$marshalRec['fullname'];
                $srole = 'Marshal · School ID ' . $marshalRec['staff_id'];
            } else {
                $srole = trim($srole) !== '' ? $srole . ' · UNVERIFIED' : 'UNVERIFIED';
            }
        } elseif ($sn !== '' && $sn !== $scannerName) {
            $srole = trim($srole) !== '' ? $srole . ' · UNVERIFIED' : 'UNVERIFIED';
        }
        if ($srole !== '') $sn = trim($sn . ' (' . $srole . ')');
        if ($sid === '' || ($typeId === 0 && $typeNm === '')) { $skipped++; continue; }

        $st = $conn->prepare("SELECT id, fullname FROM users WHERE student_id = :sid AND role='Student' AND status='Active' LIMIT 1");
        $st->execute([':sid' => $sid]);
        $student = $st->fetch(PDO::FETCH_ASSOC);

        $type = null;
        if ($typeId > 0) {
            $vt = $conn->prepare("SELECT violation_name, severity, max_points FROM violation_types WHERE id=:id");
            $vt->execute([':id' => $typeId]); $type = $vt->fetch(PDO::FETCH_ASSOC);
        }
        if (!$type && $typeNm !== '') {
            $vt = $conn->prepare("SELECT violation_name, severity, max_points FROM violation_types WHERE violation_name=:n LIMIT 1");
            $vt->execute([':n' => $typeNm]); $type = $vt->fetch(PDO::FETCH_ASSOC);
        }
        // Unknown violation type (e.g. the scanner's built-in list hasn't been
        // synced into violation_types yet) — auto-create it from the severity
        // the scanner already recorded, instead of dropping the scan.
        if (!$type && $typeNm !== '') {
            try {
                $ins = $conn->prepare("INSERT INTO violation_types (violation_name, severity, max_points) VALUES (:n,:s,:p)");
                $ins->execute([':n' => $typeNm, ':s' => $typeSev, ':p' => $sevPoints[$typeSev]]);
                $type = ['violation_name' => $typeNm, 'severity' => $typeSev, 'max_points' => $sevPoints[$typeSev]];
            } catch (Throwable $e) { /* fall through to skip below */ }
        }
        if (!$student) { $skipped++; $lines[] = "Skipped — no active student for that ID."; continue; }
        if (!$type)    { $skipped++; $lines[] = "Skipped — unknown violation type."; continue; }

        /* IMPORT ONCE ONLY.
           A marshal's file often gets imported twice (re-sent, or both a
           Guard/Marshal and the OSA import the same day's export). Without this
           the same scan lands again as a brand-new offense and inflates the
           student's ladder. Match on the scan's own timestamp when the file
           carries one; otherwise fall back to "same student + same violation
           on the same day". */
        $scanAt = trim((string)($s['at'] ?? $s['scanned_at'] ?? $s['date'] ?? $s['date_reported'] ?? ''));
        $ts = $scanAt !== '' ? strtotime($scanAt) : false;
        if ($ts !== false) {
            $dup = $conn->prepare("SELECT id FROM violations
                                   WHERE student_id = :s AND violation = :v
                                     AND ABS(TIMESTAMPDIFF(SECOND, date_reported, :t)) <= 120 LIMIT 1");
            $dup->execute([':s' => (int)$student['id'], ':v' => $type['violation_name'],
                           ':t' => date('Y-m-d H:i:s', $ts)]);
        } else {
            $dup = $conn->prepare("SELECT id FROM violations
                                   WHERE student_id = :s AND violation = :v
                                     AND DATE(date_reported) = CURDATE() LIMIT 1");
            $dup->execute([':s' => (int)$student['id'], ':v' => $type['violation_name']]);
        }
        if ($dup->fetchColumn()) {
            $skipped++;
            $lines[] = "• Already imported — " . $student['fullname'] . " (" . $sid . ") — " . $type['violation_name'] . ".";
            continue;
        }

        /* ONE-MAJOR RULE — a scanner file can carry a Major for a student who
           already has one (the marshal on the gate cannot see their history).
           Report it as skipped with the reason instead of filing a second. */
        [$majorBlocked, $majorWhy] = major_violation_blocked($conn, (int)$student['id'], $type['severity']);
        if ($majorBlocked) {
            $skipped++;
            $lines[] = "• Skipped — " . $student['fullname'] . " (" . $sid . ") — "
                     . $type['violation_name'] . ": already has a Major offense on record.";
            continue;
        }

        /* THE PROOF PHOTO.
           Two shapes reach this point and both are handled here so the live
           and the hand-carried route produce the same record:

             `photo`    a base64 frame straight off the scanner's camera.
                        This is what an offline .vtsl carries — the file was
                        written on a phone with nowhere to put an image.
             `evidence` a filename already on disk, used by the live path,
                        where api/scan_submit.php has saved the frame before
                        handing the row over.

           A photo that fails its checks is dropped rather than failing the
           row: a violation that really happened must not be lost because
           its picture was corrupted in transit. */
        $evidenceName = null;
        if (!empty($s['photo'])) {
            $evidenceName = vts_save_evidence_base64((string)$s['photo']);
        } elseif (!empty($s['evidence'])) {
            $candidate = basename((string)$s['evidence']);   // never a path
            if (is_file(__DIR__ . '/../uploads/evidence/' . $candidate)) $evidenceName = $candidate;
        }

        $vid = record_violation($conn, (int)$student['id'], $type['violation_name'], $type['severity'],
            $adminId, $sn, ['description'    => $vdetail !== ''
                                                  ? $vdetail
                                                  : 'Imported from offline scanner file',
                            'evidence'       => $evidenceName,
                            // File it at the real scan time, not the import time.
                            'date_reported'  => ($ts !== false ? date('Y-m-d H:i:s', $ts) : null),
                            // No per-row external email during import — it blocks
                            // on the mail API and can stall the whole request.
                            'email'          => false]);
        if ($vid > 0) {
            $off = $conn->prepare("SELECT offense FROM violations WHERE id=:id");
            $off->execute([':id' => $vid]); $offense = $off->fetchColumn() ?: 'Recorded';
            $imported++;
            $touchedStudents[(int)$student['id']] = true;
            $importedRecords[] = [
                'student_id' => (int)$student['id'],
                'violation_id' => $vid,
                'violation_name' => $type['violation_name'],
                'severity' => $type['severity'],
            ];
            $lines[] = "✓ " . $student['fullname'] . " (" . $sid . ") — " . $type['violation_name'] . " (" . offense_display($offense) . ").";
        } else {
            // Say WHY. "could not record one row" gave the office nothing to
            // act on when a whole file failed for one repeatable reason.
            $skipped++;
            $reason = vts_last_violation_error();
            $lines[] = "• Skipped — " . $student['fullname'] . " (" . $sid . ") — "
                     . $type['violation_name'] . ($reason !== '' ? ": " . $reason : ": could not be recorded.");
        }
    }
    if ($imported > 0) {
        // Imported rows may pre-date existing ones, so re-derive the ladder —
        // but ONLY for the students THIS file actually touched. Re-numbering
        // every student in the whole system on every single import was
        // unnecessary synchronous DB work that could time out a request on
        // shared hosting (the exact cause of a blank/failed page after import).
        try {
            foreach (array_keys($touchedStudents) as $sidTouched) {
                vts_renumber_offenses($conn, $sidTouched);
            }
        } catch (Throwable $e) { error_log('Renumber after import failed: ' . $e->getMessage()); }
        // Send the same student Gmail update used by live scans, after the
        // full import has been renumbered so the email shows the final count.
        foreach ($importedRecords as $record) {
            try {
                $activeCount = active_violation_count($conn, $record['student_id']);
                vts_notify_student_of_violation($conn, $record['student_id'],
                    $record['violation_name'], $record['severity'], $activeCount, $record['violation_id']);
            } catch (Throwable $e) {
                error_log('Imported student notification failed: ' . $e->getMessage());
            }
        }
        try {
            vts_auto_backup($conn, 'import');
        } catch (Throwable $e) { error_log('Auto-backup after import failed: ' . $e->getMessage()); }
    }
    // Keep the original file the marshal handed over. If a record is ever
    // queried ("that scan isn't mine"), the office can produce the exact file
    // it came from instead of relying on memory.
    vts_archive_import_file($tmpPath, $origName, $imported, $skipped);
    return ['ok'=>true, 'error'=>'', 'results'=>['imported'=>$imported,'skipped'=>$skipped,'lines'=>$lines,'scanner'=>$scannerName]];
}

/**
 * Make sure import_logs exists AND carries the `source` column.
 * Split out of log_import() because the Violations page reads the table
 * directly and has to know the column is there before SELECTing it.
 */
function vts_ensure_import_log_table($conn): void {
    static $done = false;
    if ($done) return;
    try {
        $conn->exec("CREATE TABLE IF NOT EXISTS import_logs (
            id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            filename       VARCHAR(255) NULL,
            imported_by    INT UNSIGNED NULL,
            scanner_name   VARCHAR(150) NULL,
            source         VARCHAR(10)  NOT NULL DEFAULT 'offline',
            imported_count INT UNSIGNED NOT NULL DEFAULT 0,
            skipped_count  INT UNSIGNED NOT NULL DEFAULT 0,
            ok             TINYINT(1) NOT NULL DEFAULT 1,
            error_message  VARCHAR(255) NULL,
            details        TEXT NULL,
            created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        // Tables created before this change are missing the two new columns.
        $cols = $conn->query("SHOW COLUMNS FROM import_logs")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('source', $cols, true)) {
            $conn->exec("ALTER TABLE import_logs ADD COLUMN source VARCHAR(10) NOT NULL DEFAULT 'offline' AFTER scanner_name");
        }
        if (!in_array('updated_at', $cols, true)) {
            $conn->exec("ALTER TABLE import_logs ADD COLUMN updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP");
        }
        // Per-row outcome lines, so the history can answer WHY rows were
        // skipped instead of only how many. Older tables predate this.
        if (!in_array('details', $cols, true)) {
            $conn->exec("ALTER TABLE import_logs ADD COLUMN details TEXT NULL AFTER error_message");
        }
        $done = true;
    } catch (Throwable $e) { /* best-effort — the panel just stays empty */ }
}

/**
 * Record one row in the scan-import history (bootstraps its own table).
 * Call right after import_scan_file() returns, from every entry point that
 * uses it, so the office can see what came in, how, and who scanned it.
 *
 * $source is how the scans reached the server:
 *   'offline' — a .vtsl/.csv/.xlsx file handed over and imported by hand
 *   'online'  — the phone scanner synced them live over Wi-Fi
 *
 * A live sync posts ONE record per scan, so logging each as its own row
 * would bury the file imports under hundreds of one-line entries. An
 * online sync therefore rolls up into a single running row per scanner
 * per day, counting up as the shift goes on.
 */
function log_import($conn, $adminId, $origName, array $result, string $source = 'offline') {
    $source = $source === 'online' ? 'online' : 'offline';
    try {
        vts_ensure_import_log_table($conn);
        $r  = $result['results'] ?? [];
        $sn = $r['scanner'] ?? null;

        if ($source === 'online') {
            $roll = $conn->prepare("SELECT id FROM import_logs
                                    WHERE source = 'online'
                                      AND COALESCE(scanner_name,'') = COALESCE(:sn,'')
                                      AND DATE(created_at) = CURDATE()
                                    ORDER BY id DESC LIMIT 1");
            $roll->execute([':sn' => $sn]);
            if ($rollId = $roll->fetchColumn()) {
                $conn->prepare("UPDATE import_logs
                                   SET imported_count = imported_count + :ic,
                                       skipped_count  = skipped_count  + :sc,
                                       ok             = :ok,
                                       error_message  = :err
                                 WHERE id = :id")
                     ->execute([
                        ':ic'  => (int)($r['imported'] ?? 0),
                        ':sc'  => (int)($r['skipped'] ?? 0),
                        ':ok'  => !empty($result['ok']) ? 1 : 0,
                        ':err' => $result['error'] ?? null,
                        ':id'  => (int)$rollId,
                     ]);
                return;
            }
        }

        // The per-row outcome lines ("Skipped -- no active student for that ID"),
        // kept so the history can say WHY, not just how many. Capped so one
        // huge import can't bloat the row.
        $details = null;
        if (!empty($r['lines']) && is_array($r['lines'])) {
            $details = mb_substr(implode("
", $r['lines']), 0, 20000);
        }

        /* ONE ROW PER IMPORT. The offline path always INSERTed, so a
           double-tapped Import button -- or a browser re-POSTing the form on
           refresh -- filed the same file twice, with identical counts, and the
           history showed the same .vtsl listed twice as if it had been
           imported on two separate occasions. A repeat of the SAME file by the
           SAME admin inside a minute is that double-submit, not a second
           import, so it updates the row it already wrote. */
        if ($source !== 'online' && ($origName ?: '') !== '') {
            $dup = $conn->prepare("SELECT id FROM import_logs
                                    WHERE source <> 'online'
                                      AND filename = :f
                                      AND COALESCE(imported_by,0) = COALESCE(:u,0)
                                      AND created_at > DATE_SUB(NOW(), INTERVAL 60 SECOND)
                                    ORDER BY id DESC LIMIT 1");
            $dup->execute([':f' => $origName, ':u' => $adminId ?: null]);
            if ($dupId = $dup->fetchColumn()) {
                $conn->prepare("UPDATE import_logs
                                   SET imported_count = :ic, skipped_count = :sc,
                                       ok = :ok, error_message = :err, details = :det
                                 WHERE id = :id")
                     ->execute([
                        ':ic'  => (int)($r['imported'] ?? 0),
                        ':sc'  => (int)($r['skipped'] ?? 0),
                        ':ok'  => !empty($result['ok']) ? 1 : 0,
                        ':err' => $result['error'] ?? null,
                        ':det' => $details,
                        ':id'  => (int)$dupId,
                     ]);
                return;
            }
        }

        $ins = $conn->prepare("INSERT INTO import_logs
            (filename, imported_by, scanner_name, source, imported_count, skipped_count, ok, error_message, details)
            VALUES (:f, :u, :sn, :src, :ic, :sc, :ok, :err, :det)");
        $ins->execute([
            ':f'   => $source === 'online' ? null : ($origName ?: null),
            ':u'   => $adminId ?: null,
            ':sn'  => $sn,
            ':src' => $source,
            ':ic'  => (int)($r['imported'] ?? 0),
            ':sc'  => (int)($r['skipped'] ?? 0),
            ':ok'  => !empty($result['ok']) ? 1 : 0,
            ':err' => $result['error'] ?? null,
            ':det' => $details,
        ]);
    } catch (Throwable $e) { /* logging is best-effort */ }
}

/**
 * Wipe the file-import history (the "Recent file imports" panel). Clearing the
 * log never touches the imported violations themselves — it only empties the
 * list of past import events. Returns the number of rows removed.
 */
function clear_import_logs($conn): int {
    try {
        return (int)$conn->exec("DELETE FROM import_logs");
    } catch (Throwable $e) {
        return 0; // table may not exist yet — nothing to clear
    }
}

/* =====================================================================
   BRUTE-FORCE / BOT PROTECTION for the login form.
   Records every failed attempt (per username AND per IP) and locks out
   after too many. Stops password-guessing bots dead.
   ===================================================================== */
function vts_login_table($conn) {
    static $done = false;
    if ($done) return;
    try {
        $conn->exec("CREATE TABLE IF NOT EXISTS login_attempts (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            username VARCHAR(100) NULL,
            ip VARCHAR(45) NOT NULL,
            success TINYINT(1) NOT NULL DEFAULT 0,
            attempted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_la_ip (ip, attempted_at),
            INDEX idx_la_user (username, attempted_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $done = true;
    } catch (Throwable $e) {}
}

function vts_client_ip() {
    return substr($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0', 0, 45);
}

/**
 * Seconds the client must wait, or 0 if they may try now.
 *
 * LOCKOUT IS OFF by design: staff (Admin/OSA/OSA Staff/Guard) mistype passwords
 * on a shared gate phone all the time, and being frozen out for 15 minutes
 * stopped real work at the gate. Every attempt is STILL recorded in
 * login_attempts, so the audit trail for repeated failures is intact — it just
 * no longer blocks the person at the keyboard.
 *
 * To switch it back on, set VTS_LOGIN_LOCKOUT to true in config/database.php
 * (or define it anywhere before this runs).
 */
function login_lock_seconds($conn, $username) {
    if (!defined('VTS_LOGIN_LOCKOUT') || VTS_LOGIN_LOCKOUT !== true) {
        return 0;                 // never lock anyone out
    }
    vts_login_table($conn);
    try {
        $ip = vts_client_ip();
        $q = $conn->prepare("SELECT COUNT(*) FROM login_attempts
             WHERE success=0 AND username=:u AND attempted_at > NOW() - INTERVAL 15 MINUTE");
        $q->execute([':u' => $username]);
        $byUser = (int)$q->fetchColumn();

        $q2 = $conn->prepare("SELECT COUNT(*) FROM login_attempts
              WHERE success=0 AND ip=:ip AND attempted_at > NOW() - INTERVAL 15 MINUTE");
        $q2->execute([':ip' => $ip]);
        $byIp = (int)$q2->fetchColumn();

        if ($byUser >= 5 || $byIp >= 15) {
            $q3 = $conn->prepare("SELECT TIMESTAMPDIFF(SECOND, NOW(), MAX(attempted_at) + INTERVAL 15 MINUTE)
                  FROM login_attempts WHERE success=0 AND (username=:u OR ip=:ip)");
            $q3->execute([':u' => $username, ':ip' => $ip]);
            return max(0, (int)$q3->fetchColumn());
        }
    } catch (Throwable $e) {}
    return 0;
}

function login_record_attempt($conn, $username, $success) {
    vts_login_table($conn);
    try {
        $conn->prepare("INSERT INTO login_attempts (username, ip, success) VALUES (:u,:ip,:s)")
             ->execute([':u' => mb_substr($username, 0, 100), ':ip' => vts_client_ip(), ':s' => $success ? 1 : 0]);
        if ($success) {
            $conn->prepare("DELETE FROM login_attempts WHERE username=:u AND success=0")->execute([':u' => $username]);
        }
        $conn->exec("DELETE FROM login_attempts WHERE attempted_at < NOW() - INTERVAL 1 DAY");
    } catch (Throwable $e) {}
}

/* =====================================================================
   AUDIT LOG — records WHO did WHAT, to WHICH record, and WHEN.
   scan_logs only covered scans. This covers admin/OSA/OSA Staff actions:
   deleting or editing a violation, adding a user, importing a roster, etc.
   Call it right after any action that changes data.
   ===================================================================== */
function audit_log($conn, $action, $target = null, $targetId = null, $details = null) {
    try {
        $conn->prepare("INSERT INTO audit_logs
                (user_id, user_name, role, action, target, target_id, details, ip)
                VALUES (:uid,:un,:r,:a,:t,:tid,:d,:ip)")
             ->execute([
                ':uid' => $_SESSION['user_id']  ?? null,
                ':un'  => $_SESSION['fullname'] ?? 'System',
                ':r'   => $_SESSION['role']     ?? null,
                ':a'   => mb_substr($action, 0, 60),
                ':t'   => $target   !== null ? mb_substr($target, 0, 60)  : null,
                ':tid' => $targetId !== null ? mb_substr((string)$targetId, 0, 60) : null,
                ':d'   => $details  !== null ? mb_substr($details, 0, 255) : null,
                ':ip'  => substr($_SERVER['REMOTE_ADDR'] ?? '', 0, 45),
             ]);
    } catch (Throwable $e) { /* logging must never break the app */ }
}

/* =====================================================================
   SANCTION LOOKUP — what actually happens for this severity + offense.
   The system counted points but never stated the penalty.
   ===================================================================== */
function get_sanction($conn, $severity, $offense) {
    try {
        $q = $conn->prepare("SELECT sanction, description FROM sanctions
                             WHERE severity = :s AND offense = :o LIMIT 1");
        $q->execute([':s' => $severity, ':o' => $offense]);
        $r = $q->fetch(PDO::FETCH_ASSOC);
        return $r ?: null;
    } catch (Throwable $e) { return null; }
}

/* =====================================================================
   RETURN-TO-WHERE-YOU-WERE
   A row action (delete / clear / edit) used to redirect to a bare
   "violations.php", which falls back to the DEFAULT view — the Official
   Sheet — so deleting a row while in "Records & Actions" silently threw
   the user back to the other tab with every filter lost. Every row action
   now carries a `return` value holding the exact page it was fired from,
   and these helpers hand the user straight back to it.
   ===================================================================== */

/* Read a caller-supplied return target and make sure it is safe to use.
   Only a plain relative path to a .php page in the SAME folder is accepted
   (optionally with a query string) — no scheme, no host, no traversal —
   so this can never be turned into an open redirect. */
function vts_return_url(string $fallback = 'violations.php'): string {
    $raw = trim((string)($_POST['return'] ?? $_GET['return'] ?? ''));
    if ($raw === '') return $fallback;
    if (preg_match('#^[a-z][a-z0-9+.-]*:#i', $raw))                 return $fallback;  // http:, javascript:, data:
    if (str_starts_with($raw, '/') || str_starts_with($raw, '\\'))  return $fallback;
    if (str_contains($raw, '..') || str_contains($raw, '\\'))       return $fallback;
    $parts = explode('?', $raw, 2);
    $path  = $parts[0];
    $query = $parts[1] ?? '';
    if (!preg_match('/^[A-Za-z0-9_-]+\.php$/', $path))              return $fallback;
    // Strip any banner already baked in — a fresh one gets appended.
    if ($query !== '') {
        parse_str($query, $q);
        unset($q['success'], $q['error'], $q['return']);
        $query = http_build_query($q);
    }
    return $path . ($query !== '' ? '?' . $query : '');
}

/* Redirect back to that page with a success/error banner appended. */
function vts_redirect_back(string $fallback, string $type, string $message): void {
    $url = vts_return_url($fallback);
    $sep = str_contains($url, '?') ? '&' : '?';
    header('Location: ' . $url . $sep . ($type === 'error' ? 'error=' : 'success=') . urlencode($message));
    exit();
}

/* The current page (view tab + every active filter) as a relative URL. */
function vts_current_page_url(): string {
    $self  = basename((string)($_SERVER['SCRIPT_NAME'] ?? 'violations.php'));
    $query = $_GET;
    unset($query['success'], $query['error'], $query['return']);
    return $self . ($query ? '?' . http_build_query($query) : '');
}

/* Hidden form field carrying that URL into a POST action. */
function vts_return_field(): string {
    return '<input type="hidden" name="return" value="'
         . htmlspecialchars(vts_current_page_url(), ENT_QUOTES, 'UTF-8') . '">';
}

/* Same value, URL-encoded, for appending to a link (edit, view, …). */
function vts_return_param(): string {
    return rawurlencode(vts_current_page_url());
}

/* =====================================================================
   DEPARTMENT / COURSE IDENTITY — one icon + colour per department, so a
   sheet is recognisable at a glance instead of every heading looking the
   same. Matching is on whatever the school happens to call it (short code
   "CIT", full "Department of Information Technology", or a course code
   like "BSIT"), so renaming a department in the DB doesn't lose the icon.
   ===================================================================== */
function vts_dept_identity(?string $name): array {
    $n = strtolower(trim((string)$name));
    $has = function (...$needles) use ($n) {
        foreach ($needles as $x) { if ($x !== '' && str_contains($n, $x)) return true; }
        return false;
    };

    if ($has('information technology', 'computer', 'bsit', 'bscs', 'cit'))
        return ['icon' => 'fa-laptop-code',     'color' => '#1d6fd0', 'tint' => 'rgba(29,111,208,.12)',  'label' => 'Information Technology'];
    if ($has('business', 'accountancy', 'accounting', 'bsba', 'bsa', 'cba'))
        return ['icon' => 'fa-briefcase',       'color' => '#0f8a6a', 'tint' => 'rgba(15,138,106,.12)',  'label' => 'Business Administration'];
    if ($has('education', 'beed', 'bsed', 'coe', 'teacher'))
        return ['icon' => 'fa-chalkboard-user', 'color' => '#b8791b', 'tint' => 'rgba(184,121,27,.14)',  'label' => 'Education'];
    if ($has('criminology', 'criminal', 'bscrim', 'ccj', 'crim'))
        return ['icon' => 'fa-shield-halved',   'color' => '#9c2f3c', 'tint' => 'rgba(156,47,60,.12)',   'label' => 'Criminology'];
    if ($has('nursing', 'health', 'bsn'))
        return ['icon' => 'fa-user-nurse',      'color' => '#0e7d8c', 'tint' => 'rgba(14,125,140,.12)',  'label' => 'Health Sciences'];
    if ($has('engineering', 'bsce', 'bsee'))
        return ['icon' => 'fa-gears',           'color' => '#5b4bb5', 'tint' => 'rgba(91,75,181,.12)',   'label' => 'Engineering'];
    if ($has('hospitality', 'tourism', 'bshm', 'bstm'))
        return ['icon' => 'fa-utensils',        'color' => '#c2601f', 'tint' => 'rgba(194,96,31,.12)',   'label' => 'Hospitality & Tourism'];

    return ['icon' => 'fa-building-columns', 'color' => '#1a3a6b', 'tint' => 'rgba(26,58,107,.10)', 'label' => 'All Departments'];
}

/* Per-course icon — finer than the department one where a department holds
   more than one course (BSIT vs BSCS, BEED vs BSED, …). */
function vts_course_identity(?string $code): array {
    $c = strtoupper(trim((string)$code));
    $exact = [
        'BSIT'   => ['fa-laptop-code',      '#1d6fd0'],
        'BSCS'   => ['fa-code',             '#2456a8'],
        'BSBA'   => ['fa-briefcase',        '#0f8a6a'],
        'BSA'    => ['fa-calculator',       '#127a5e'],
        'BEED'   => ['fa-book-open-reader', '#b8791b'],
        'BSED'   => ['fa-chalkboard-user',  '#a86c14'],
        'BSCRIM' => ['fa-shield-halved',    '#9c2f3c'],
        'BSN'    => ['fa-user-nurse',       '#0e7d8c'],
    ];
    $d = vts_dept_identity($code);
    if (isset($exact[$c])) {
        return ['icon' => $exact[$c][0], 'color' => $exact[$c][1], 'tint' => $d['tint'], 'label' => $c];
    }
    $d['label'] = $c !== '' ? $c : $d['label'];
    return $d;
}

/* A small course chip: icon + code, tinted with the department's colour. */
function vts_course_chip(?string $code, bool $strong = false): string {
    $code = trim((string)$code);
    if ($code === '') return '<span class="u-faint">-</span>';
    $id = vts_course_identity($code);
    return '<span class="dept-chip' . ($strong ? ' strong' : '') . '"'
         . ' style="--chip-color:' . $id['color'] . ';--chip-tint:' . $id['tint'] . ';"'
         . ' title="' . htmlspecialchars($id['label'], ENT_QUOTES, 'UTF-8') . '">'
         . '<i class="fas ' . $id['icon'] . '"></i>' . htmlspecialchars($code) . '</span>';
}

/* The same thing for a department name (used in the page heading). */
function vts_dept_chip(?string $name): string {
    $id = vts_dept_identity($name);
    $txt = trim((string)$name) !== '' ? $name : $id['label'];
    return '<span class="dept-chip strong" style="--chip-color:' . $id['color'] . ';--chip-tint:' . $id['tint'] . ';">'
         . '<i class="fas ' . $id['icon'] . '"></i>' . htmlspecialchars($txt) . '</span>';
}

/* How a batch of scans reached the server, as a pill. Offline = a file the
   marshal handed over; Online = the phone scanner synced it live. */
function vts_import_source_pill(?string $source): string {
    if (strtolower(trim((string)$source)) === 'online') {
        return '<span class="src-pill src-online" title="Synced live from the phone scanner over Wi-Fi">'
             . '<i class="fas fa-cloud-arrow-up"></i>Online sync</span>';
    }
    return '<span class="src-pill src-offline" title="Imported from a scanner file (.vtsl / .csv / .xlsx)">'
         . '<i class="fas fa-file-import"></i>Offline file</span>';
}

/* Outcome of one import, told honestly.
   The history used to print a green "OK" whenever the FILE had been readable,
   so an import that added nothing at all -- a student list fed to the scan
   importer, or a file whose every row was a duplicate -- was reported as a
   success and the 0 in the Imported column was the only clue. The status now
   describes what actually happened to the records.
   Returns [label, css-class, explanation]. */
function vts_import_status($log): array {
    $ok       = (int)($log['ok'] ?? 0) === 1;
    $imported = (int)($log['imported_count'] ?? 0);
    $skipped  = (int)($log['skipped_count'] ?? 0);

    if (!$ok) {
        return ['Failed', 'atrisk',
                trim((string)($log['error_message'] ?? '')) ?: 'The file could not be imported.'];
    }
    if ($imported === 0 && $skipped === 0) {
        return ['Nothing to import', 'warning', 'The file was read successfully but contained no scan rows.'];
    }
    if ($imported === 0) {
        return ['Nothing imported', 'atrisk',
                'Every row was skipped (' . $skipped . '), so no violation was added. Open Details for the reason on each row.'];
    }
    if ($skipped > 0) {
        return ['With warnings', 'warning',
                $imported . ' imported, but ' . $skipped . ' row(s) were skipped. Open Details for the reason on each row.'];
    }
    return ['Imported', 'resolved', 'All ' . $imported . ' row(s) imported with nothing skipped.'];
}

/* The same call, rendered as the pill the history table shows. */
function vts_import_status_pill($log): string {
    [$label, $cls, $why] = vts_import_status($log);
    return '<span class="pill ' . $cls . '" title="' . htmlspecialchars($why, ENT_QUOTES, 'UTF-8') . '">'
         . htmlspecialchars($label) . '</span>';
}

/* The marshal who did the scanning, masked behind a reveal button.
   Names start HIDDEN: the import panel is on screen whenever the Violations
   page is open, often with students or other staff able to see it, and who
   was on duty is not something that needs to be readable at a glance. */
function vts_masked_name(?string $name, string $label = 'scanner name'): string {
    $name = trim((string)$name);
    if ($name === '' || $name === '-') return '<span class="u-faint">—</span>';
    $safe = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
    return '<span class="masked-name-wrap">'
         . '<span class="masked-name" data-full="' . $safe . '" data-hidden="1">'
         . str_repeat('•', min(mb_strlen($name), 14)) . '</span>'
         . '<button type="button" class="mask-toggle btn-outline btn-sm"'
         . ' aria-label="Show or hide the ' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '"'
         . ' title="Show / hide — kept hidden for confidentiality">'
         . '<i class="fas fa-eye"></i></button></span>';
}


/* =====================================================================
   ACTIVE FILTER CHIPS
   One statement of what you are looking at, in the filter bar where you
   would look for it, with each filter removable. Replaces a prose
   paragraph that said the same thing and could not be acted on.

   $filters : key => [icon, readable label]
   $base    : the page to link back to (keeps the view tab)
   ===================================================================== */
/* An empty result that accounts for itself.
   "No records match these filters." never said WHICH filters, so the usual
   next move was to re-read the filter bar and guess -- and with a department
    or a date range set several screens up, the common case was a filter the
    reader had forgotten was on. Name every active one; the toolbar owns reset.
   $filters is the array vts_violation_filter_labels() builds. */
function vts_empty_filter_html(array $filters, string $page, array $query, array $preserve = ['view']): string {
    if (!$filters) {
        return '<b class="u-muted">No violation records yet.</b>'
             . '<div style="font-size:.84rem;margin-top:5px;">Nothing has been recorded, and no filter is hiding anything.</div>';
    }
    $names = [];
    foreach ($filters as [$icon, $label]) $names[] = htmlspecialchars($label);

    return '<b class="u-muted">No records match these filters.</b>'
         . '<div style="font-size:.84rem;margin-top:6px;color:#7c8aa5;">Filtering by: <b>'
            . implode('</b>, <b>', $names) . '</b></div>';
}

/* $preserve names the query keys that are SCOPE rather than filters -- the
   tab you are on, the department you are looking at. "Clear all" keeps them.
   Throwing the department away turned "clear my filters" into "leave this
   department", which is not what the words say and not what was wanted. */
function vts_filter_chips(array $filters, string $page, array $query, array $preserve = ['view']): string {
    if (!$filters) return '';
    $keep = $query;
    unset($keep['success'], $keep['error'], $keep['return']);

    $out = '<div class="filter-chips"><span class="fc-label">Showing</span>';
    foreach ($filters as $key => [$icon, $label]) {
        $without = $keep;
        unset($without[$key]);
        // "when" has no empty state of its own — dropping it means all dates.
        $qs = http_build_query($without);
        $out .= '<span class="fc"><i class="fas ' . $icon . '"></i>'
              . '<span>' . htmlspecialchars($label) . '</span>'
              . '<a href="' . htmlspecialchars($page . ($qs ? '?' . $qs : '')) . '"'
              . ' title="Remove this filter" aria-label="Remove filter: ' . htmlspecialchars($label, ENT_QUOTES) . '">'
              . '<i class="fas fa-xmark"></i></a></span>';
    }
    // Clearing everything still keeps the tab you are on AND the department
    // you are in -- both are where you ARE, not something you filtered by.
    $clear = array_intersect_key($keep, array_flip($preserve));
    $cqs   = http_build_query($clear);
    $out .= '<a class="fc-clear" href="' . htmlspecialchars($page . ($cqs ? '?' . $cqs : '')) . '">Clear all</a>';
    return $out . '</div>';
}

/* Build the readable filter list both violations pages show. Kept here so
   Admin and OSA Staff can never drift apart on wording. */
function vts_violation_filter_labels(array $v, string $departmentHeading): array {
    $chips = [];
    if (($v['college_id'] ?? '') !== '') $chips['college_id'] = ['fa-building-columns', $departmentHeading];
    if (($v['course'] ?? '') !== '')     $chips['course']     = ['fa-graduation-cap',   $v['course']];
    if (($v['year_level'] ?? '') !== '') $chips['year_level'] = ['fa-layer-group',      $v['year_level']];
    if (($v['section'] ?? '') !== '')    $chips['section']    = ['fa-users',            'Set ' . $v['section']];
    if (($v['violation'] ?? '') !== '')  $chips['violation']  = ['fa-triangle-exclamation', $v['violation']];
    if (($v['search'] ?? '') !== '')     $chips['search']     = ['fa-magnifying-glass', '"' . $v['search'] . '"'];

    $from = $v['from'] ?? ''; $to = $v['to'] ?? ''; $when = $v['when'] ?? 'all';
    if ($from !== '' && $to !== '') {
        $chips['from'] = ['fa-calendar-days', vts_date($from) . ' – ' . vts_date($to)];
    } elseif ($when !== 'all') {
        $names = ['today' => 'Today', 'yesterday' => 'Yesterday', '7days' => 'Last 7 days', 'month' => 'This month'];
        $chips['when'] = ['fa-calendar-days', $names[$when] ?? $when];
    }
    return $chips;
}

/**
 * A session token expiring mid-task used to end in `die("Invalid CSRF token.")`
 * — a white page, a phrase meaning nothing to the person reading it, and no
 * way back except the browser's Back button, which most people don't think to
 * use after an error page. It reads as "the app broke and ate my work".
 *
 * Nothing is actually lost: the form is still in the browser's history with
 * every field intact. So say what happened in plain words and put the button
 * that recovers the work right there.
 */
function vts_csrf_fail(string $what = 'save that'): void {
    if (!headers_sent()) {
        // 403, not the non-standard 419: Apache turns an unrecognised code
        // into a 500, which tells the browser the SERVER broke rather than
        // that the token expired -- and some proxies replace a 500 body with
        // their own error page, losing this one entirely.
        http_response_code(403);
        header('Content-Type: text/html; charset=utf-8');
    }
    $what = htmlspecialchars($what, ENT_QUOTES, 'UTF-8');
    echo <<<HTML
<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Session expired</title>
<style>
 body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;
   background:#f4f7fc;font-family:system-ui,sans-serif;
   color:#33415c;padding:24px;}
 .card{background:#fff;border:1px solid #e3e9f3;border-radius:16px;padding:30px 32px;
   max-width:480px;box-shadow:0 12px 34px rgba(16,34,63,.12);}
 .ic{width:46px;height:46px;border-radius:12px;background:#fff4e0;color:#b8791b;
   display:flex;align-items:center;justify-content:center;font-size:22px;margin-bottom:16px;}
 h1{font-size:1.2rem;margin:0 0 8px;color:#1a3a6b;}
 p{font-size:.9rem;line-height:1.6;margin:0 0 8px;}
 .ok{background:#eef6f0;border:1px solid #cfe6d8;color:#1b6b45;border-radius:9px;
   padding:10px 12px;font-size:.85rem;margin:14px 0 20px;}
 .row{display:flex;gap:10px;flex-wrap:wrap;}
 a,button{display:inline-flex;align-items:center;justify-content:center;gap:8px;
   font:inherit;font-size:.88rem;font-weight:700;padding:11px 18px;border-radius:10px;
   cursor:pointer;text-decoration:none;border:1px solid #d9e1ee;background:#fff;color:#33415c;}
 .primary{background:#1a3a6b;border-color:#1a3a6b;color:#fff;}
</style></head><body>
<div class="card">
  <div class="ic">&#9888;</div>
  <h1>Your session timed out</h1>
  <p>The page sat open long enough for its security token to expire, so we couldn't {$what}.</p>
  <div class="ok"><b>Nothing you typed is lost.</b> Go back and everything will still be in the form &mdash; submit it again and it will save.</div>
  <div class="row">
    <button class="primary" onclick="history.back()">&#8592; Go back to the form</button>
    <a href="javascript:location.reload()">Start over</a>
  </div>
</div></body></html>
HTML;
    exit();
}

/* =====================================================================
   OTP THROTTLING

   A verification code is 6 digits — one million possibilities, valid for
   30 minutes. verify.php accepted unlimited guesses, so a script could
   walk the whole range in minutes and activate someone else's account.

   Note this is deliberately NOT the same policy as login_lock_seconds(),
   which is switched off on purpose because staff mistype passwords on the
   shared gate phone and being frozen out stopped real work. A numeric OTP
   is different: nobody legitimately types it fifty times, so it is capped
   and the cap is always on.

   Reuses the existing login_attempts table (it bootstraps itself) with an
   "otp:" prefix on the key, so there is no new table to create or migrate.
   ===================================================================== */

/** Seconds the caller must wait before another code attempt, 0 if free. */
function vts_otp_lock_seconds($conn, string $email): int {
    vts_login_table($conn);
    $key = 'otp:' . mb_substr(strtolower(trim($email)), 0, 94);
    try {
        $ip = vts_client_ip();
        // Per email: 6 wrong codes in 15 minutes.
        $q = $conn->prepare("SELECT COUNT(*) FROM login_attempts
             WHERE success=0 AND username=:u AND attempted_at > NOW() - INTERVAL 15 MINUTE");
        $q->execute([':u' => $key]);
        $byEmail = (int)$q->fetchColumn();

        // Per IP: 20 wrong codes in 15 minutes, across any address — stops
        // one host working through a list of emails.
        $q2 = $conn->prepare("SELECT COUNT(*) FROM login_attempts
              WHERE success=0 AND ip=:ip AND username LIKE 'otp:%'
                AND attempted_at > NOW() - INTERVAL 15 MINUTE");
        $q2->execute([':ip' => $ip]);
        $byIp = (int)$q2->fetchColumn();

        if ($byEmail >= 6 || $byIp >= 20) {
            $q3 = $conn->prepare("SELECT TIMESTAMPDIFF(SECOND, NOW(), MAX(attempted_at) + INTERVAL 15 MINUTE)
                  FROM login_attempts
                  WHERE success=0 AND username LIKE 'otp:%' AND (username=:u OR ip=:ip)");
            $q3->execute([':u' => $key, ':ip' => $ip]);
            return max(0, (int)$q3->fetchColumn());
        }
    } catch (Throwable $e) { /* never block on a logging failure */ }
    return 0;
}

/** Record one code attempt. A correct code clears that email's failures. */
function vts_otp_record($conn, string $email, bool $success): void {
    vts_login_table($conn);
    $key = 'otp:' . mb_substr(strtolower(trim($email)), 0, 94);
    try {
        $conn->prepare("INSERT INTO login_attempts (username, ip, success) VALUES (:u,:ip,:s)")
             ->execute([':u' => $key, ':ip' => vts_client_ip(), ':s' => $success ? 1 : 0]);
        if ($success) {
            $conn->prepare("DELETE FROM login_attempts WHERE username=:u AND success=0")->execute([':u' => $key]);
        }
    } catch (Throwable $e) { /* best effort */ }
}

/**
 * Seconds left before another code may be SENT to this address, 0 if free.
 * Resending was an unauthenticated GET with no cooldown, so anyone could
 * point it at a student's address and refill their inbox on a loop.
 */
function vts_otp_resend_wait($conn, string $email, int $cooldown = 60): int {
    vts_login_table($conn);
    $key = 'otpsend:' . mb_substr(strtolower(trim($email)), 0, 90);
    try {
        $q = $conn->prepare("SELECT TIMESTAMPDIFF(SECOND, NOW(), MAX(attempted_at) + INTERVAL :cd SECOND)
                             FROM login_attempts WHERE username = :u");
        $q->bindValue(':cd', $cooldown, PDO::PARAM_INT);
        $q->bindValue(':u', $key);
        $q->execute();
        return max(0, (int)$q->fetchColumn());
    } catch (Throwable $e) { return 0; }
}

/** Note that a code was just sent, starting the cooldown. */
function vts_otp_resend_record($conn, string $email): void {
    vts_login_table($conn);
    $key = 'otpsend:' . mb_substr(strtolower(trim($email)), 0, 90);
    try {
        $conn->prepare("DELETE FROM login_attempts WHERE username = :u")->execute([':u' => $key]);
        $conn->prepare("INSERT INTO login_attempts (username, ip, success) VALUES (:u,:ip,1)")
             ->execute([':u' => $key, ':ip' => vts_client_ip()]);
    } catch (Throwable $e) { /* best effort */ }
}

/* =====================================================================
   STAFF LOGIN OTP — the second half of a staff sign-in.

   The password check in login_process.php was the entire door for every
   privileged account in the system. These are the accounts that can edit
   and erase a student's disciplinary record, and their passwords are typed
   in the least private places the college has: the guardhouse, a shared
   scanner phone, a lab machine a queue of people can see. One password
   read over a shoulder was one whole Admin account.

   So the password now only proves the first thing. A 6-digit code goes to
   the address the office already holds for that account, and no session
   exists until that code comes back. The pending state between the two
   steps deliberately carries no role and no user_id, so auth.php treats a
   half-finished sign-in as no sign-in at all.

   WHY A SEPARATE COLUMN. users.verify_code already holds a 6-digit code —
   but it is the *registration* code, and forgot_password.php overwrites it
   too. Sharing one column would mean a password reset started in one tab
   silently invalidates a login code in another, and a login code would
   satisfy verify.php. Different questions get different columns.

   WHY IT IS HASHED. verify_code is stored in the clear, which is a
   liability this flow does not need to inherit: anyone who can read the
   users table (a backup, an export, a stray injection anywhere else) could
   read live login codes out of it. Only the hash is stored here, so a
   leaked table hands over nothing that still works.

   Rate limiting is NOT new code — vts_otp_lock_seconds()/vts_otp_record()
   above already cap code guessing at 6 per address and 20 per IP per 15
   minutes, and this flow is keyed into the same counters.
   ===================================================================== */

require_once __DIR__ . '/../config/app.php';

/* The two columns this flow needs, added on first use. Same best-effort
   idiom as vts_ensure_categorization_columns(): cached per request, and a
   missing ALTER privilege degrades the feature instead of breaking login. */
function vts_ensure_login_otp_columns($conn) {
    static $done = false;
    if ($done) return;
    $done = true;
    $adds = [
        ['login_otp_hash',    'VARCHAR(255) NULL'],
        ['login_otp_expires', 'DATETIME NULL'],
    ];
    foreach ($adds as [$col, $ddl]) {
        try {
            $chk = $conn->prepare("SELECT 1 FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = :c");
            $chk->execute([':c' => $col]);
            if (!$chk->fetchColumn()) $conn->exec("ALTER TABLE `users` ADD COLUMN `{$col}` {$ddl}");
        } catch (Throwable $e) { /* ignore — feature degrades, login keeps working */ }
    }
}

/* =====================================================================
   WHICH STUDENTS HAVE A PASSWORD YET.

   Students used to have no password at all — registration generated a
   throwaway random hash nobody could ever type, and they signed in by
   looking themselves up. Registration now asks for a real password, but the
   students already in the system do not have one, and their stored hash is
   indistinguishable from a real one: both are just bcrypt output.

   So the fact has to be recorded rather than inferred. has_password is 0 for
   everyone who predates this, 1 the moment a student sets one (registering,
   or resetting via forgot_password.php). Student login reads it to decide
   which proof to ask for, which is what lets the two coexist while everybody
   moves across — see STUDENT_PASSWORD_REQUIRED in config/app.php.
   ===================================================================== */
function vts_ensure_password_flag($conn) {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        $chk = $conn->prepare("SELECT 1 FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'has_password'");
        $chk->execute();
        if (!$chk->fetchColumn()) {
            $conn->exec("ALTER TABLE `users` ADD COLUMN `has_password` TINYINT(1) NOT NULL DEFAULT 0");
            /* Staff have always had real passwords — they have been typing them
               to sign in. Only students carried the throwaway hash, so only
               students start at 0. */
            $conn->exec("UPDATE `users` SET `has_password` = 1 WHERE role <> 'Student'");
        }
    } catch (Throwable $e) { /* ignore — login falls back to the lookup path */ }
}

/* The one notification that nags a student to set a password. Its title is
   fixed so the pair of helpers below can find it again. */
const VTS_PW_NOTICE_TITLE = 'Set your password';

/**
 * Put the "set a password" notice in the student's bell, once.
 *
 * Deliberately NOT notify_once(): that only suppresses a repeat within 60
 * seconds, so a student who signs in twice a day would collect a new copy of
 * the same nag every time. This one stays a single unread row until they act
 * on it, however long that takes.
 */
function vts_notify_set_password($conn, int $userId): void {
    try {
        $chk = $conn->prepare("SELECT id FROM notifications
                               WHERE user_id = :u AND title = :t AND is_read = 0 LIMIT 1");
        $chk->execute([':u' => $userId, ':t' => VTS_PW_NOTICE_TITLE]);
        if ($chk->fetch()) return;                       // already waiting for them

        $conn->prepare("INSERT INTO notifications (user_id, title, message) VALUES (:u, :t, :m)")
             ->execute([
                 ':u' => $userId,
                 ':t' => VTS_PW_NOTICE_TITLE,
                 ':m' => 'Your account has no password yet. Open My Profile to set one — '
                       . 'you will need it to sign in once the old method is switched off.',
             ]);
    } catch (Throwable $e) {
        // Never break a login over a missing nag — but do not vanish either,
        // or "the reminder isn't appearing" has nothing to go on.
        error_log('Set-password notice failed: ' . $e->getMessage());
    }
}

/** Clear that notice — they have done the thing it was asking for. */
function vts_clear_password_notice($conn, int $userId): void {
    try {
        $conn->prepare("DELETE FROM notifications WHERE user_id = :u AND title = :t")
             ->execute([':u' => $userId, ':t' => VTS_PW_NOTICE_TITLE]);
    } catch (Throwable $e) { /* best effort */ }
}

/** Record that this account now has a password its owner chose. */
function vts_mark_password_set($conn, int $userId): void {
    vts_ensure_password_flag($conn);
    try {
        $conn->prepare("UPDATE users SET has_password = 1 WHERE id = :id")->execute([':id' => $userId]);
    } catch (Throwable $e) { /* best effort */ }
    // The reason for the nag is gone, so the nag goes with it.
    vts_clear_password_notice($conn, $userId);
}

/* =====================================================================
   MAY THIS REQUEST BE SHOWN A SECRET IT SHOULD HAVE BEEN EMAILED?

   Only ever true on a developer's own machine. Two independent conditions,
   because a single flag is one careless commit away from being wrong on the
   live site:

     1. DEV_SHOW_RESET_CODE is on in config/app.php, and
     2. the request actually came from localhost or a private LAN address.

   The host check is not a formality — it is what makes the flag safe to get
   wrong. A public deployment can never satisfy it, whatever the config says.
   ===================================================================== */
function vts_dev_reveal_ok(): bool {
    if (!defined('DEV_SHOW_RESET_CODE') || DEV_SHOW_RESET_CODE !== true) return false;

    $host = $_SERVER['HTTP_HOST'] ?? '';
    $hostOk = in_array($host, ['localhost', '127.0.0.1'], true)
           || str_starts_with($host, 'localhost:')
           || str_starts_with($host, '127.0.0.1:')
           || preg_match('~\.(test|localhost|local)(:\d+)?$~i', $host) === 1
           || preg_match('~^(10\.|192\.168\.|169\.254\.|172\.(1[6-9]|2[0-9]|3[01])\.)~', $host) === 1;
    if (!$hostOk) return false;

    /* And the client itself must be local, so a public hostname pointed at a
       dev box cannot be used to harvest codes. */
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    return $ip === '127.0.0.1' || $ip === '::1'
        || (bool)preg_match('~^(10\.|192\.168\.|169\.254\.|172\.(1[6-9]|2[0-9]|3[01])\.)~', $ip);
}

/** Does this student sign in with a password, or still with the old lookup? */
function vts_student_has_password($conn, array $user): bool {
    vts_ensure_password_flag($conn);
    return (int)($user['has_password'] ?? 0) === 1;
}

/** Does this role sign in with a second step? */
function vts_staff_otp_applies($role): bool {
    if (!defined('STAFF_OTP_ENABLED') || STAFF_OTP_ENABLED !== true) return false;
    return in_array((string)$role, STAFF_OTP_ROLES, true);
}

/**
 * Can a code actually REACH this account? An address the mailer will refuse
 * (blank, malformed, or one of the auto-generated @student.gwc.local
 * placeholders) means there is nowhere to send a second factor — see the
 * note in login_process.php for what happens then.
 */
function vts_otp_deliverable($email): bool {
    $email = trim((string)$email);
    return $email !== ''
        && filter_var($email, FILTER_VALIDATE_EMAIL) !== false
        && stripos($email, '@student.gwc.local') === false;
}

/** "jdelacruz@gwc.edu.ph" -> "jde•••@gwc.edu.ph", for showing on the code page. */
function vts_mask_email($email): string {
    $email = trim((string)$email);
    $at = strrpos($email, '@');
    if ($at === false || $at === 0) return $email;
    $user = substr($email, 0, $at);
    $keep = mb_substr($user, 0, min(3, max(1, mb_strlen($user) - 1)));
    return $keep . '•••' . substr($email, $at);
}

/**
 * Generate a login code, store only its hash, and email it.
 * Returns true when a mail provider accepted the message.
 */
function vts_issue_login_otp($conn, array $user, ?string &$devCode = null): bool {
    vts_ensure_login_otp_columns($conn);
    $email = trim((string)($user['email'] ?? ''));
    if (!vts_otp_deliverable($email)) return false;

    $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $ttl  = defined('STAFF_OTP_TTL') ? (int)STAFF_OTP_TTL : 600;

    try {
        $up = $conn->prepare("UPDATE users
                              SET login_otp_hash = :h,
                                  login_otp_expires = DATE_ADD(NOW(), INTERVAL :ttl SECOND)
                              WHERE id = :id");
        $up->bindValue(':h',   password_hash($code, PASSWORD_DEFAULT));
        $up->bindValue(':ttl', $ttl, PDO::PARAM_INT);
        $up->bindValue(':id',  (int)$user['id'], PDO::PARAM_INT);
        $up->execute();
    } catch (Throwable $e) {
        error_log('Login OTP store failed: ' . $e->getMessage());
        return false;
    }

    /* Hand the plaintext back ONLY on a developer machine, and only so
       login_otp.php can print it when there is no mailbox to send it to.
       Same double gate as the password-reset code — see vts_dev_reveal_ok().
       Everywhere else this stays null and only the hash is ever stored. */
    if (vts_dev_reveal_ok()) $devCode = $code;

    require_once __DIR__ . '/mailer.php';
    if (!function_exists('vts_send_mail')) return false;

    $mins = max(1, (int)round($ttl / 60));
    $appName = defined('APP_NAME') ? APP_NAME : 'QR Shield';
    $body = vts_mail_shell('Your sign-in code',
        '<p style="font-size:14px;color:#333;">Hi <b>' . htmlspecialchars((string)$user['fullname']) . '</b>,</p>'
      . '<p style="font-size:14px;color:#333;">Someone signed in to your <b>'
      . htmlspecialchars((string)$user['role']) . '</b> account at ' . date('g:i A')
      . ' and needs this code to finish. It expires in ' . $mins . ' minutes.</p>'
      . '<p style="text-align:center;font-size:30px;font-weight:800;letter-spacing:8px;color:#1a3a6b;margin:16px 0;">'
      . htmlspecialchars($code) . '</p>'
      . '<p style="font-size:13px;color:#b0122b;"><b>If this was not you, someone else knows your password.</b> '
      . 'Do not enter the code — change your password and tell the Office of Student Affairs.</p>');

    return vts_send_mail($email, (string)$user['fullname'], 'Your ' . $appName . ' sign-in code: ' . $code, $body);
}

/**
 * Check a typed login code. A correct code is consumed on the spot, so the
 * same one can never be replayed — including by whoever is reading the
 * inbox it was sent to.
 */
function vts_check_login_otp($conn, int $userId, string $code): bool {
    vts_ensure_login_otp_columns($conn);
    $code = trim($code);
    if ($code === '') return false;

    try {
        $st = $conn->prepare("SELECT login_otp_hash, login_otp_expires FROM users WHERE id = :id LIMIT 1");
        $st->execute([':id' => $userId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log('Login OTP check failed: ' . $e->getMessage());
        return false;
    }

    if (!$row || empty($row['login_otp_hash']) || empty($row['login_otp_expires'])) return false;
    if (strtotime((string)$row['login_otp_expires']) < time())                      return false;
    if (!password_verify($code, (string)$row['login_otp_hash']))                    return false;

    vts_clear_login_otp($conn, $userId);   // single use
    return true;
}

/** Drop any outstanding login code for this account. */
function vts_clear_login_otp($conn, int $userId): void {
    try {
        $conn->prepare("UPDATE users SET login_otp_hash = NULL, login_otp_expires = NULL WHERE id = :id")
             ->execute([':id' => $userId]);
    } catch (Throwable $e) { /* best effort */ }
}

/* =====================================================================
   PASSWORD-RESET CODES — their own columns, not verify_code.

   forgot_password.php used to write users.verify_code, which is also where
   verify.php keeps the code that confirms a NEW registration's email
   address. One column, two unrelated jobs, and no way to tell whose code is
   in it: asking for a password reset silently destroyed a pending email
   verification, and verifying an email destroyed a pending reset. Whichever
   was requested last won, and the other just stopped working with no
   explanation.

   Same reasoning as the staff login OTP above: different questions get
   different columns. And like that one the code is HASHED — verify_code was
   stored in the clear, so anyone who could read the users table (a backup,
   an export, an injection elsewhere) could read live reset codes and take
   over any account they liked.
   ===================================================================== */
function vts_ensure_reset_columns($conn) {
    static $done = false;
    if ($done) return;
    $done = true;
    foreach ([['reset_code_hash', 'VARCHAR(255) NULL'],
              ['reset_expires',   'DATETIME NULL']] as [$col, $ddl]) {
        try {
            $chk = $conn->prepare("SELECT 1 FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = :c");
            $chk->execute([':c' => $col]);
            if (!$chk->fetchColumn()) $conn->exec("ALTER TABLE `users` ADD COLUMN `{$col}` {$ddl}");
        } catch (Throwable $e) { /* best effort — the feature degrades, login keeps working */ }
    }
}

/** Store a fresh reset code for this account and hand back the plaintext. */
function vts_issue_reset_code($conn, int $userId, int $ttlMinutes = 30): ?string {
    vts_ensure_reset_columns($conn);
    $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    try {
        $up = $conn->prepare("UPDATE users
                              SET reset_code_hash = :h,
                                  reset_expires   = DATE_ADD(NOW(), INTERVAL :m MINUTE)
                              WHERE id = :id");
        $up->bindValue(':h', password_hash($code, PASSWORD_DEFAULT));
        $up->bindValue(':m', $ttlMinutes, PDO::PARAM_INT);
        $up->bindValue(':id', $userId, PDO::PARAM_INT);
        $up->execute();
    } catch (Throwable $e) {
        error_log('Reset code store failed: ' . $e->getMessage());
        return null;
    }
    return $code;
}

/** True when this is the live, unexpired code for the account. Single use. */
function vts_check_reset_code($conn, int $userId, string $code): bool {
    vts_ensure_reset_columns($conn);
    $code = trim($code);
    if ($code === '') return false;
    try {
        $st = $conn->prepare("SELECT reset_code_hash, reset_expires FROM users WHERE id = :id LIMIT 1");
        $st->execute([':id' => $userId]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log('Reset code check failed: ' . $e->getMessage());
        return false;
    }
    if (!$r || empty($r['reset_code_hash']) || empty($r['reset_expires'])) return false;
    if (strtotime((string)$r['reset_expires']) < time())                    return false;
    return password_verify($code, (string)$r['reset_code_hash']);
}

/** Drop any outstanding reset code (used the moment one is spent). */
function vts_clear_reset_code($conn, int $userId): void {
    try {
        $conn->prepare("UPDATE users SET reset_code_hash = NULL, reset_expires = NULL WHERE id = :id")
             ->execute([':id' => $userId]);
    } catch (Throwable $e) { /* best effort */ }
}

/* =====================================================================
   GRANTING THE SESSION, IN ONE PLACE.

   login_process.php used to be the only thing that populated $_SESSION and
   worked out where a role lands. login_otp.php now finishes half of those
   sign-ins, and a second copy of "what a logged-in session contains" is
   exactly the kind of thing that drifts — one copy gains a field, the other
   does not, and a session ends up subtly different depending on which door
   it came through. Both call these.
   ===================================================================== */

/** Where a role lands after signing in, as a path relative to the app root. */
function vts_login_destination($role): string {
    return [
        'Admin'     => 'admin/dashboard.php',
        'OSA'       => 'admin/dashboard.php',   // admin-level; shares Admin's pages
        'OSA Staff' => 'osa_staff/dashboard.php',
        'Guard'     => 'spck_scanner.html',     // straight to the scanner
        'Student'   => 'student/dashboard.php',
    ][(string)$role] ?? '';
}

/**
 * Turn a verified user row into a live session. Regenerates the session ID
 * first: the ID that carried the anonymous visitor (and, for staff, the
 * half-finished OTP step) must not be the ID that carries their privileges,
 * or anyone who planted that ID beforehand inherits the account.
 */
function vts_establish_session(array $user): void {
    session_regenerate_id(true);

    // A half-finished staff sign-in must not outlive the session it belonged
    // to — otherwise an abandoned one sits there waiting to be resumed.
    unset($_SESSION['pending_otp']);

    $_SESSION['user_id']         = $user['id'];
    $_SESSION['fullname']        = $user['fullname'];
    $_SESSION['role']            = $user['role'];
    $_SESSION['email']           = $user['email'] ?? '';
    $_SESSION['profile_picture'] = $user['profile_picture'] ?? '';
    $_SESSION['LOGIN_TIME']      = time();
    $_SESSION['LAST_ACTIVITY']   = time();
    $_SESSION['fp']              = hash('sha256', ($_SERVER['HTTP_USER_AGENT'] ?? '') . '|VTS');
}

/* =====================================================================
   NEW-DEVICE GATE — one recognised browser per account, every role.
   ---------------------------------------------------------------------
   Staff already got a mandatory code on every single login (see
   vts_issue_login_otp() above) — strong, but the same friction whether the
   browser was seen yesterday or never before. Students and the two
   auto-login flows (register, passwordless lookup) had NOTHING: any browser
   that knew a password, or an unregistered student's own School ID + name,
   was let straight in.

   This closes both gaps the same way, everywhere: a long-lived, httponly
   cookie names the one browser currently "recognised" for an account. A
   request from that browser signs in exactly as it always did — nothing
   changes for the ordinary case of using your own device. A request from
   any OTHER browser is held at the door: a 6-digit code goes to the
   account's email, and nothing is written to $_SESSION until it is entered
   (same "not logged in until the code is right" rule login_otp.php already
   uses). The FIRST session is never touched — this only decides whether a
   NEW one may start, exactly the "OTP gates the second login, first session
   stays alive" behaviour asked for.

   Fails OPEN, like the staff 2FA it sits next to: an account with no usable
   email, or a mail outage, signs in as a recognised device would and the gap
   is written to the audit log — a login feature must never be the reason
   the office can't get into its own system. */

if (!defined('VTS_DEVICE_COOKIE'))   define('VTS_DEVICE_COOKIE', 'vts_device');
if (!defined('VTS_DEVICE_OTP_TTL'))  define('VTS_DEVICE_OTP_TTL', 600); // 10 minutes

function vts_ensure_device_columns($conn): void {
    static $done = false;
    if ($done) return;
    $done = true;
    foreach ([
        ['device_token',            'VARCHAR(64) NULL'],
        ['device_otp_hash',         'VARCHAR(255) NULL'],
        ['device_otp_expires',      'DATETIME NULL'],
        ['device_otp_pending_token','VARCHAR(64) NULL'],
    ] as [$col, $ddl]) {
        try {
            $chk = $conn->prepare("SELECT 1 FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = :c");
            $chk->execute([':c' => $col]);
            if (!$chk->fetchColumn()) $conn->exec("ALTER TABLE `users` ADD COLUMN `{$col}` {$ddl}");
        } catch (Throwable $e) { /* feature degrades; login keeps working */ }
    }
}

/** Is THIS browser the one already trusted for THIS account? */
function vts_device_recognized($conn, array $user): bool {
    $cookie = (string)($_COOKIE[VTS_DEVICE_COOKIE] ?? '');
    $stored = (string)($user['device_token'] ?? '');
    return $cookie !== '' && $stored !== '' && hash_equals($stored, $cookie);
}

function vts_device_set_cookie(string $token): void {
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
          || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    setcookie(VTS_DEVICE_COOKIE, $token, [
        'expires'  => time() + 60 * 60 * 24 * 365,
        'path'     => '/',
        'secure'   => $https,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

/** Generate + email a device code. Returns false if it could not be sent
 *  (no usable address, mail outage) — the caller falls through, never blocks. */
function vts_device_issue_otp($conn, array $user, ?string &$devCode = null): bool {
    vts_ensure_device_columns($conn);
    $email = trim((string)($user['email'] ?? ''));
    if (!vts_otp_deliverable($email)) return false;

    $code  = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $token = bin2hex(random_bytes(32));

    try {
        $up = $conn->prepare("UPDATE users SET
                device_otp_hash = :h,
                device_otp_expires = DATE_ADD(NOW(), INTERVAL :ttl SECOND),
                device_otp_pending_token = :t
              WHERE id = :id");
        $up->bindValue(':h',   password_hash($code, PASSWORD_DEFAULT));
        $up->bindValue(':ttl', VTS_DEVICE_OTP_TTL, PDO::PARAM_INT);
        $up->bindValue(':t',   $token);
        $up->bindValue(':id',  (int)$user['id'], PDO::PARAM_INT);
        $up->execute();
    } catch (Throwable $e) {
        error_log('Device OTP store failed: ' . $e->getMessage());
        return false;
    }

    // Dev machines only — see vts_dev_reveal_ok().
    if (vts_dev_reveal_ok()) $devCode = $code;

    require_once __DIR__ . '/mailer.php';
    if (!function_exists('vts_send_mail')) return false;

    $mins    = max(1, (int)round(VTS_DEVICE_OTP_TTL / 60));
    $appName = defined('APP_NAME') ? APP_NAME : 'QR Shield';
    $body = vts_mail_shell('New device sign-in',
        '<p style="font-size:14px;color:#333;">Hi <b>' . htmlspecialchars((string)$user['fullname']) . '</b>,</p>'
      . '<p style="font-size:14px;color:#333;">Your account was just used to sign in from a device we don\'t '
      . 'recognize, at ' . date('g:i A') . '. Enter this code to continue — it expires in ' . $mins . ' minutes.</p>'
      . '<p style="text-align:center;font-size:30px;font-weight:800;letter-spacing:8px;color:#1a3a6b;margin:16px 0;">'
      . htmlspecialchars($code) . '</p>'
      . '<p style="font-size:13px;color:#b0122b;"><b>If this wasn\'t you</b>, someone else has your password or ID. '
      . 'Do not enter the code — change your password and tell the Office of Student Affairs.</p>');

    return vts_send_mail($email, (string)$user['fullname'], 'New device sign-in — your code: ' . $code, $body);
}

/** A correct code both confirms the device AND makes it the recognised one —
 *  consumed on the spot, so it can never be replayed. */
function vts_device_check_otp($conn, int $userId, string $code): bool {
    vts_ensure_device_columns($conn);
    $code = trim($code);
    if ($code === '') return false;

    try {
        $st = $conn->prepare("SELECT device_otp_hash, device_otp_expires, device_otp_pending_token
                              FROM users WHERE id = :id LIMIT 1");
        $st->execute([':id' => $userId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log('Device OTP check failed: ' . $e->getMessage());
        return false;
    }

    if (!$row || empty($row['device_otp_hash']) || empty($row['device_otp_expires'])) return false;
    if (strtotime((string)$row['device_otp_expires']) < time())                        return false;
    if (!password_verify($code, (string)$row['device_otp_hash']))                       return false;

    $token = (string)($row['device_otp_pending_token'] ?? '');
    try {
        $conn->prepare("UPDATE users SET device_token = :t, device_otp_hash = NULL,
                        device_otp_expires = NULL, device_otp_pending_token = NULL WHERE id = :id")
             ->execute([':t' => ($token !== '' ? $token : null), ':id' => $userId]);
    } catch (Throwable $e) {
        error_log('Device token commit failed: ' . $e->getMessage());
        return false;
    }
    if ($token !== '') vts_device_set_cookie($token);
    return true;
}

/** Make THIS browser the recognised device for an account, with no code.
 *  For the one case where a code would be theatre: a brand-new registration.
 *  The account did not exist a second ago, so there is no other session to
 *  protect and nobody to ask — this browser is simply its first device.
 *  Every LATER browser still has to pass vts_complete_login()'s gate. */
function vts_device_trust_now($conn, int $userId): void {
    vts_ensure_device_columns($conn);
    try {
        $token = bin2hex(random_bytes(32));
        $conn->prepare("UPDATE users SET device_token = :t, device_otp_hash = NULL,
                        device_otp_expires = NULL, device_otp_pending_token = NULL WHERE id = :id")
             ->execute([':t' => $token, ':id' => $userId]);
        vts_device_set_cookie($token);
    } catch (Throwable $e) {
        // Not fatal: the next sign-in just sees an unrecognised device and
        // asks for a code, which is the safe direction to fail in.
        error_log('vts_device_trust_now failed: ' . $e->getMessage());
    }
}

/** Drop an outstanding device code without consuming it (wrong-account guard,
 *  abandoned attempt, etc.). */
function vts_device_clear_otp($conn, int $userId): void {
    try {
        $conn->prepare("UPDATE users SET device_otp_hash = NULL, device_otp_expires = NULL,
                        device_otp_pending_token = NULL WHERE id = :id")->execute([':id' => $userId]);
    } catch (Throwable $e) { /* best effort */ }
}

/** Where a granted login actually lands, as a path relative to the app root
 *  (e.g. "admin/dashboard.php") — same shape vts_login_destination() returns.
 *  Deliberately does NOT redirect: login_process.php's staff form is
 *  submitted over fetch() and needs this URL as JSON, not a Location header
 *  (see login_goto() there) — every caller finishes the request its own way. */
function vts_login_landing(string $role): string {
    $dest = vts_login_destination($role);
    return $dest !== '' ? $dest : 'student_search.php';
}

/**
 * THE one door every login flow finishes through — password login, staff
 * OTP, registration auto-login, and the passwordless student lookup all call
 * this instead of vts_establish_session() directly.
 *
 * Returns a path (relative to the app root) for the CALLER to send the
 * browser to — never redirects itself, because how a caller finishes the
 * request differs (a plain Location header for most flows, a JSON
 * {redirect:...} body for login_process.php's AJAX staff form) and only the
 * caller knows which. Recognised device: the path IS the destination
 * dashboard, and the session is already live by the time this returns. New
 * device: the path is auth/device_verify.php and NOTHING has been written to
 * $_SESSION as "logged in" yet — that only happens once the code is entered
 * there, same rule login_otp.php already used for staff.
 */
function vts_complete_login(array $user, string $auditNote): string {
    global $conn;
    vts_ensure_device_columns($conn);

    if (vts_device_recognized($conn, $user)) {
        vts_establish_session($user);
        audit_log($conn, "Login", "users", $user['id'], $auditNote);
        return vts_login_landing($user['role']);
    }

    $devCode = null;
    if (!vts_device_issue_otp($conn, $user, $devCode)) {
        audit_log($conn, "Login (new device, unverified)", "users", $user['id'],
            $auditNote . ' — from an unrecognised device, but no code could be sent (no usable email on file); let through.');
        vts_establish_session($user);
        audit_log($conn, "Login", "users", $user['id'], $auditNote);
        return vts_login_landing($user['role']);
    }

    // Nothing above this point wrote to $_SESSION as "logged in" — a fresh ID
    // for the pending step too, same reasoning as the staff OTP step.
    session_regenerate_id(true);
    $_SESSION['device_pending'] = [
        'user_id'    => (int)$user['id'],
        'dev_code'   => $devCode,   // dev machines only; null everywhere else
        'started'    => time(),
        'audit_note' => $auditNote,
    ];
    return 'auth/device_verify.php';
}

/* =====================================================================
   WHO IS ACTUALLY HOLDING THE SCANNER?

   The scanner had no notion of this. The Duty screen asked the marshal to
   type a name and a School ID, marked the result auth:false, and told them
   outright "you don't need to be in any list" -- so whatever was typed went
   into violations.scanner_name verbatim and showed up as the recorder. One
   marshal could file scans under a colleague's name, or an invented one,
   and nothing anywhere would notice.

   This resolves the typed pair against the staff list and hands back the
   REAL record. Callers must store the fullname it returns, not the typed
   one, so a sloppy-but-passing entry ("r. santos") is still filed under the
   name the office knows.

   Matching rule: the School ID must match a Guard exactly. The name then
   has to be consistent with that record -- the longest word typed must
   appear in the stored name -- which tolerates "R. Santos" for "Ramon
   Santos" while rejecting a different person's name pasted against a
   borrowed ID.

   NOTE ON ITS LIMITS: a name plus an ID is identification, not
   authentication. Neither is secret, so anyone who KNOWS a marshal's ID can
   still act as them. Closing that needs a per-marshal PIN or password; this
   closes the "type anything at all" hole, which is the one that was open.
   ===================================================================== */
/* WHICH ACCOUNTS MAY HOLD THE SCANNER.

   The marshals at this school ARE students — there is no separate guard
   staff — so requiring a Guard account locked the actual marshals out and
   the scanner was unusable by the people meant to use it.

   Kept as a list rather than hardwired, because this is the one line that
   decides who can record a violation. To tighten it later — say, once
   marshals get their own accounts — remove 'Student' here and nothing else
   has to change.

   WORTH BEING CLEAR ABOUT: with 'Student' in this list, ANY active student
   who knows their own name and School ID can open the scanner and record a
   violation against another student. That is the trade for letting student
   marshals work at all. It does NOT let them impersonate anyone — the name
   filed is read off the account the ID belongs to — but it does not
   distinguish a marshal from any other student either. */
if (!defined('VTS_SCANNER_ROLES')) {
    define('VTS_SCANNER_ROLES', ['Guard', 'Student']);
}

function vts_resolve_marshal($conn, $schoolId, $typedName = '') {
    $sid = strtoupper(trim((string)$schoolId));
    if ($sid === '') return null;

    try {
        /* Matched on student_id OR username: students are keyed by their
           School ID, while a Guard account may have been created with only a
           username (which is what scanner_data.php falls back to sending), so
           accepting just one of the two locks somebody out either way. */
        $roles = VTS_SCANNER_ROLES;
        $in    = implode(',', array_fill(0, count($roles), '?'));
        /* No `scanner_access = 1` gate any more — see the "ON-DUTY MARSHAL
           SYSTEM" note near vts_claim_duty_slot(). This only confirms the ID
           belongs to a role that MAY hold the scanner at all; whether they
           are actually on duty right now is the scanner_session_token check
           the caller (scan_submit.php) does immediately after this. */
        $st = $conn->prepare(
            "SELECT id, fullname, COALESCE(NULLIF(student_id,''), username) AS staff_id, role
             FROM users
             WHERE role IN ($in) AND status = 'Active'
               AND (UPPER(student_id) = ? OR UPPER(username) = ?)
             LIMIT 1");
        $st->execute(array_merge($roles, [$sid, $sid]));
        $row = $st->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log('vts_resolve_marshal failed: ' . $e->getMessage());
        return null;
    }
    if (!$row) return null;

    $typed = trim((string)$typedName);
    if ($typed !== '' && !vts_name_consistent($typed, (string)$row['fullname'])) return null;

    return $row;
}

/* Is the typed name plausibly the same person as the stored one?

   Position tells you nothing here. The staff list holds both orders —
   "Shawn Kevyn Canta Pingad" (surname last) and "Garce Jasper F" (surname
   FIRST, with a middle initial trailing) — so a rule that treats the last
   word as the surname refuses half the people it should admit. It refused
   "garce" for "Garce Jasper F", reading "F" as the surname.

   Order-independent rule instead:

     1. every WHOLE word typed must appear somewhere in the stored name —
        this is what stops one person being entered against another's ID;
     2. a bare INITIAL must stand for a stored word not already matched,
        unless every stored word is accounted for, in which case it is a
        middle initial the staff list simply does not carry.

   So "garce", "Garce Jasper F" and "R. Santos" pass, "Ramon T. Santos"
   passes for "Ramon Santos" (stray middle initial), and "A. Dela Cruz"
   still does not pass for "Eva Dela Cruz" — the A has to stand for Eva,
   and it does not. */
function vts_name_consistent($typed, $stored) {
    $norm = function ($s) {
        $s = strtolower(trim((string)$s));
        $s = preg_replace('/[^a-z0-9\s]+/', ' ', $s);   // "R." -> "r"
        return trim(preg_replace('/\s+/', ' ', $s));
    };
    $t = $norm($typed);
    $s = $norm($stored);
    if ($t === '' || $s === '') return false;
    if ($t === $s) return true;

    $storedWords = array_values(array_filter(explode(' ', $s)));
    $typedWords  = array_values(array_filter(explode(' ', $t)));
    if (!$storedWords || !$typedWords) return false;

    $whole    = array_values(array_filter($typedWords, fn($w) => strlen($w) > 1));
    $initials = array_values(array_filter($typedWords, fn($w) => strlen($w) === 1));
    if (!$whole) return false;          // initials alone identify nobody

    // 1. Every whole word typed has to be part of the stored name.
    $unmatched = $storedWords;
    foreach ($whole as $w) {
        $i = array_search($w, $unmatched, true);
        if ($i === false) {
            // Allowed to repeat a word already consumed, but not to invent one.
            if (!in_array($w, $storedWords, true)) return false;
            continue;
        }
        array_splice($unmatched, $i, 1);
    }

    // 2. Each initial should stand for one of the stored words left over.
    foreach ($initials as $c) {
        $hit = false;
        foreach ($unmatched as $i => $w) {
            if (substr($w, 0, 1) === $c) { array_splice($unmatched, $i, 1); $hit = true; break; }
        }
        // An initial that matches nothing is only fine once the stored name
        // is fully accounted for — then it is a middle initial we do not hold.
        if (!$hit && $unmatched) return false;
    }
    return true;
}

/* =====================================================================
   THE VIOLATIONS FILTER, IN ONE PLACE.

   admin/violations.php built this WHERE clause inline. The auto-refresh
   needs the SAME filter to answer "has anything new arrived in what you
   are actually looking at?" — a second copy would answer that question
   differently the first time either is edited, so both read from here.

   Takes the request array ($_GET) and hands back the clause and its bound
   parameters. Column names assume the page's aliases: v = violations,
   u = users.
   ===================================================================== */
function vts_violation_filter(array $g): array {
    $str = fn($k) => is_string($g[$k] ?? null) ? trim($g[$k]) : '';

    $search    = $str('search');
    $yearLevel = $str('year_level');
    $course    = $str('course');
    $vfilter   = $str('violation');
    $section   = $str('section');
    $college   = $str('college_id');
    $when      = $str('when') !== '' ? $str('when') : 'all';
    $from      = $str('from');
    $to        = $str('to');

    $where = [];
    $params = [];

    if ($search !== '') {
        $where[] = "(u.student_id LIKE :s OR u.fullname LIKE :s OR v.violation LIKE :s)";
        $params[':s'] = "%{$search}%";
    }
    if ($yearLevel !== '') { $where[] = "u.year_level = :yl";  $params[':yl']  = $yearLevel; }
    if ($course    !== '') { $where[] = "u.course = :crs";     $params[':crs'] = $course; }
    if ($vfilter   !== '') { $where[] = "v.violation = :vf";   $params[':vf']  = $vfilter; }
    if ($section   !== '') { $where[] = "u.section = :sec";    $params[':sec'] = $section; }
    if ($college   !== '') { $where[] = "u.college_id = :col"; $params[':col'] = (int)$college; }

    /* Date filter — when it happened. An explicit from/to pair wins over the
       named range, exactly as the page has always behaved. */
    $isDate = fn($d) => (bool)preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$d);
    if ($isDate($from) && $isDate($to)) {
        $where[] = "DATE(v.date_reported) BETWEEN :df AND :dt";
        $params[':df'] = $from; $params[':dt'] = $to;
    } else {
        switch ($when) {
            case 'today':     $where[] = "DATE(v.date_reported) = CURDATE()"; break;
            case 'yesterday': $where[] = "DATE(v.date_reported) = CURDATE() - INTERVAL 1 DAY"; break;
            case '7days':     $where[] = "v.date_reported >= CURDATE() - INTERVAL 6 DAY"; break;
            case 'month':     $where[] = "YEAR(v.date_reported)=YEAR(CURDATE()) AND MONTH(v.date_reported)=MONTH(CURDATE())"; break;
        }
    }
    return ['where' => $where, 'params' => $params];
}

/* ─────────────────────────────────────────────────────────────────────────
   PAGINATION

   Long listings were printing every row they had. The pager markup was
   written out by hand on the two pages that did page, with inline styles
   and a link for every page — fine at three pages, unusable at ninety.

   vts_page_window() works out which page numbers to show, and vts_pager()
   renders them. Both are pure: pass the current page and the total, get
   back what to draw.
   ───────────────────────────────────────────────────────────────────────── */

/**
 * The page numbers to offer around $page, with 0 standing for a gap.
 * Always includes the first and last page so the ends stay reachable.
 *
 * e.g. page 40 of 90 -> [1, 0, 38, 39, 40, 41, 42, 0, 90]
 */
function vts_page_window(int $page, int $totalPages, int $around = 2): array {
    if ($totalPages < 1) return [];
    $keep = [1, $totalPages];
    for ($i = $page - $around; $i <= $page + $around; $i++) {
        if ($i >= 1 && $i <= $totalPages) $keep[] = $i;
    }
    $keep = array_values(array_unique($keep));
    sort($keep);

    $out = [];
    $prev = 0;
    foreach ($keep as $n) {
        if ($prev && $n > $prev + 1) $out[] = 0;   // gap marker
        $out[] = $n;
        $prev = $n;
    }
    return $out;
}

/**
 * Render the pager. $query is the current filter state (search, course, …) so
 * paging keeps whatever the user has filtered to; 'page' in it is ignored.
 * Returns '' when there is only one page, so callers can echo it blind.
 */
function vts_pager(int $page, int $totalPages, array $query = [], string $label = 'results'): string {
    if ($totalPages <= 1) return '';

    unset($query['page']);
    $link = function (int $n) use ($query): string {
        $q = $query;
        $q['page'] = $n;
        return '?' . http_build_query($q);
    };
    $esc = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');

    $out  = '<nav class="vts-pager" aria-label="' . $esc(ucfirst($label)) . ' pages">';
    $out .= '<span class="vts-pager-status" aria-live="polite">Page ' . $page . ' of ' . $totalPages . '</span>';
    $out .= '<span class="vts-pager-links">';

    $out .= $page > 1
        ? '<a class="vts-pager-btn" href="' . $esc($link($page - 1)) . '" rel="prev">'
          . '<i class="fas fa-chevron-left" aria-hidden="true"></i><span class="sr-only">Previous page</span></a>'
        : '<span class="vts-pager-btn is-off" aria-hidden="true"><i class="fas fa-chevron-left"></i></span>';

    foreach (vts_page_window($page, $totalPages) as $n) {
        if ($n === 0) { $out .= '<span class="vts-pager-gap">&hellip;</span>'; continue; }
        $out .= $n === $page
            ? '<span class="vts-pager-btn is-current" aria-current="page">' . $n . '</span>'
            : '<a class="vts-pager-btn" href="' . $esc($link($n)) . '">'
              . '<span class="sr-only">Page </span>' . $n . '</a>';
    }

    $out .= $page < $totalPages
        ? '<a class="vts-pager-btn" href="' . $esc($link($page + 1)) . '" rel="next">'
          . '<i class="fas fa-chevron-right" aria-hidden="true"></i><span class="sr-only">Next page</span></a>'
        : '<span class="vts-pager-btn is-off" aria-hidden="true"><i class="fas fa-chevron-right"></i></span>';

    $out .= '</span></nav>';
    return $out;
}

/** Notify every account that can actually act on the thing being reported.
 *  Defaults to Admin + OSA, the two roles that own the records. $roles narrows
 *  it: the scanner-duty alert passes ['Admin'], because signing a marshal off
 *  is on the Admin-only Settings page and telling OSA to go and do it would be
 *  sending them at a locked door. */
function vts_notify_overseers($conn, string $title, string $message, $violationId = null, array $roles = ['Admin', 'OSA']): int {
    $sent = 0;
    try {
        // Whitelisted against the known roles, then inlined: this is a fixed
        // set from calling code, never user input, but a role list built by
        // string concatenation is worth keeping impossible to widen.
        $roles = array_values(array_intersect($roles, ['Admin', 'OSA', 'OSA Staff', 'Guard']));
        if (!$roles) return 0;
        $in = "'" . implode("','", $roles) . "'";
        $q = $conn->query("SELECT id FROM users WHERE role IN ($in) AND status = 'Active'");
        foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $uid) {
            try { if (notify_once($conn, (int)$uid, $title, $message, $violationId)) $sent++; }
            catch (Throwable $e) { /* one bad row must not stop the rest */ }
        }
    } catch (Throwable $e) { error_log('Overseer notify failed: ' . $e->getMessage()); }
    return $sent;
}

/* =====================================================================
   PROOF REVIEW  (admin/proof.php)
   ---------------------------------------------------------------------
   An Admin/OSA reads the photo against the reason the violation was
   recorded for and says whether the two agree.

   WHY THIS IS NOT THE OLD approve/reject COLUMN
   database/upgrade_2026-07.sql removed an approve/reject flow on purpose:
   violations are direct records, final the moment they are recorded. That
   has not changed and must not — a scan taken offline can reach the
   server days later on a USB, so a record that only counts once somebody
   has blessed it would leave every offline scan in limbo.

   So Pending is not a holding pen: an unreviewed violation counts exactly
   as it always did. A review only ever SUBTRACTS. Rejected means an
   Admin looked at the proof and found it does not support the record, and
   from then on the row stays in history but stops advancing the ladder.

   WHY NOT REUSE cleared_at
   "Cleared" already means something specific and narrow here:
   violation_is_clearable() only lets a MINOR offense be marked Served, and
   only when it is the student's sole offense. "The proof did not support
   this" is a different claim about a record, and it has to be able to strike
   a Major just as readily. Collapsing the two would lose the ability to say
   which happened, and would leave rejecting the proof on a Major silently
   impossible. The proof review gets its own columns.
   ===================================================================== */

/** Add the proof-review columns. Same idiom as the other ensures. */
function vts_ensure_proof_columns($conn): void {
    static $done = false;
    if ($done) return;
    $done = true;
    $adds = [
        ['proof_status',      "ENUM('Pending','Approved','Rejected') NOT NULL DEFAULT 'Pending'"],
        ['proof_reviewed_by', "INT UNSIGNED NULL"],
        ['proof_reviewed_at', "DATETIME NULL"],
    ];
    foreach ($adds as [$col, $ddl]) {
        try {
            $chk = $conn->prepare("SELECT 1 FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'violations' AND COLUMN_NAME = :c");
            $chk->execute([':c' => $col]);
            if (!$chk->fetchColumn()) $conn->exec("ALTER TABLE violations ADD COLUMN `{$col}` {$ddl}");
        } catch (Throwable $e) { /* feature degrades, recording still works */ }
    }
}

/**
 * Record a proof decision. $decision is 'approve', 'reject' or 'reset'.
 * Returns [bool $ok, string $message].
 *
 * A decision is reversible — an Admin who rejects the wrong row must be
 * able to put it back, and the ladder is re-derived either way, so the
 * offense numbers after it shift down and back up again correctly.
 */
function vts_decide_proof($conn, int $violationId, int $adminId, string $decision): array {
    vts_ensure_proof_columns($conn);

    $map = ['approve' => 'Approved', 'reject' => 'Rejected', 'reset' => 'Pending'];
    if (!isset($map[$decision])) return [false, 'Unknown decision.'];
    $new = $map[$decision];

    $q = $conn->prepare("SELECT id, student_id, proof_status FROM violations WHERE id = :v LIMIT 1");
    $q->execute([':v' => $violationId]);
    $v = $q->fetch(PDO::FETCH_ASSOC);
    if (!$v) return [false, 'That violation could not be found.'];

    $old       = (string)($v['proof_status'] ?? 'Pending');
    $studentId = (int)$v['student_id'];
    if ($old === $new) return [false, 'That record is already marked ' . strtolower($new) . '.'];

    /* Rejecting is what takes a Major off the student's count, so it waits
       for the conference. Approving and clearing are not gated: approving
       changes nothing for the student, and clearing only puts the record
       back to counting, which needs no meeting to justify. */
    if ($new === 'Rejected' && function_exists('vts_discussion_required')) {
        [$needsTalk, $why] = vts_discussion_required($conn, $violationId);
        if ($needsTalk) return [false, $why];
    }

    try {
        $conn->prepare("UPDATE violations
                           SET proof_status = :s, proof_reviewed_by = :by,
                               proof_reviewed_at = NOW()
                         WHERE id = :v")
             ->execute([':s' => $new, ':by' => $adminId, ':v' => $violationId]);
    } catch (Throwable $e) {
        error_log('Proof decision failed: ' . $e->getMessage());
        return [false, 'That did not go through. Please try again.'];
    }

    /* Only a move INTO or OUT OF Rejected changes what counts, so only
       those two touch the ladder or tell the student anything. Approving a
       record changes nothing for them: it already counted, and they were
       told about it when it was recorded. */
    $wasCounting = ($old !== 'Rejected');
    $nowCounting = ($new !== 'Rejected');

    if ($wasCounting !== $nowCounting) {
        try { vts_renumber_offenses($conn, $studentId); } catch (Throwable $e) {}

        $title = $nowCounting
            ? 'Violation reinstated after review'
            : 'Violation removed after proof review';
        $msg = $nowCounting
            ? 'A violation that had been set aside has been reinstated and counts toward your record again.'
            : 'A violation was reviewed and the proof did not support it. It has been removed from your offense count.';
        $msg = function_exists('mb_substr') ? mb_substr($msg, 0, 255) : substr($msg, 0, 255);
        try { notify_once($conn, $studentId, $title, $msg, $violationId); } catch (Throwable $e) {}
    }

    if (function_exists('audit_log')) {
        audit_log($conn, 'Proof ' . $new, 'violations', $violationId,
                  'Proof review: ' . $old . ' -> ' . $new);
    }

    return [true, $new === 'Rejected'
        ? 'Marked rejected. The record stays in history but no longer counts toward the offense ladder.'
        : ($new === 'Approved'
            ? 'Marked approved — the proof supports this record.'
            : 'Decision cleared. The record counts as normal again.')];
}

/* =====================================================================
   MAJOR VIOLATIONS — TALK TO THE STUDENT FIRST
   ---------------------------------------------------------------------
   A Major (and a Grave, which this system folds in with Major everywhere
   else) cannot be rejected on proof review or deleted until the office has
   recorded that it was DISCUSSED with the student in person.

   WHY A GATE AND NOT A WARNING
   These two actions are how a Major stops counting, and a Major is the
   one offense a student carries at most one of — so making it disappear is
   the single most consequential thing anyone can do to a record here. Both
   routes previously ran on a form submit with nobody obliged to have
   spoken to the person it belongs to.

   WHY IT IS A RECORD AND NOT A CHECKBOX
   The conference is the thing that justifies the removal, so it is stored
   like evidence: who held it, when, and what was said. An audit that can
   see a Major was overturned but not that anyone met the student is not
   much of an audit.

   WHAT IT DOES NOT BLOCK
   Recording a Major, or approving its proof. The gate is only on the two
   routes that REMOVE a Major from a student's count — nothing here can stop
   a violation being filed, and nothing here delays the student being told
   about it.
   ===================================================================== */

/** Add the conference columns. Same idiom as the other ensures. */
function vts_ensure_discussion_columns($conn): void {
    static $done = false;
    if ($done) return;
    $done = true;
    $adds = [
        ['discussed_at',    'DATETIME NULL'],
        ['discussed_by',    'INT UNSIGNED NULL'],
        ['discussion_note', 'TEXT NULL'],
    ];
    foreach ($adds as [$col, $ddl]) {
        try {
            $chk = $conn->prepare("SELECT 1 FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'violations' AND COLUMN_NAME = :c");
            $chk->execute([':c' => $col]);
            if (!$chk->fetchColumn()) $conn->exec("ALTER TABLE violations ADD COLUMN `{$col}` {$ddl}");
        } catch (Throwable $e) { /* feature degrades, recording still works */ }
    }
}

/**
 * Is this violation locked pending a conversation with the student?
 * Returns [bool $blocked, string $why].
 *
 * Minor offenses are never blocked — they are routine, and the clearing
 * route for them was built precisely so the office does not have to hold a
 * meeting over a missing ID.
 */
function vts_discussion_required($conn, int $violationId): array {
    vts_ensure_discussion_columns($conn);
    try {
        $q = $conn->prepare("SELECT severity, discussed_at FROM violations WHERE id = :v LIMIT 1");
        $q->execute([':v' => $violationId]);
        $v = $q->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        /* Cannot tell — do not invent a block. A column that failed to be
           added must not freeze every Major in the system. */
        return [false, ''];
    }
    if (!$v) return [false, ''];

    $sev = (string)($v['severity'] ?? 'Minor');
    if ($sev !== 'Major' && $sev !== 'Grave') return [false, ''];
    if (!empty($v['discussed_at']))           return [false, ''];

    return [true, 'This is a ' . $sev . ' offense. It has to be discussed with the student in person '
                . 'and that conference recorded before it can be removed or rejected.'];
}

/**
 * Record that the conference happened. Admin/OSA only — the caller checks
 * the role, this checks everything else. Returns [bool $ok, string $msg].
 */
function vts_record_discussion($conn, int $violationId, int $adminId, string $note = ''): array {
    vts_ensure_discussion_columns($conn);

    $note = trim($note);
    $note = function_exists('mb_substr') ? mb_substr($note, 0, 2000) : substr($note, 0, 2000);
    /* The note is the substance of the record. An empty one would leave a
       Major unlocked by a click that says nothing about what was said. */
    $len = function_exists('mb_strlen') ? mb_strlen($note) : strlen($note);
    if ($len < 10) return [false, 'Write a short note on what was discussed — at least 10 characters.'];

    $q = $conn->prepare("SELECT id, severity, discussed_at FROM violations WHERE id = :v LIMIT 1");
    $q->execute([':v' => $violationId]);
    $v = $q->fetch(PDO::FETCH_ASSOC);
    if (!$v)                        return [false, 'That violation could not be found.'];
    if (!empty($v['discussed_at'])) return [false, 'A conference is already recorded for this violation.'];

    try {
        $conn->prepare("UPDATE violations
                           SET discussed_at = NOW(), discussed_by = :by, discussion_note = :n
                         WHERE id = :v")
             ->execute([':by' => $adminId, ':n' => $note, ':v' => $violationId]);
    } catch (Throwable $e) {
        error_log('Record discussion failed: ' . $e->getMessage());
        return [false, 'That did not go through. Please try again.'];
    }

    if (function_exists('audit_log')) {
        audit_log($conn, 'Conference Recorded', 'violations', $violationId,
                  'Discussed with student: ' . $note);
    }
    return [true, 'Conference recorded. This violation can now be rejected or deleted.'];
}

/**
 * May THIS person delete THIS violation? Returns [bool $allowed, string $why].
 *
 * WHY DELETING HAS ITS OWN RULE AND DOES NOT JUST CALL
 * vts_discussion_required()
 *
 * The conference gate above is one rule serving two actions, and the two are
 * not owned by the same people. Rejecting a proof is a JUDGEMENT on a record
 * — the photo does not support it — and the record survives the judgement,
 * so OSA make it and the conference has to come first for everyone.
 *
 * Deleting is not a judgement, it is an ERASURE. It is the one action here
 * that leaves nothing behind at all, which makes it a data-ownership question
 * rather than a disciplinary one, and data ownership in this system is
 * Admin's: Admin already holds the delete on student records, on staff
 * accounts and on the audit log itself, and none of those ask anyone's
 * permission either.
 *
 * So:
 *   Admin  full CRUD. A Major may be deleted with no conference on
 *          record. The audit row says the gate was passed without one,
 *          so the bypass is visible rather than silent — see
 *          admin/delete_violation.php.
 *   OSA    everything except that. They may delete a Minor freely, and a
 *          Major only once the conference is recorded, which is exactly
 *          where they stood before.
 *   anyone the page guards refuse them long before this is reached.
 *   else
 *
 * Callers must still check the ROLE may delete at all. This answers only
 * "is this particular record open to them", not "are they staff".
 */
function vts_can_delete_violation($conn, int $violationId, string $role): array {
    // Full CRUD. Nothing below applies.
    if ($role === 'Admin') return [true, ''];

    [$needsTalk, $whyTalk] = vts_discussion_required($conn, $violationId);
    if ($needsTalk) {
        return [false, $whyTalk . ' Only an Admin can remove it before then.'];
    }
    return [true, ''];
}

/* =====================================================================
   AUTOMATED VIOLATION RULES — REMOVED (2026-09)
   ---------------------------------------------------------------------
   This block held vts_ensure_rule_columns(), vts_violation_rule() and
   vts_apply_violation_rule(): a per-type `escalate_after` number that
   silently promoted a Minor to Major on the Nth offense of that type, and
   a `max_points` score multiplied by the offense number.

   Both are gone. They decided things about a record from settings that
   were invisible on the record itself, the office never used either, and
   the points figure was displayed nowhere in the app. A violation type is
   now just a name and Minor or Major — see admin/violation_rules.php,
   which manages that list and nothing else.

   The columns are left on violation_types rather than dropped, because a
   dropped column takes its data with it and these are cheap to ignore.
   Nothing reads them any more.
   ===================================================================== */
