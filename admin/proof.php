<?php
/* Admin / OSA: look at the proof attached to a violation.

   WHAT THIS IS FOR
   A violation carries a reason and, where one was taken, a photo. Those two
   have to agree — a record saying "improper uniform" with a picture of an
   empty corridor is not evidence of anything. Until now the only place a
   photo could be seen at all was a 34px thumbnail in a table column, which
   is enough to know a file exists and not enough to judge it.

   THE DECISION
   Approve says the proof supports the record. Reject says it does not, and
   from then on the row stays in history but stops counting toward the
   student's offense ladder.

   THE DECISION IS THE WHOLE ANSWER
   There is no note field, deliberately. This page asks one question — does
   the photo show what the record claims — and Approve/Reject answers it.
   A free-text box beside that invited commentary ON A STUDENT from someone
   whose job here is to read a photograph, and it was then posted to the
   student in their notification. Reviewing proof is not the place to write
   about a person. What a meeting with the student established is a different
   thing and has its own field — see vts_record_discussion().

   Pending is NOT a holding pen. upgrade_2026-07.sql removed an earlier
   approve/reject flow because violations are direct records, final the
   moment they are recorded, and that has not changed: the scanner is
   offline-first, and a shift's scans can arrive days later on a USB. If a
   record only counted once somebody had blessed it, every one of those
   would sit invisible in the meantime. So an unreviewed violation counts
   exactly as it always did, and a review can only ever SUBTRACT.

   A decision is reversible — rejecting the wrong row must be undoable —
   and the ladder is re-derived each time. See vts_decide_proof().

   WHO RECORDED IT IS NOT SHOWN HERE
   Deliberately. This page exists to be looked at — over a shoulder, on a
   projector, with a student or parent in the room — and naming the guard
   who filed a violation in that setting puts a person at risk over a
   dispute that is not theirs. It is the same reason
   admin/violations_only.php withholds it. The name is still on the record
   and still in the audit log for anyone who needs it.

   Guard and OSA Staff are not here on purpose: they record violations, and
   judging one belongs with the office that owns the record. */
require_once "../auth/auth.php";
require_once "../config/database.php";
require_once "../includes/functions.php";

if (!in_array($_SESSION['role'] ?? '', ['Admin', 'OSA'], true)) {
    vts_deny_access();
}

vts_ensure_proof_columns($conn);
vts_ensure_discussion_columns($conn);
$adminId = (int)$_SESSION['user_id'];
/* Admin and OSA both work this page, and they do not have the same
   delete. See vts_can_delete_violation() — the button below only
   mirrors what that decides. */
$vtsIsAdmin = (($_SESSION['role'] ?? '') === 'Admin');

/* ---- Deciding ----
   Before any output: this branch redirects. vts_decide_proof() owns the
   rules — it re-derives the offense ladder when a decision changes what
   counts, and tells the student when their record improves. */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        vts_redirect_back('proof.php', 'error', 'Session expired. Please try again.');
        exit();
    }
    /* Recording the conference with the student. Its own branch, because it
       is not a judgement on the proof — it is the thing that has to happen
       before a Major can be judged at all. */
    if (isset($_POST['record_discussion'])) {
        [$ok, $msg] = vts_record_discussion($conn,
                                            (int)($_POST['violation_id'] ?? 0),
                                            $adminId,
                                            (string)($_POST['discussion_note'] ?? ''));
        vts_redirect_back('proof.php', $ok ? 'success' : 'error', $msg);
        exit();
    }

    [$ok, $msg] = vts_decide_proof($conn,
                                   (int)($_POST['violation_id'] ?? 0),
                                   $adminId,
                                   (string)($_POST['decision'] ?? ''));
    vts_redirect_back('proof.php', $ok ? 'success' : 'error', $msg);
    exit();
}

/* ---- What to show ----
   `all` is the default rather than `with`: "which records have no proof at
   all" is as much a review question as "does this photo match", and a tab
   nobody opens hides it. */
$search = trim((string)($_GET['search'] ?? ''));
/* The only axis left is what has been DECIDED about the proof. A
   with-proof / no-proof split used to sit alongside it; proof is required
   to record a violation on the web form, so "no proof" is not a category
   the office browses. */
$review = in_array($_GET['review'] ?? '', ['Pending', 'Approved', 'Rejected'], true) ? $_GET['review'] : 'any';

