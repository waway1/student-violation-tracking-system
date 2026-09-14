<?php
/* API (JSON) behind the navbar bell's notification panel.

   The bell used to be a plain link: reading one notification meant leaving
   whatever page you were on and finding your way back. The panel reads from
   here instead, so it opens over the page rather than replacing it.

   The full pages (student/notifications.php, admin/notifications.php) are
   still there and the panel links to them — search, status filters and paging
   live on those, and bulk deletes belong somewhere with room to confirm them.
   This endpoint deliberately offers only what a bell panel should: read the
   latest, mark them all read, and dismiss one.

   GET                       -> { ok, unread, total, items[] }
   POST action=mark_all_read -> { ok, unread, total, items[] }
   POST action=delete&id=N   -> { ok, unread, total, items[] }
   POST action=clear         -> { ok, … }  student: delete all of theirs;
                                            Admin/OSA: delete the READ ones

   Every response carries the fresh counts and list, so the caller never has
   to guess what the badge should say after an action. */
require_once __DIR__ . "/../auth/session.php";
require_once __DIR__ . "/../config/database.php";
require_once __DIR__ . "/../includes/functions.php";   // csrf_verify(), vts_time_ago()

header("Content-Type: application/json");
header("Cache-Control: no-store");

function notif_out(array $payload, int $code = 200) {
    http_response_code($code);
    echo json_encode($payload);
    exit();
}

if (!isset($_SESSION['user_id'])) {
    notif_out(["ok" => false, "error" => "Please sign in again."], 401);
}

$role   = $_SESSION['role'] ?? '';
$userId = (int)$_SESSION['user_id'];

/* WHO MAY SEE AND TOUCH WHAT.

   A student may only ever reach their own rows. The previous version of this
   file took the id from the query string (?user=<n>), so changing the number
   read somebody else's notifications — their violations, by name. The id now
   comes from the session and nowhere else.

   Admin/OSA see every row, which is already what their bell counts. Guard and
   OSA Staff have no notification feed, so they get a clean 403 rather than an
   empty list that looks like a bug. */
$isOverseer = in_array($role, ['Admin', 'OSA'], true);
if (!$isOverseer && $role !== 'Student') {
    notif_out(["ok" => false, "error" => "This account has no notifications."], 403);
}

/* One scope, applied to every read and every write below, so a new query
   cannot accidentally be written without it. */
$scopeSql    = $isOverseer ? "" : " AND n.user_id = :me";
$scopeSqlRaw = $isOverseer ? "" : " AND user_id = :me";     // no alias, for UPDATE/DELETE
$scopeParams = $isOverseer ? [] : [':me' => $userId];

/** The panel's payload: newest few, plus the counts the badge needs. */
function notif_payload(PDO $conn, string $scopeSql, string $scopeSqlRaw, array $scopeParams, bool $isOverseer): array {
    $limit = 12;   // a panel, not the archive — "View all" goes to the full page

    $st = $conn->prepare(
        "SELECT n.id, n.title, n.message, n.is_read, n.created_at, u.fullname AS recipient
         FROM notifications n
         LEFT JOIN users u ON n.user_id = u.id
         WHERE 1=1 {$scopeSql}
         ORDER BY n.created_at DESC
         LIMIT {$limit}");
    $st->execute($scopeParams);

    $items = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $items[] = [
            "id"      => (int)$r['id'],
            "title"   => (string)($r['title'] ?? ''),
            "message" => (string)($r['message'] ?? ''),
            "unread"  => (int)$r['is_read'] === 0,
            "when"    => vts_time_ago($r['created_at']),
            "exact"   => vts_datetime($r['created_at']),
            // Only an overseer's panel shows who it went to; a student already
            // knows, and printing their own name on every row is just noise.
            "who"     => $isOverseer ? (string)($r['recipient'] ?? '') : "",
        ];
    }

    $uc = $conn->prepare("SELECT COUNT(*) FROM notifications n WHERE n.is_read = 0 {$scopeSql}");
    $uc->execute($scopeParams);
    $tc = $conn->prepare("SELECT COUNT(*) FROM notifications n WHERE 1=1 {$scopeSql}");
    $tc->execute($scopeParams);

    return [
        "ok"     => true,
        // Drives the bulk-clear button's wording in the panel.
        "scope"  => $isOverseer ? "all" : "mine",
        "unread" => (int)$uc->fetchColumn(),
        "total"  => (int)$tc->fetchColumn(),
        "items"  => $items,
    ];
}

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        // Anything that changes state is a POST and carries the same token the
        // rest of the app's forms do.
        if (!csrf_verify()) {
            notif_out(["ok" => false, "error" => "This page was open too long. Reload and try again."], 419);
        }

        $action = $_POST['action'] ?? '';
        if ($action === 'mark_all_read') {
            $conn->prepare("UPDATE notifications SET is_read = 1 WHERE is_read = 0 {$scopeSqlRaw}")
                 ->execute($scopeParams);

        } elseif ($action === 'delete') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) notif_out(["ok" => false, "error" => "Nothing to delete."], 400);
            // The scope is what stops a student deleting someone else's row by
            // sending its id.
            $d = $conn->prepare("DELETE FROM notifications WHERE id = :id {$scopeSqlRaw}");
            $d->execute($scopeParams + [':id' => $id]);

        } elseif ($action === 'clear') {
            /* The bulk clear, and it means different things by role — matching
               exactly what each full page already offers, so the panel is not
               quietly more destructive than the page it replaces:
                 student   -> delete all of their own notifications
                 Admin/OSA -> delete the READ ones only, never unread alerts
               Anything broader than the caller's own scope is impossible
               because $scopeSqlRaw is appended either way. */
            if ($isOverseer) {
                $conn->prepare("DELETE FROM notifications WHERE is_read = 1 {$scopeSqlRaw}")
                     ->execute($scopeParams);
            } else {
                $conn->prepare("DELETE FROM notifications WHERE 1=1 {$scopeSqlRaw}")
                     ->execute($scopeParams);
            }

        } else {
            notif_out(["ok" => false, "error" => "Unknown action."], 400);
        }
    }

    notif_out(notif_payload($conn, $scopeSql, $scopeSqlRaw, $scopeParams, $isOverseer));

} catch (Throwable $e) {
    error_log('Notifications API failed: ' . $e->getMessage());
    notif_out(["ok" => false, "error" => "Notifications are unavailable right now."], 500);
}
