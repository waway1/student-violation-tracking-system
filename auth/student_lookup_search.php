<?php
/* AJAX: live name/School-ID search for the passwordless student login.
   Returns a small JSON list of matching enrolled students (from the official
   roster AND already-registered student accounts). No password involved —
   the student picks their name here, then confirms their School ID on submit. */
require_once "../config/database.php";
require_once "../includes/functions.php";

header('Content-Type: application/json');

/* WHAT THIS ENDPOINT MAY AND MAY NOT HAND OUT.

   It is public by necessity — it runs before anyone has signed in — so
   everything it returns is public. It still NEVER returns a school_id: the
   page does not show one, and when the server used to send it anyway, two
   characters of a common surname handed an anonymous caller nine students'
   School IDs, which was the whole of their login.

   MATCHING ON A SCHOOL ID IS DIFFERENT FROM RETURNING ONE, and the two
   directions carry different risk:

     name -> name   safe. Names are meant to be findable; that is the feature.
     ID   -> name   depends entirely on whether the account has a password.

   For an account WITH a password, the name is not a credential — the password
   is — so answering "1242…" with "Juan Dela Cruz" gives an attacker nothing
   they can use. For an account WITHOUT one, the surname IS the other half of
   the credential (School ID + surname signs them in), so answering the same
   question hands over a working login to anyone holding a photographed ID
   card or QR code.

   So an ID prefix matches ONLY accounts that already have a password. Typing
   your ID finds you the moment you have one; until then you are found by name
   and your ID stays private. The gate disappears on its own as students set
   passwords.  */
$q = trim($_GET['q'] ?? '');

/* Digits (with the odd dash) mean they are typing an ID, not a name. */
$looksLikeId = (bool)preg_match('/^[0-9][0-9\-]*$/', $q);

/* HOW SHORT A QUERY IS ALLOWED, AND WHY IT DIFFERS.

   A NAME needs 3 characters. Two used to be enough and one request came back
   with nine students; a short string matches anywhere inside the name, and the
   enrollment roster — which has no password to gate it — is searched too.

   AN ID may be a single digit. It is a different exposure: an ID matches from
   the START only, and the ID branch answers solely for accounts that already
   have a password, where the name is not a credential (see the note above).
   The most a short ID prefix can produce is a handful of names that a name
   search would have found anyway, and never a School ID. So typing the first
   one, two or three digits of your own ID brings you straight up. */
$minLen = $looksLikeId ? 1 : 3;
if (mb_strlen($q) < $minLen) { echo json_encode([]); exit(); }

$like   = '%' . $q . '%';
$idLike = $q . '%';        // IDs match from the START, not anywhere inside
$out    = [];   // keyed by School ID so the two sources can't produce duplicates

try {
    vts_ensure_password_flag($conn);

    /* Registered accounts. A name matches any account; an ID prefix matches
       only accounts that already have a password — see the note at the top
       for why those two are not the same question. */
    if ($looksLikeId) {
        $u = $conn->prepare(
            "SELECT student_id, fullname, lastname, firstname, course, year_level
               FROM users
              WHERE role = 'Student' AND student_id IS NOT NULL
                AND has_password = 1
                AND student_id LIKE :a
              ORDER BY student_id LIMIT 12");
        $u->execute([':a' => $idLike]);
    } else {
        $u = $conn->prepare(
            "SELECT student_id, fullname, lastname, firstname, course, year_level
               FROM users
              WHERE role = 'Student' AND student_id IS NOT NULL
                AND fullname LIKE :a
              ORDER BY fullname LIMIT 12");
        $u->execute([':a' => $like]);
    }
    foreach ($u->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $sid = strtoupper((string)$r['student_id']);
        if ($sid === '') continue;
        // $sid is the array key only — it de-duplicates the two sources and
        // never reaches the response body below.
        $out[$sid] = [
            'name'      => $r['fullname'] ?: trim(($r['firstname'] ?? '') . ' ' . ($r['lastname'] ?? '')),
            'lastname'  => $r['lastname'] ?? '',
            'course'    => $r['course'] ?? '',
            'year'      => $r['year_level'] ?? '',
        ];
    }

    // Enrolled roster (may not have registered yet).
    /* The enrollment roster holds no password, so an ID typed against it
       could only ever be the unprotected ID -> name lookup. Names only. */
    $ro = $looksLikeId ? null : $conn->prepare(
        "SELECT school_id, lastname, firstname, course, year_level
           FROM student_roster
          WHERE lastname LIKE :a OR firstname LIKE :b
          ORDER BY lastname, firstname LIMIT 12");
    if ($ro) $ro->execute([':a' => $like, ':b' => $like]);
    foreach (($ro ? $ro->fetchAll(PDO::FETCH_ASSOC) : []) as $r) {
        $sid = strtoupper((string)$r['school_id']);
        if ($sid === '' || isset($out[$sid])) continue;   // users row wins
        $name = trim(($r['firstname'] ?? '') . ' ' . ($r['lastname'] ?? ''));
        if ($name === '') continue;   // nothing to show but the ID, which stays private
        $out[$sid] = [
            'name'      => $name,
            'lastname'  => $r['lastname'] ?? '',
            'course'    => $r['course'] ?? '',
            'year'      => $r['year_level'] ?? '',
        ];
    }
} catch (Throwable $e) {
    echo json_encode([]); exit();
}

echo json_encode(array_values($out));