$where  = ["1=1"];
$params = [];
if ($review !== 'any') {
    $where[] = "v.proof_status = :rv";
    $params[':rv'] = $review;
}
if ($search !== '') {
    $where[] = "(s.fullname LIKE :q OR s.student_id LIKE :q OR v.violation LIKE :q)";
    $params[':q'] = '%' . $search . '%';
}
$whereSql = implode(' AND ', $where);

/* How many are waiting on a decision, and how the decided ones went. */
$reviewCounts = [];
try {
    foreach ($conn->query("SELECT proof_status, COUNT(*) AS n FROM violations GROUP BY proof_status") as $r) {
        $reviewCounts[(string)$r['proof_status']] = (int)$r['n'];
    }
    $reviewCounts += ['Pending' => 0, 'Approved' => 0, 'Rejected' => 0];
} catch (Throwable $e) { $reviewCounts = []; }

$perPage = 12;
$page    = (isset($_GET['page']) && is_numeric($_GET['page'])) ? max(1, (int)$_GET['page']) : 1;

$cnt = $conn->prepare("SELECT COUNT(*) FROM violations v INNER JOIN users s ON v.student_id = s.id WHERE {$whereSql}");
$cnt->execute($params);
$totalRows  = (int)$cnt->fetchColumn();
$totalPages = max(1, (int)ceil($totalRows / $perPage));
if ($page > $totalPages) $page = $totalPages;

/* reported_by / scanner_name are deliberately NOT selected. Not merely
   hidden in the markup — not fetched, so the name cannot reach the page
   through a later edit, a debug dump or a view-source. See the header note. */
$sql = "SELECT v.id, v.violation, v.description, v.severity, v.offense, v.remarks,
               v.evidence, v.date_reported,
               v.proof_status, v.proof_reviewed_at,
               v.discussed_at, v.discussion_note,
               s.fullname AS student_name, s.student_id AS school_id,
               s.course, s.year_level,
               p.fullname AS reviewer_name,
               g.fullname AS discussed_by_name
        FROM violations v
        INNER JOIN users s ON v.student_id = s.id
        LEFT  JOIN users p ON v.proof_reviewed_by = p.id
        LEFT  JOIN users g ON v.discussed_by = g.id
        WHERE {$whereSql}
        /* WORK FIRST, HISTORY LAST.

           This used to be newest-first and nothing else, which put a photo
           somebody approved this morning above twelve that nobody has
           looked at. The page exists to get through the undecided ones, so
           they sit at the top and stay there until they are decided:

             0  Pending   -- the queue, and the reason to open this page
             1  Rejected  -- decided, but the one worth re-reading
             2  Approved  -- done; it sinks to the bottom

           Newest-first inside each group. The sort is in SQL, not in the
           page, so it holds across pagination instead of only ordering
           whichever twenty rows happened to be fetched. */
        ORDER BY CASE COALESCE(v.proof_status, 'Pending')
                   WHEN 'Pending'  THEN 0
                   WHEN 'Rejected' THEN 1
                   ELSE 2
                 END ASC,
                 v.date_reported DESC
        LIMIT :off, :lim";
