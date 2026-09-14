<?php
/* =====================================================================
   RESTORE a .sql backup back INTO the live system.
   ---------------------------------------------------------------------
   Taking backups was only ever half the job: the app could write a dump
   and hand it to you, but nothing could put one back, so a backup was
   only usable by someone willing to open phpMyAdmin.

   One detail of the dump shape matters a great deal here.
   vts_build_sql_dump() files the STUDENTS into a side table --
   `students_backup` -- and only the violations into their real table.
   Replaying the SQL on its own therefore restores the violations and
   leaves every student sitting in a table the application never reads:
   the restore would report success and the students would still be gone.
   So once the SQL has been replayed, the rows in `students_backup` are
   merged back into `users`, which is what actually puts the system back.
   ===================================================================== */

/* Split a dump into individual statements.

   The dump carries a CREATE TABLE captured from SHOW CREATE TABLE, which
   spans many lines, so it cannot be split on newlines -- only on a `;`
   that is not inside a quoted string, a quoted identifier, or a comment. */
function vts_sql_split($sql) {
    $out = array();
    $buf = '';
    $inS = false; $inD = false; $inB = false; $inLine = false; $inBlock = false;
    $len = strlen($sql);

    for ($i = 0; $i < $len; $i++) {
        $c = $sql[$i];
        $n = ($i + 1 < $len) ? $sql[$i + 1] : '';

        if ($inLine)  { $buf .= $c; if ($c === "\n") { $inLine = false; } continue; }
        if ($inBlock) { $buf .= $c; if ($c === '*' && $n === '/') { $buf .= $n; $i++; $inBlock = false; } continue; }

        if (!$inS && !$inD && !$inB) {
            if ($c === '-' && $n === '-') { $inLine  = true; $buf .= $c; continue; }
            if ($c === '#')               { $inLine  = true; $buf .= $c; continue; }
            if ($c === '/' && $n === '*') { $inBlock = true; $buf .= $c; continue; }
        }

        // A backslash escapes the next character inside a string literal.
        if ($c === "\\" && ($inS || $inD)) {
            $buf .= $c;
            if ($n !== '') { $buf .= $n; $i++; }
            continue;
        }

        if     ($c === "'" && !$inD && !$inB) { $inS = !$inS; }
        elseif ($c === '"' && !$inS && !$inB) { $inD = !$inD; }
        elseif ($c === '`' && !$inS && !$inD) { $inB = !$inB; }

        if ($c === ';' && !$inS && !$inD && !$inB) {
            $t = trim($buf);
            if ($t !== '') { $out[] = $t; }
            $buf = '';
            continue;
        }
        $buf .= $c;
    }

    $t = trim($buf);
    if ($t !== '') { $out[] = $t; }

    // Drop anything that is only comments or blank lines.
    return array_values(array_filter($out, function ($s) {
        foreach (preg_split('/\r\n|\r|\n/', $s) as $line) {
            $line = trim($line);
            if ($line === '' || substr($line, 0, 2) === '--' || substr($line, 0, 1) === '#') { continue; }
            return true;
        }
        return false;
    }));
}

/* Does this text actually look like a dump this system wrote?
   Replaying an arbitrary .sql into the live database is not something to
   do on trust, so a file that mentions neither this system nor its own
   tables is refused before anything is touched. */
function vts_sql_looks_like_vts_backup($sql) {
    if (stripos($sql, 'VTS Backup') !== false) { return true; }
    return (stripos($sql, '`violations`') !== false || stripos($sql, '`students_backup`') !== false);
}

/* Move the students the dump parked in `students_backup` back into `users`.

   Matched on student_id, because the dump's `id` values belong to the
   database the backup came from and may collide with unrelated rows here.
   A student who still exists is updated in place; one who had been removed
   is re-created. Staff accounts are never touched: only Student rows are
   written, and username/password/role are never taken from the dump. */
