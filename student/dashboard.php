<?php
/* Student dashboard: personal violation summary. */
require_once "../auth/auth.php";
require_once "../config/database.php";
require_once "../includes/functions.php";   // vts_date() used below

if (($_SESSION['role'] ?? '') !== "Student") {
    vts_deny_access();
}

$assetBase = "../";
include "../includes/header.php";
include "../includes/navbar.php";
include "../includes/sidebar.php";

$userID = $_SESSION['user_id'];

$stmt = $conn->prepare("SELECT student_id, fullname, course, year_level, section FROM users WHERE id = :id");
$stmt->execute([':id' => $userID]);
$student = $stmt->fetch(PDO::FETCH_ASSOC);

$totalViolations = $conn->prepare("SELECT COUNT(*) FROM violations WHERE student_id = :id");
$totalViolations->execute([':id' => $userID]);
$totalViolations = $totalViolations->fetchColumn();

// Active count (cleared minors excluded) drives the escalating warning.
$activeCount = active_violation_count($conn, $userID);
$showWarning = $activeCount >= 1;                     // warn from the 1st offense
[$warnTitle, $warnMsg, $warnLevel] = vts_offense_warning_text($activeCount);
/* The colours for each escalation level live in student-dashboard.css as
   custom properties on .warn-level.is-<level>; this only picks which of the
   four applies. Sixteen hex values used to sit here and be echoed into
   style="" on ten elements. */
$warnClass = in_array($warnLevel, ['warn','caution','final','escalation'], true) ? $warnLevel : 'final';

$pending = $conn->prepare("SELECT COUNT(*) FROM violations WHERE student_id = :id AND MONTH(date_reported)=MONTH(NOW()) AND YEAR(date_reported)=YEAR(NOW())");
$pending->execute([':id' => $userID]);
$pending = $pending->fetchColumn();

// Determine overall status
$thirdOffense = $conn->prepare("SELECT COUNT(*) FROM violations WHERE student_id=:id AND offense='Third Offense'");
$thirdOffense->execute([':id' => $userID]);
$hasThird = $thirdOffense->fetchColumn();

$secondOffense = $conn->prepare("SELECT COUNT(*) FROM violations WHERE student_id=:id AND offense='Second Offense'");
$secondOffense->execute([':id' => $userID]);
$hasSecond = $secondOffense->fetchColumn();

$status_label = 'Good';
$status_icon  = 'fa-check-circle';
$status_class = 'status-good';      // colours the Status card: green / amber / red
if ($hasThird) { $status_label = 'Critical'; $status_icon = 'fa-exclamation-circle'; $status_class = 'status-critical'; }
elseif ($hasSecond) { $status_label = 'At risk'; $status_icon = 'fa-exclamation-triangle'; $status_class = 'status-risk'; }

// Recent violations
$recent = $conn->prepare("SELECT id, violation, severity, offense, status, date_reported FROM violations WHERE student_id=:id ORDER BY date_reported DESC LIMIT 5");
$recent->execute([':id' => $userID]);
$recent = $recent->fetchAll(PDO::FETCH_ASSOC);

// QR code — unified helper (phpqrcode if installed, else qrserver web API)
require_once "../includes/qr_helper.php";
$qrPayload = $student['student_id'] . "|" . $student['fullname'] . "|" . $student['year_level'] . "|" . $student['course'];
$qrSrc  = get_student_qr($student['student_id'], "../", $qrPayload);
$qrFile = qr_file_path($student['student_id']);
$qrName = preg_replace('/[^A-Za-z0-9]+/', '', (string)$student['fullname']);
if ($qrName === '') $qrName = 'Student';
?>

<link rel="stylesheet" href="../assets/css/student-dashboard.css?v=<?php echo @filemtime(__DIR__."/../assets/css/student-dashboard.css"); ?>">
<?php /* This is the only page that adds a stylesheet after includes/header.php,
         which would otherwise make it the one page where the design system is
         NOT last and so does not get to settle disagreements. Re-linking it
         here restores that; the browser serves it from cache, so it costs a
         cache hit and nothing else. */ ?>