$stmt = $conn->prepare($sql);
foreach ($params as $k => $v) $stmt->bindValue($k, $v);
$stmt->bindValue(':off', ($page - 1) * $perPage, PDO::PARAM_INT);
$stmt->bindValue(':lim', $perPage, PDO::PARAM_INT);
$stmt->execute();
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$assetBase = "../";
include "../includes/header.php";
include "../includes/navbar.php";
include "../includes/sidebar.php";
?>
<link rel="stylesheet" href="../assets/css/proof.css?v=<?php echo @filemtime(__DIR__."/../assets/css/proof.css"); ?>">
<main class="vts-main">
<div class="vts-main-inner">

  <div class="admin-welcome-row">
    <div>
      <h1><i class="fas fa-camera"></i> Violation Proof</h1>
      <p>The photo attached to a record, next to the reason it was recorded for.</p>
    </div>
  </div>

  <?php /* Same flash pair every other admin page uses — vts_redirect_back()
           puts the sentence in ?success= or ?error=. */ ?>
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

  <div class="recent-table-wrap is-panel">

    <?php
      /* Each tab keeps whatever the OTHER filters are set to, so switching
         axis does not silently drop a search someone just typed. */
      $tabHref = function (array $over) use ($review, $search): string {
          $q = array_merge(['review' => $review, 'search' => $search], $over);
          if (($q['review'] ?? '') === 'any') unset($q['review']);
          if (($q['search'] ?? '') === '')    unset($q['search']);
          return 'proof.php?' . http_build_query($q);
      };
    ?>

    <?php /* A "With proof / No proof" split used to sit here. It is gone:
             proof is required to record a violation on the web form, so
             "without" is not a category the office needs to browse — and a
             tab that is always empty teaches people to ignore the row it is
             in. The one axis left is what has been DECIDED about the proof. */ ?>
    <div class="tab-row proof-review-tabs u-mb-12">
      <span class="proof-tabs-label">Review</span>
      <?php foreach ([['any', 'Any'], ['Pending', 'Not reviewed'], ['Approved', 'Approved'], ['Rejected', 'Rejected']] as [$k, $label]): ?>
        <a href="<?php echo htmlspecialchars($tabHref(['review' => $k])); ?>" class="tab-link<?php echo $review === $k ? ' active' : ''; ?>">
          <?php echo $label; ?>
          <?php if (isset($reviewCounts[$k])): ?><span class="tab-count"><?php echo (int)$reviewCounts[$k]; ?></span><?php endif; ?>
        </a>
      <?php endforeach; ?>
    </div>

    <form method="GET" class="vts-filter-row">
      <input type="hidden" name="review" value="<?php echo htmlspecialchars($review); ?>">
      <input type="text" name="search" class="vts-table-filter" placeholder="Search student, school ID or violation…"
             aria-label="Search proof records" value="<?php echo htmlspecialchars($search); ?>">
      <button type="submit" class="btn-primary btn-sm"><i class="fas fa-search"></i> Search</button>
      <?php if ($search !== ''): ?>
        <a href="<?php echo htmlspecialchars($tabHref(['search' => ''])); ?>" class="btn-outline btn-sm">
          <i class="fas fa-times"></i> Reset
        </a>
      <?php endif; ?>
    </form>

    <?php if (!$rows): ?>
      <div class="proof-empty">
        <i class="fas fa-camera"></i>
        <p><?php
          if ($search !== '')            echo 'No records match that search.';
          elseif ($review === 'Pending')  echo 'Nothing is waiting for a decision.';
          elseif ($review !== 'any')      echo 'No record has been marked ' . strtolower($review) . '.';
          else                            echo 'There are no violation records yet.';
        ?></p>
      </div>
    <?php else: ?>

      <?php /* ---- ONE ROW PER RECORD ----

               This was a grid of cards, each carrying the photo, the reason,
               the decision form and the conference block. One record filled
               most of a screen, so comparing two of them meant scrolling,
               and the queue of undecided ones could not be seen as a queue
               at all.

               A table reads as a list of work: status, who, what, when, and
               the decision, on one line each. Everything that is reading
               rather than deciding -- the full photo, the reason it was
               recorded for, the notes -- moves into the viewer that the
               thumbnail opens, which is where a person is actually looking
               when they judge a record. */ ?>
      <div class="table-scroll table-responsive">
      <table class="data-table proof-table">
        <thead><tr>
          <th class="pt-shot">Proof</th>
          <th>Student</th>
          <th>Violation</th>
          <th class="nowrap">Recorded</th>
          <th>Status</th>
          <th class="pt-act">Decision</th>
        </tr></thead>
        <tbody>
        <?php foreach ($rows as $r):
          $file  = (string)($r['evidence'] ?? '');
          $isImg = $file !== '' && preg_match('/\.(jpe?g|png|gif|webp)$/i', $file);
          $isPdf = $file !== '' && preg_match('/\.pdf$/i', $file);
          $src   = '../uploads/evidence/' . rawurlencode($file);
          $sev   = $r['severity'] ?? 'Minor';
          $sevCls = $sev === 'Grave' ? 'sev-grave' : ($sev === 'Major' ? 'sev-major' : 'sev-minor');
          $pStatus = (string)($r['proof_status'] ?? 'Pending');
          /* A Major/Grave is locked until the conference with the student is
             on record — the same rule vts_discussion_required() enforces on
             the server. Worked out from the row already in hand rather than
             a query per row. */
          $isMajor   = ($sev === 'Major' || $sev === 'Grave');
          $talked    = !empty($r['discussed_at']);
          $lockedMaj = $isMajor && !$talked;
          /* Deleting is not the same gate. An Admin holds full CRUD and
             is not stopped by a missing conference; OSA are, exactly as
             before. Worked out from the row already in hand —
             vts_can_delete_violation() re-decides it on the server. */
          $mayDelete = $vtsIsAdmin || !$lockedMaj;

          /* Everything the viewer shows, handed over as data- attributes so
             one dialog serves the whole table. */
          $reason = ($r['description'] !== null && $r['description'] !== '')
                  ? (string)$r['description'] : '';
          $who    = $r['student_name'] . ' · ' . ($r['school_id'] ?: '—');
          $what   = $r['violation'] . ' · ' . strtoupper($sev) . ' · ' . vts_datetime($r['date_reported']);
        ?>
        <tr class="pt-row is-<?php echo strtolower($pStatus); ?>">

          <td class="pt-shot">
            <?php if ($isImg): ?>
              <?php
              /* The thumbnail and the View button are ONE control written
                 twice, because a 62px picture is not an obvious button. The
                 picture tells you there is proof and roughly what it is; the
                 labelled eye underneath tells you it opens. Both carry the
                 same data- attributes and the same js-proof-open hook, so
                 either one opens the same viewer.

                 The attributes are built once here rather than repeated in
                 both tags -- two copies drift, and a stale caption on a
                 photo is worse than no caption. */
              $openAttrs = 'data-src="'     . $src . '" '
                         . 'data-who="'     . htmlspecialchars($who) . '" '
                         . 'data-what="'    . htmlspecialchars($what) . '" '
                         . 'data-reason="'  . htmlspecialchars($reason) . '" '
                         . 'data-remarks="' . htmlspecialchars((string)($r['remarks'] ?? '')) . '"';
              ?>
              <button type="button" class="pt-thumb js-proof-open" <?php echo $openAttrs; ?>
                      title="See the proof photo" aria-label="See the proof photo full size">
                 <img src="<?php echo $src; ?>" loading="lazy"
                   alt="Proof photo for <?php echo htmlspecialchars($who . ' - ' . $what); ?>">
                <span class="pt-thumb-zoom" aria-hidden="true"><i class="fas fa-expand"></i></span>
              </button>
              <button type="button" class="pt-view js-proof-open" <?php echo $openAttrs; ?>
                      title="See the proof photo for this violation">
                <i class="fas fa-eye" aria-hidden="true"></i> View proof
              </button>
            <?php elseif ($isPdf || $file !== ''): ?>
              <a class="pt-file" href="<?php echo $src; ?>" target="_blank" rel="noopener"
                 title="Open the attachment">
                <i class="fas fa-<?php echo $isPdf ? 'file-pdf' : 'paperclip'; ?>"></i>
              </a>
              <a class="pt-view" href="<?php echo $src; ?>" target="_blank" rel="noopener"
                 title="Open the attachment in a new tab">
                <i class="fas fa-up-right-from-square" aria-hidden="true"></i> Open file
              </a>
            <?php else: ?>
              <span class="pt-none" title="No proof is attached to this record"><i class="fas fa-camera-rotate"></i></span>
              <span class="pt-view is-none">No photo</span>
            <?php endif; ?>
          </td>

          <td>
            <div class="pt-name"><?php echo htmlspecialchars($r['student_name']); ?></div>
            <div class="pt-sub">
              <?php echo htmlspecialchars($r['school_id'] ?: '—'); ?>
              <?php if (!empty($r['course'])): ?> · <?php echo htmlspecialchars($r['course']); ?><?php endif; ?>
              <?php if (!empty($r['year_level'])): ?> · <?php echo htmlspecialchars($r['year_level']); ?><?php endif; ?>
            </div>
          </td>

          <td>
            <div class="pt-viol"><?php echo htmlspecialchars($r['violation']); ?>
              <span class="sev <?php echo $sevCls; ?>"><?php echo htmlspecialchars(strtoupper($sev)); ?></span>
            </div>
            <div class="pt-sub">
              <?php echo htmlspecialchars(offense_display($r['offense'] ?? '')); ?>
            </div>
          </td>

          <td class="nowrap pt-sub"><?php echo htmlspecialchars(vts_date($r['date_reported'])); ?></td>

          <td>
            <span class="pt-status is-<?php echo strtolower($pStatus); ?>">
              <?php if ($pStatus === 'Approved'): ?>
                <i class="fas fa-circle-check"></i> Approved
              <?php elseif ($pStatus === 'Rejected'): ?>
                <i class="fas fa-circle-xmark"></i> Rejected
              <?php else: ?>
                <i class="fas fa-clock"></i> Waiting
              <?php endif; ?>
            </span>
            <?php if ($pStatus !== 'Pending' && !empty($r['proof_reviewed_at'])): ?>
              <div class="pt-sub"><?php echo htmlspecialchars(vts_date($r['proof_reviewed_at'])); ?>
                <?php if (!empty($r['reviewer_name'])): ?><br>by <?php echo htmlspecialchars($r['reviewer_name']); ?><?php endif; ?>
              </div>
            <?php endif; ?>
          </td>

          <td class="pt-act">
            <form method="POST" class="pt-decide">
              <?php echo csrf_field(); ?>
              <input type="hidden" name="violation_id" value="<?php echo (int)$r['id']; ?>">
              <div class="pt-buttons">
                <?php if ($pStatus !== 'Approved'): ?>
                  <button type="submit" name="decision" value="approve" class="btn-success btn-sm"
                          title="The photo supports this record">
                    <i class="fas fa-check"></i> Approve
                  </button>
                <?php endif; ?>
                <?php if ($pStatus !== 'Rejected'): ?>
                  <?php /* Shown but disabled rather than hidden: an absent
                           button reads as a page that is missing something,
                           while a disabled one with the reason beside it
                           says what to do next. The server refuses it too —
                           this attribute is a courtesy, not the control. */ ?>
                  <button type="submit" name="decision" value="reject" class="btn-danger btn-sm"
                          <?php echo $lockedMaj ? 'disabled title="Record the conference with the student first"' : 'title="The photo does not support this record"'; ?>
                          onclick="return confirm('Reject this proof? The violation stays in history but stops counting toward the student\'s offense ladder, and the student is told.');">
                    <i class="fas fa-xmark"></i> Reject
                  </button>
                <?php endif; ?>
                <?php if ($pStatus !== 'Pending'): ?>
                  <button type="submit" name="decision" value="reset" class="btn-outline btn-sm"
                          onclick="return confirm('Clear this decision? The record goes back to counting as normal.');">
                    <i class="fas fa-rotate-left"></i> Clear
                  </button>
                <?php endif; ?>
              </div>
            </form>

            <?php /* The conference a Major/Grave needs before it can be
                     rejected. Kept in the row rather than the viewer because
                     it is an action, and actions stay where the decision is. */ ?>
            <?php if ($isMajor && !$talked): ?>
              <details class="pt-conf">
                <summary><i class="fas fa-comments"></i> Record conference</summary>
                <p class="pt-conf-why">A <?php echo htmlspecialchars($sev); ?> cannot be rejected
                  or deleted until it has been talked through with the student.</p>
                <form method="POST">
                  <?php echo csrf_field(); ?>
                  <input type="hidden" name="violation_id" value="<?php echo (int)$r['id']; ?>">
                  <input type="hidden" name="record_discussion" value="1">
                  <label for="dn<?php echo (int)$r['id']; ?>" class="sr-only">What was discussed</label>
                  <textarea id="dn<?php echo (int)$r['id']; ?>" name="discussion_note" rows="2"
                            minlength="10" maxlength="2000" required
                            placeholder="What was said, and what the student's account was."></textarea>
                  <button type="submit" class="btn-outline btn-sm"
                          onclick="return confirm('Record that this <?php echo htmlspecialchars($sev, ENT_QUOTES); ?> offense was discussed with the student?\n\nThis unlocks rejecting and deleting it, and is kept on the record.');">
                    <i class="fas fa-comments"></i> Save
                  </button>
                </form>
              </details>
            <?php elseif ($isMajor && $talked): ?>
              <div class="pt-sub pt-talked"><i class="fas fa-comments"></i> Discussed
                <?php echo htmlspecialchars(vts_date($r['discussed_at'])); ?></div>
            <?php endif; ?>

            <div class="pt-row-actions">
              <a class="pt-edit" href="edit_violation.php?id=<?php echo (int)$r['id']; ?>&amp;return=<?php echo vts_return_param(); ?>">
                <i class="fas fa-pen"></i> <?php echo $file === '' ? 'Attach proof' : 'Edit'; ?>
              </a>

              <?php /* ---- Delete ----
                       Last in the row and set apart, because it is the
                       only control here that cannot be undone: Approve,
                       Reject and Clear move a record between states, this
                       one ends it. The photo on disk goes with it.

                       Shown to OSA even when it is locked, disabled with
                       the reason — same as the Reject button above, and
                       for the same reason: an absent button reads as a
                       page missing something, a disabled one says what to
                       do next. admin/delete_violation.php refuses it on
                       the server too; the attribute is a courtesy, not
                       the control. */ ?>
              <form method="POST" action="delete_violation.php" class="pt-del-form"
                    onsubmit="return confirm('Delete this violation permanently?\n\nThe record and its proof photo are both removed. This cannot be undone — to stop it counting without erasing it, use Reject instead.');">
                <?php echo csrf_field(); ?>
                <?php echo vts_return_field(); ?>
                <input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>">
                <button type="submit" class="btn-danger btn-sm pt-del"
                        <?php echo $mayDelete
                            ? 'title="Delete this record and its proof photo permanently"'
                            : 'disabled title="Record the conference with the student first — or ask an Admin"'; ?>>
                  <i class="fas fa-trash"></i> Delete
                </button>
              </form>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      </div>

      <p class="vo-count"><?php echo (int)$totalRows; ?> record(s).</p>
      <?php echo vts_pager($page, $totalPages, $_GET, 'proof'); ?>

    <?php endif; ?>
  </div>

