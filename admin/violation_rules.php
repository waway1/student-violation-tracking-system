<?php
/* Admin: the list of violation types.

   WHAT THIS USED TO BE
   A rules engine. Each type carried `max_points` (a score, multiplied by the
   offense number on every record) and `escalate_after` (a count after which a
   Minor silently became a Major). Both are gone — see the note where they
   were removed in includes/functions.php.

   They went because they decided things about a record from settings that
   were invisible on the record itself. A slip said "Major" and nothing on it
   explained whether that was the type's severity or an escalation rule
   nobody could see; the points figure was stored on every row and displayed
   on no page in the app.

   WHAT IT IS NOW
   The list, and only the list: add a violation type, delete one, and say
   whether it is Minor or Major. Repetition is tracked by the 1st/2nd/3rd
   offense ladder, which is derived from the records themselves and can be
   read off any student's history.

   `critical_alert` is kept — it is not a scoring rule but a routing one:
   a type marked critical alerts every Admin the moment it is recorded
   (violation_is_critical(), notify_critical()). Dropping the control would
   leave the feature live but unmanageable.

   Admin only. OSA record against this list every day, but changing what is
   on it is a policy decision, not a recording one. */
require_once "../auth/auth.php";
require_once "../config/database.php";
require_once "../includes/functions.php";

if (($_SESSION['role'] ?? '') !== 'Admin') {
    vts_deny_access();
}

vts_ensure_categorization_columns($conn);   // critical_alert

/* ---- Add / delete / save ---- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        vts_redirect_back('violation_rules.php', 'error', 'Session expired. Please try again.');
        exit();
    }

    /* ---- Add one ---- */
    if (isset($_POST['add_type'])) {
        $name = trim((string)($_POST['violation_name'] ?? ''));
        $sev  = in_array($_POST['new_severity'] ?? '', ['Minor', 'Major'], true) ? $_POST['new_severity'] : 'Minor';
        $crit = !empty($_POST['new_critical']) ? 1 : 0;

        if ($name === '') {
            vts_redirect_back('violation_rules.php', 'error', 'Give the violation a name.');
            exit();
        }
        if (mb_strlen($name) > 150) {
            vts_redirect_back('violation_rules.php', 'error', 'That name is too long (150 characters maximum).');
            exit();
        }
        /* Case-insensitive: "Improper Uniform" and "improper uniform" would be
           two rows here but the SAME string on a record, so the list would
           disagree with itself about the severity of one violation. */
        $dupe = $conn->prepare("SELECT 1 FROM violation_types WHERE LOWER(violation_name) = LOWER(:n) LIMIT 1");
        $dupe->execute([':n' => $name]);
        if ($dupe->fetchColumn()) {
            vts_redirect_back('violation_rules.php', 'error', '“' . $name . '” is already on the list.');
            exit();
        }
        try {
            $conn->prepare("INSERT INTO violation_types (violation_name, severity, critical_alert)
                            VALUES (:n, :s, :c)")
                 ->execute([':n' => $name, ':s' => $sev, ':c' => $crit]);
            if (function_exists('audit_log')) {
                audit_log($conn, 'Violation Type Added', 'violation_types', 0, $name . ' (' . $sev . ')');
            }
            vts_redirect_back('violation_rules.php', 'success', '“' . $name . '” added.');
        } catch (Throwable $e) {
            error_log('Add violation type failed: ' . $e->getMessage());
            vts_redirect_back('violation_rules.php', 'error', 'That did not go through. Please try again.');
        }
        exit();
    }

    /* ---- Delete one ----
       Records are NOT touched. violations.violation stores the name as text,
       so a student's history keeps reading correctly after the type it was
       filed under stops being offerable. Removing a type takes it off the
       list of things that can be recorded from now on — nothing more. */
    if (isset($_POST['delete_type'])) {
        $id = (int)($_POST['id'] ?? 0);
        $n  = $conn->prepare("SELECT violation_name FROM violation_types WHERE id = :i");
        $n->execute([':i' => $id]);
        $name = (string)$n->fetchColumn();
        if ($name === '') {
            vts_redirect_back('violation_rules.php', 'error', 'That violation type could not be found.');
            exit();
        }
        try {
            $conn->prepare("DELETE FROM violation_types WHERE id = :i")->execute([':i' => $id]);
            if (function_exists('audit_log')) {
                audit_log($conn, 'Violation Type Deleted', 'violation_types', $id, $name);
            }
            vts_redirect_back('violation_rules.php', 'success',
                '“' . $name . '” removed from the list. Records already filed under it are unchanged.');
        } catch (Throwable $e) {
            error_log('Delete violation type failed: ' . $e->getMessage());
            vts_redirect_back('violation_rules.php', 'error', 'That did not go through. Please try again.');
        }
        exit();
    }

    /* ---- Save the severities ---- */
    $ids   = (array)($_POST['id']       ?? []);
    $sevIn = (array)($_POST['severity'] ?? []);
    $crIn  = (array)($_POST['critical'] ?? []);

    $up = $conn->prepare("UPDATE violation_types SET severity = :s, critical_alert = :c WHERE id = :id");
    $changed = 0;
    try {
        $conn->beginTransaction();
        foreach ($ids as $rowId) {
            $rowId = (int)$rowId;
            if ($rowId <= 0) continue;
            /* Clamp rather than trust — these come back as form fields, and a
               hand-edited post must not be able to write a severity the rest
               of the app does not understand. */
            $sev  = in_array($sevIn[$rowId] ?? 'Minor', ['Minor', 'Major'], true) ? $sevIn[$rowId] : 'Minor';
            $crit = !empty($crIn[$rowId]) ? 1 : 0;
            $up->execute([':s' => $sev, ':c' => $crit, ':id' => $rowId]);
            $changed += $up->rowCount();
        }
        $conn->commit();
    } catch (Throwable $e) {
        if ($conn->inTransaction()) $conn->rollBack();
        error_log('Save violation types failed: ' . $e->getMessage());
        vts_redirect_back('violation_rules.php', 'error', 'That did not go through. Please try again.');
        exit();
    }

    vts_redirect_back('violation_rules.php', 'success',
        $changed > 0 ? 'Saved.' : 'Nothing needed changing.');
    exit();
}