<link rel="stylesheet" href="../assets/css/vts-design.css?v=<?php echo @filemtime(__DIR__."/../assets/css/vts-design.css"); ?>">
<main class="vts-main">
<div class="vts-main-inner">

  <!-- The page opened at level 2 with no level-1 heading above it, so its
       outline started one rank down from nowhere. Same size on screen,
       correct rank for anything reading the structure. -->
  <h1 class="sd-greeting">
    Welcome, <?php echo htmlspecialchars(vts_first_name($student['fullname'] ?? '', 'Student')); ?>!
  </h1>
  <p class="sd-sub">Your violation overview.</p>

  <?php if ($showWarning): ?>
  <div id="threeStrikeBanner" class="warn-banner warn-level is-<?php echo $warnClass; ?>">
    <i class="fas fa-triangle-exclamation ic" aria-hidden="true"></i>
    <div class="body">
      <span class="warn-title"><?php echo htmlspecialchars($warnTitle); ?></span>
      <span class="warn-msg">&mdash; <?php echo htmlspecialchars($warnMsg); ?>
        <a href="notifications.php">View details &rarr;</a></span>
      <button type="button" id="enableAlertsBtn" class="warn-alerts-btn">
        <i class="fas fa-bell" aria-hidden="true"></i> Enable warning alerts
      </button>
    </div>
  </div>

  <!-- Pop-up warning modal (shows once per login session) -->
  <div id="warnModal" class="warn-modal warn-level is-<?php echo $warnClass; ?>">
    <div class="sheet">
      <div class="sheet-head">
        <i class="fas fa-triangle-exclamation ic" aria-hidden="true"></i>
        <div class="ttl"><?php echo htmlspecialchars($warnTitle); ?></div>
      </div>
      <div class="sheet-body">
        <p><?php echo htmlspecialchars($warnMsg); ?></p>
        <div class="tally">
          <span class="n"><?php echo (int)$activeCount; ?></span>
          <span class="lbl">active violation<?php echo $activeCount==1?'':'s'; ?> on record</span>
        </div>
        <div class="sheet-actions">
          <a href="violations.php" class="btn-outline btn-sm">View my violations</a>
          <button type="button" class="btn-primary btn-sm" id="warnModalOk">I understand</button>
        </div>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <?php
  /* No password on this account yet.

     Read from the DATABASE, not from the session flag the lookup login sets:
     the flag is only present on the visit they signed in, so a student who
     navigates around, or comes back on a session that was restored, would
     stop being told. This asks the account itself, every time.

     The matching notification (bell) is created at sign-in and both clear
     together the moment a password is set — see vts_mark_password_set(). */
  vts_ensure_password_flag($conn);
  $pwq = $conn->prepare("SELECT has_password FROM users WHERE id = :id");
  $pwq->execute([':id' => $userID]);
  $needsPassword = (int)$pwq->fetchColumn() === 0;
  ?>
  <?php if ($needsPassword): ?>
  <div class="alert alert-warning vts-notice-row" role="status">
    <i class="fas fa-key" aria-hidden="true"></i>
    <span class="grow">
      <b>Your account has no password yet.</b>
      Set one now so you can still sign in when the older method is switched off.
    </span>
    <a href="profile.php#password" class="btn-primary btn-sm">Set password</a>
  </div>
  <?php endif; ?>

  <?php if (trim((string)($student['section'] ?? '')) === ''): ?>
  <!-- Nudge: a student with no Set can't be filed under one on the OSA sheet -->
  <div class="alert alert-warning" style="display:flex;align-items:center;gap:10px;margin-bottom:16px;">
    <i class="fas fa-circle-exclamation"></i>
    <span>Please set your <b>Set / Section</b> — <a href="profile.php" style="font-weight:700;text-decoration:underline;">open your profile</a> and fill it in.</span>
  </div>
  <?php endif; ?>

  <!-- STAT CARDS -->
  <div class="stat-grid">
    <div class="stat-card red">
      <div>
        <div class="stat-label">Total Violations</div>
        <div class="stat-value"><?php echo $totalViolations; ?></div>
        <a href="violations.php" class="stat-link">View all</a>
      </div>
      <i class="fas fa-times-circle stat-icon"></i>
    </div>
    <div class="stat-card teal">
      <div>
        <div class="stat-label">This Month</div>
        <div class="stat-value"><?php echo $pending; ?></div>
        <a href="violations.php" class="stat-link">View all</a>
      </div>
      <i class="fas fa-clock stat-icon"></i>
    </div>
    <div class="stat-card <?php echo $status_class; ?>">
      <div>
        <div class="stat-label">Status</div>
        <div class="stat-value" style="font-size:1.5rem;"><?php echo $status_label; ?></div>
        <a href="violations.php" class="stat-link">View all</a>
      </div>
      <i class="fas <?php echo $status_icon; ?> stat-icon"></i>
    </div>
  </div>

  <!-- BOTTOM ROW -->
  <div class="dash-bottom">
    <!-- My Violations -->
    <div class="my-violations-list">
      <h4>My Violations</h4>
      <?php if (count($recent) > 0): ?>
        <?php foreach($recent as $v): $sev = ($v['severity'] ?? 'Minor') === 'Major' ? 'Major' : 'Minor'; ?>
        <?php /* Kind only — the specific violation type is withheld from the student. */ ?>
        <div class="violation-list-item u-pointer" title="View details"
             onclick="location.href='view_violation.php?id=<?php echo (int)$v['id']; ?>'">
          <div class="vi-type">
            <span class="kind-chip <?php echo strtolower($sev); ?>"><?php echo strtoupper($sev); ?></span>
          </div>
          <div class="vi-date"><?php echo vts_date($v['date_reported']); ?></div>
        </div>
        <?php endforeach; ?>
      <?php else: ?>
        <div class="violation-list-item">
          <div class="vi-type u-muted">No violations recorded.</div>
        </div>
      <?php endif; ?>
      <a href="violations.php" class="view-all-link">View all</a>
    </div>

    <!-- QR Code -->
    <div class="qr-mini-card">
      <h4>Your QR Code</h4>
      <?php if (file_exists($qrFile)): ?>
        <img alt="Your QR Code" src="<?php echo $qrSrc; ?>" class="sd-qr">
      <?php else: ?>
        <div class="sd-qr-empty">
          <i class="fas fa-qrcode" aria-hidden="true"></i>
        </div>
      <?php endif; ?>
      <a href="<?php echo $qrSrc; ?>" class="btn-primary btn-sm qr-download-btn"
         data-src="<?php echo htmlspecialchars($qrSrc); ?>"
         data-filename="QR_<?php echo htmlspecialchars($student['student_id'] . '_' . $qrName); ?>.png"
         data-name="<?php echo htmlspecialchars($student['fullname']); ?>"
         data-id="<?php echo htmlspecialchars($student['student_id']); ?>"
         data-course="<?php echo htmlspecialchars($student['course']); ?>"
         data-logo="<?php echo htmlspecialchars($assetBase); ?>assets/images/logo-sm.jpg">
        <i class="fas fa-download"></i> Download QR
      </a>
      <button type="button" id="installAppBtn" class="btn-outline btn-sm" style="display:none;margin-top:10px;">
        <i class="fas fa-mobile-screen-button"></i> Install App
      </button>
    </div>
  </div>