<?php /* ---- THE VIEWER ----
         One dialog for the whole table. The rows carry the photo's path and
         the few lines of context in data- attributes, so a page of records
         costs a page of attributes rather than a page of hidden dialogs.

         It shows the photo large, next to the reason the violation was
         recorded for -- those two are what a reviewer is comparing, and the
         old thumbnail-in-a-new-tab put them on separate screens. */ ?>
<div class="pv-overlay" id="proofView" role="dialog" aria-modal="true" aria-labelledby="pvWho">
  <div class="pv-box" role="document">
    <div class="pv-head">
      <div class="t">
        <div class="pv-who" id="pvWho"></div>
        <div class="pv-what" id="pvWhat"></div>
      </div>
      <button type="button" class="pv-x" id="pvClose" aria-label="Close">&times;</button>
    </div>
    <div class="pv-body">
      <div class="pv-shot"><img id="pvImg" alt="Proof photo for this violation"></div>
      <div class="pv-side">
        <div class="pv-block">
          <h4>Reason given</h4>
          <p id="pvReason"></p>
        </div>
        <div class="pv-block" id="pvRemarksBlock" hidden>
          <h4>Remarks</h4>
          <p id="pvRemarks"></p>
        </div>
      </div>
    </div>
    <div class="pv-foot">
      <span class="pv-hint">Does the photo show what the reason says?</span>
      <a id="pvOpen" href="#" target="_blank" rel="noopener" class="btn-outline btn-sm">
        <i class="fas fa-up-right-from-square"></i> Open full size</a>
    </div>
  </div>