function vts_restore_students_from_backup_table($conn) {
    $res = array('restored' => 0, 'created' => 0);
    try {
        $has = $conn->query("SHOW TABLES LIKE 'students_backup'")->fetchColumn();
        if (!$has) { return $res; }

        $bCols = $conn->query("SHOW COLUMNS FROM `students_backup`")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('student_id', $bCols, true)) { return $res; }

        $userCols = $conn->query("SHOW COLUMNS FROM `users`")->fetchAll(PDO::FETCH_COLUMN);
        $rows     = $conn->query("SELECT * FROM `students_backup`")->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as $r) {
            $sid = trim((string)(isset($r['student_id']) ? $r['student_id'] : ''));
            if ($sid === '') { continue; }

            $find = $conn->prepare("SELECT id FROM users WHERE student_id = :s LIMIT 1");
            $find->execute(array(':s' => $sid));
            $existing = $find->fetchColumn();

            // Only columns present on BOTH tables, minus the identity ones.
            $use = array_values(array_intersect(array_keys($r), $userCols));
            $use = array_values(array_diff($use, array('id', 'username', 'password', 'role')));

            if ($existing) {
                $set  = array();
                $bind = array(':id' => (int)$existing);
                foreach ($use as $c) {
                    $set[] = "`{$c}` = :{$c}";
                    $bind[":{$c}"] = $r[$c];
                }
                if ($set) {
                    $conn->prepare("UPDATE users SET " . implode(',', $set) . " WHERE id = :id")->execute($bind);
                    $res['restored']++;
                }
            } else {
                /* Re-create a student who had been deleted. Students sign in by
                   looking themselves up and typing their own School ID, so the
                   password column just needs to hold something unusable. */
                $cNames = $use;
                $cNames[] = 'username';
                $cNames[] = 'password';
                $cNames[] = 'role';

                $bind = array();
                foreach ($use as $c) { $bind[':' . $c] = $r[$c]; }
                $bind[':username'] = 'stu_' . $sid;
                $bind[':password'] = password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT);
                $bind[':role']     = 'Student';

                $ph = array();
                foreach ($cNames as $c) { $ph[] = ':' . $c; }
                $quoted = array_map(function ($c) { return "`{$c}`"; }, $cNames);

                $sql = "INSERT INTO users (" . implode(',', $quoted) . ") VALUES (" . implode(',', $ph) . ")";
                try {
                    $conn->prepare($sql)->execute($bind);
                    $res['created']++;
                } catch (Throwable $e) {
                    error_log('restore student failed (' . $sid . '): ' . $e->getMessage());
                }
            }
        }
    } catch (Throwable $e) {
        error_log('vts_restore_students_from_backup_table: ' . $e->getMessage());
    }
    return $res;
}

/* Replay a dump into the live database.

   ALWAYS snapshots the current state first (backups/auto/auto_prerestore_*),
   so a restore started by mistake is itself undoable -- the one thing a
   restore feature must never get wrong. */
function vts_restore_sql_dump($conn, $sql) {
    $out = array(
        'ok'         => false,
        'statements' => 0,
        'failed'     => 0,
        'error'      => '',
        'students'   => array('restored' => 0, 'created' => 0),
        'safety'     => null,
    );

    if (trim($sql) === '') {
        $out['error'] = 'That backup file is empty.';
        return $out;
    }
    if (!vts_sql_looks_like_vts_backup($sql)) {
        $out['error'] = "That file does not look like a VTS backup, so nothing was changed. "
                      . "Use a .sql written by this system's Backup page.";
        return $out;
    }

    // Snapshot BEFORE touching anything.
    try {
        if (function_exists('vts_auto_backup')) {
            $out['safety'] = vts_auto_backup($conn, 'prerestore');
        }
    } catch (Throwable $e) { /* never block the restore on the safety copy */ }

    $stmts = vts_sql_split($sql);
    if (!$stmts) {
        $out['error'] = 'No SQL statements were found in that file.';
        return $out;
    }

    try { $conn->exec('SET FOREIGN_KEY_CHECKS = 0'); } catch (Throwable $e) {}

    foreach ($stmts as $st) {
        try {
            $conn->exec($st);
            $out['statements']++;
        } catch (Throwable $e) {
            $out['failed']++;
            if ($out['error'] === '') { $out['error'] = $e->getMessage(); }
            error_log('restore statement failed: ' . substr($st, 0, 120) . ' -- ' . $e->getMessage());
        }
    }

    // The part that actually puts the students back where the app reads them.
    $out['students'] = vts_restore_students_from_backup_table($conn);

    try { $conn->exec('SET FOREIGN_KEY_CHECKS = 1'); } catch (Throwable $e) {}

    $out['ok'] = ($out['statements'] > 0 && $out['failed'] === 0);
    return $out;
}