<?php /* .vts-main-inner and <main> are closed by includes/footer.php;
         closing them here too emitted a stray </div></main>. */ ?>

<script>
/* ---- Installable web app (PWA) + 3-strike push warning ---- */
(function(){
  // Attach the student manifest (header.php already closed <head>).
  if (!document.querySelector('link[rel="manifest"]')){
    var m = document.createElement('link');
    m.rel = 'manifest'; m.href = '../student_manifest.json';
    document.head.appendChild(m);
  }

  // Register the student service worker (enables install + local notifications).
  var swReg = null;
  if ('serviceWorker' in navigator){
    navigator.serviceWorker.register('../sw_student.js')
      .then(function(reg){ swReg = reg; })
      .catch(function(){ /* non-fatal */ });
  }

  // "Install App" button — shown when the browser offers installation.
  var deferredPrompt = null;
  var installBtn = document.getElementById('installAppBtn');
  window.addEventListener('beforeinstallprompt', function(e){
    e.preventDefault(); deferredPrompt = e;
    if (installBtn) installBtn.style.display = 'inline-flex';
  });
  if (installBtn){
    installBtn.addEventListener('click', function(){
      if (!deferredPrompt) return;
      deferredPrompt.prompt();
      deferredPrompt.userChoice.finally(function(){ deferredPrompt = null; installBtn.style.display = 'none'; });
    });
  }
  window.addEventListener('appinstalled', function(){ if (installBtn) installBtn.style.display = 'none'; });

  <?php if ($showWarning): ?>
  // Pop-up the warning modal once per login session (re-shows if the count changed).
  (function(){
    var modal = document.getElementById('warnModal');
    if (!modal) return;
    var key = 'vtsWarnSeen_<?php echo (int)$activeCount; ?>';
    // Shown/hidden with a class now, not by writing style.display — the
    // modal's own rules live in student-dashboard.css.
    function open(){ modal.classList.add('open'); }
    function close(){ modal.classList.remove('open'); }
    try {
      if (sessionStorage.getItem(key) !== '1'){ open(); sessionStorage.setItem(key, '1'); }
    } catch(e){ open(); }
    modal.addEventListener('click', function(e){ if (e.target === modal) close(); });
    var ok = document.getElementById('warnModalOk');
    if (ok) ok.addEventListener('click', close);
    document.addEventListener('keydown', function(e){
      if (e.key === 'Escape' && modal.classList.contains('open')) close();
    });
  })();

  // Warning present → offer to push it as a device notification (PWA).
  var WARN_TITLE = <?php echo json_encode($warnTitle); ?>;
  var WARN_BODY  = <?php echo json_encode($warnMsg); ?>;
  function pushWarning(){
    if (!('Notification' in window) || Notification.permission !== 'granted') return;
    if (swReg && swReg.active){ swReg.active.postMessage({type:'notify', title:WARN_TITLE, body:WARN_BODY, tag:'vts-3strike'}); }
    else { try { new Notification(WARN_TITLE, {body:WARN_BODY, icon:'../assets/icons/icon-192.png'}); } catch(e){} }
  }
  var alertsBtn = document.getElementById('enableAlertsBtn');
  if ('Notification' in window){
    if (Notification.permission === 'granted'){ setTimeout(pushWarning, 800); }
    else if (Notification.permission === 'default' && alertsBtn){
      alertsBtn.style.display = 'inline-flex';   // revealed only when permission can still be asked
      alertsBtn.addEventListener('click', function(){
        Notification.requestPermission().then(function(p){
          if (p === 'granted'){ alertsBtn.style.display = 'none'; setTimeout(pushWarning, 400); }
        });
      });
    }
  }
  <?php endif; ?>
})();
</script>

<?php include "../includes/footer.php"; ?>