</div>

<script>
/* Delegated, so it survives a filter re-render and one handler serves every
   row. The dialog is read-only on purpose: deciding happens on the row,
   where the buttons already are, so there is one place a decision is made
   rather than two that can disagree. */
(function(){
  var ov = document.getElementById('proofView');
  if (!ov) return;
  var img = document.getElementById('pvImg'), last = null;

  function fill(id, text, blockId){
    var el = document.getElementById(id);
    if (!el) return;
    el.textContent = text || '';
    if (blockId) document.getElementById(blockId).hidden = !text;
  }
  function open(btn){
    last = btn;
    var src = btn.getAttribute('data-src');
    img.src = src;
    document.getElementById('pvOpen').href = src;
    document.getElementById('pvWho').textContent  = btn.getAttribute('data-who')  || '';
    document.getElementById('pvWhat').textContent = btn.getAttribute('data-what') || '';
    fill('pvReason', btn.getAttribute('data-reason') || 'No reason was written.');
    fill('pvRemarks', btn.getAttribute('data-remarks'), 'pvRemarksBlock');
    ov.classList.add('open');
    document.getElementById('pvClose').focus();
  }
  function close(){
    ov.classList.remove('open');
    img.removeAttribute('src');            // do not keep a large frame in memory
    if (last){ try{ last.focus(); }catch(e){} }
  }
  document.addEventListener('click', function(e){
    var btn = e.target.closest && e.target.closest('.js-proof-open');
    if (btn){ e.preventDefault(); open(btn); return; }
    if (e.target === ov) close();
  });
  document.getElementById('pvClose').addEventListener('click', close);
  document.addEventListener('keydown', function(e){
    if (e.key === 'Escape' && ov.classList.contains('open')) close();
  });
})();
</script>

<?php include "../includes/footer.php"; ?>