/* ---- The list ----
   Each type carries how many records have been filed under it, so an admin
   about to delete one can see what it has been used for. */
$search = trim((string)($_GET['search'] ?? ''));
$params = [];
$where  = '';
if ($search !== '') {
    $where = "WHERE t.violation_name LIKE :q";
    $params[':q'] = '%' . $search . '%';
}
$q = $conn->prepare("SELECT t.id, t.violation_name, t.severity, t.critical_alert,
                            (SELECT COUNT(*) FROM violations v
                              WHERE v.violation = t.violation_name) AS used_count
                     FROM violation_types t
                     {$where}
                     ORDER BY t.violation_name");
$q->execute($params);
$types = $q->fetchAll(PDO::FETCH_ASSOC);

$assetBase = "../";
include "../includes/header.php";
include "../includes/navbar.php";
include "../includes/sidebar.php";
?>
<link rel="stylesheet" href="../assets/css/violation-types.css?v=<?php echo @filemtime(__DIR__."/../assets/css/violation-types.css"); ?>">
<main class="vts-main">
<div class="vts-main-inner">

  <div class="admin-welcome-row">
    <div>
      <h1><i class="fas fa-list-check"></i> Violation Types</h1>
      <p>What can be recorded, and whether each one is Minor or Major.</p>
    </div>
  </div>

  <?php if (isset($_GET['success'])): ?>
    <div class="alert alert-success u-mb-14" role="status">
      <i class="fas fa-circle-check"></i> <?php echo htmlspecialchars($_GET['success']); ?>
    </div>
  <?php endif; ?>
  <?php if (isset($_GET['error'])): ?>
    <div class="alert alert-error u-mb-14" role="alert">
      <i class="fas fa-circle-exclamation"></i> <?php echo htmlspecialchars($_GET['error']); ?>
    </div>
  <?php endif; ?>

  <!-- ---- Add ---- -->
  <div class="recent-table-wrap is-panel u-mb-14">
    <h3 class="card-label">Add a violation type</h3>
    <form method="POST" class="vt-add">
      <?php echo csrf_field(); ?>
      <div class="vt-add-name">
        <label for="violation_name">Name</label>
        <input id="violation_name" type="text" name="violation_name" class="vts-input"
               maxlength="150" required placeholder="e.g. Littering">
      </div>
      <div class="vt-add-sev">
        <label for="new_severity">Kind</label>
        <select id="new_severity" name="new_severity" class="vts-input">
          <option value="Minor">Minor</option>
          <option value="Major">Major</option>
        </select>
      </div>
      <label class="vt-crit" title="Alerts every Admin the moment this is recorded">
        <input type="checkbox" name="new_critical" value="1"> Critical
      </label>
      <button type="submit" name="add_type" value="1" class="btn-primary btn-sm">
        <i class="fas fa-plus"></i> Add
      </button>
    </form>
  </div>

  <!-- ---- The list ---- -->
  <div class="recent-table-wrap is-panel">

    <form method="GET" class="vts-filter-row">
      <input type="text" name="search" class="vts-table-filter" placeholder="Search violation types…"
             aria-label="Search violation types" value="<?php echo htmlspecialchars($search); ?>">
      <button type="submit" class="btn-primary btn-sm"><i class="fas fa-search"></i> Search</button>
      <?php if ($search !== ''): ?>
        <a href="violation_rules.php" class="btn-outline btn-sm"><i class="fas fa-times"></i> Reset</a>
      <?php endif; ?>
    </form>

    <?php if (!$types): ?>
      <div class="vt-empty">
        <i class="fas fa-list-check"></i>
        <p><?php echo $search !== '' ? 'No violation type matches that search.' : 'There are no violation types yet.'; ?></p>
      </div>
    <?php else: ?>

      <?php /* One form for the whole table: an admin reclassifying several
               types at once should press Save once, not once per row. Delete
               is its own form per row — it is a different, irreversible act
               and must not ride along with a Save. */ ?>
      <form method="POST" id="vtSaveForm">
        <?php echo csrf_field(); ?>
        <div class="table-scroll table-responsive">
          <table class="data-table">
            <thead>
              <tr><th>Violation</th><th>Kind</th><th>Critical</th><th>Records</th><th>Action</th></tr>
            </thead>
            <tbody>
              <?php foreach ($types as $t): $id = (int)$t['id']; ?>
              <tr>
                <td><?php echo htmlspecialchars($t['violation_name']); ?></td>
                <td>
                  <input type="hidden" name="id[]" value="<?php echo $id; ?>">
                  <label class="sr-only" for="severity-<?php echo $id; ?>">Severity for <?php echo htmlspecialchars($t['violation_name']); ?></label>
                  <select id="severity-<?php echo $id; ?>" name="severity[<?php echo $id; ?>]" class="vts-input vt-sev">
                    <option value="Minor" <?php echo $t['severity'] === 'Minor' ? 'selected' : ''; ?>>Minor</option>
                    <option value="Major" <?php echo $t['severity'] === 'Major' ? 'selected' : ''; ?>>Major</option>
                  </select>
                </td>
                <td>
                  <input type="checkbox" name="critical[<?php echo $id; ?>]" value="1"
                         <?php echo (int)$t['critical_alert'] === 1 ? 'checked' : ''; ?>
                         aria-label="Alert every Admin when <?php echo htmlspecialchars($t['violation_name']); ?> is recorded">
                </td>
                <td class="nowrap"><?php echo (int)$t['used_count']; ?></td>
                <td class="nowrap">
                  <?php /* Deleting posts to its own form (below the table),
                           reached by form="vtDeleteForm". Nesting a form
                           inside the Save form is invalid HTML and browsers
                           silently drop the inner one, so the button carries
                           the row id and the outer form carries the intent. */ ?>
                  <button type="submit" form="vtDeleteForm" name="id" value="<?php echo $id; ?>"
                          class="btn-danger btn-sm"
                          onclick="return confirm('Remove “<?php echo htmlspecialchars($t['violation_name'], ENT_QUOTES); ?>” from the list?\n\nThe <?php echo (int)$t['used_count']; ?> record(s) already filed under it are NOT deleted — it just stops being offerable for new violations.');"
                          title="Remove this violation type" aria-label="Remove this violation type">
                    <i class="fas fa-trash"></i>
                  </button>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>

        <div class="vt-save-row">
          <button type="submit" class="btn-primary btn-sm"><i class="fas fa-check"></i> Save changes</button>
          <span class="vt-hint">Records already filed keep the kind they were given.</span>
        </div>
      </form>

      <?php /* The delete target, outside the save form (nesting forms is
               invalid HTML and browsers silently drop the inner one). Each
               row's button points at it with form="vtDeleteForm". */ ?>
      <form method="POST" id="vtDeleteForm">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="delete_type" value="1">
      </form>

    <?php endif; ?>
  </div>

<?php include "../includes/footer.php"; ?>
