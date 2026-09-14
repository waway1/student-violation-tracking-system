<?php
/* STUDENT VIOLATION SLIP — 8.5in wide x 6.9in tall.
   This is a DIFFERENT document from the ID Confiscation Slip: it is the
   checkbox slip the marshal/OSA issues for the violation itself.
   Layout mirrors the printed GWC-OSA_violationslip2025 form.
   Every field is editable on screen (contenteditable) before printing. */
require_once "../auth/auth.php";
require_once "../config/database.php";
require_once "../includes/functions.php";

$role = $_SESSION['role'] ?? '';
if (!in_array($role, ['Admin', 'OSA', 'OSA Staff', 'Guard'], true)) {
    vts_deny_access();
}

$vid = (int)($_GET['id'] ?? 0);
$stmt = $conn->prepare("
    SELECT v.*, u.student_id AS sid, u.fullname, u.course, u.year_level, u.section,
           r.fullname AS recorder
    FROM violations v
    JOIN users u ON v.student_id = u.id
    LEFT JOIN users r ON v.reported_by = r.id
    WHERE v.id = :id LIMIT 1");
$stmt->execute([':id' => $vid]);
$v = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$v) { die("Violation not found."); }

$offenseNum = offense_number($v['offense'] ?? '');
$vname      = strtoupper(trim($v['violation'] ?? ''));

/* The printed form's fixed checkbox grid. Key => label. We tick the box whose
   label best matches the recorded violation; anything else lands in "Others". */
$COL1 = [
    'uniform_not'     => 'NOT WEARING OF UNIFORM',
    'long_hair'       => 'LONG HAIR (FOR MALE)',
    'uniform_improper'=> 'IMPROPER WEARING OF UNIFORM',
    'smoking'         => 'SMOKING, VAPING &amp; DRINKING OF LIQUOR',
    'possession'      => 'POSSESSION OF CIGARETTES, DRUGS &amp; ALCOHOL',
];
$COL2 = [
    'earring_male'    => 'EARRING/S (FOR MALE)',
    'earring_multi'   => 'MULTIPLE EARRINGS (FEMALE)',
    'sleeveless'      => 'SLEEVELESS/CROP TOP/SANDO',
    'clogs'           => 'CLOGS/CROCS/STEP-IN',
    'blonde'          => 'EXTREME BLONDE/HIGHLIGHTED HAIR',
];
$COL3 = [
    'outfit'          => 'IMPROPER/INAPPROPRIATE OUTFIT',
    'short_skirt'     => 'SHORT/SKIRT',
    'tattered'        => 'TATTERED JEANS/LEGGINGS',
    'id_improper'     => 'IMPROPER USE OF I.D.',
    'id_not'          => 'NOT WEARING OF I.D.',
];
$COL4 = [
    'weapon'          => 'CARRYING OF DEADLY WEAPON/USE OF EXPLOSIVE',
];

/* Match the recorded violation to a printed box.
   TWO PASSES, and the order matters: an exact match anywhere must win before
   any loose keyword match, otherwise "Multiple Earrings" would tick
   "EARRING/S (FOR MALE)" simply because that box is listed first. */
function slip_norm($s) { return preg_replace('/[^A-Z]/', '', strtoupper(html_entity_decode($s))); }

function slip_exact($vname, $label) { return slip_norm($label) === slip_norm($vname); }

/* Score a label: the LONGEST keyword present in both the label and the
   recorded violation wins. Scoring across every box (instead of taking the
   first hit) is what keeps "Multiple Earrings" off the "EARRING/S (FOR MALE)"
   box — "MULTIPLE EARRING" (16) beats plain "EARRING" (7). */
function slip_score($vname, $label) {
    $l = strtoupper(html_entity_decode($label));
    $best = 0;
    foreach (['MULTIPLE EARRING','EXTREME BLONDE','HIGHLIGHT','SLEEVELESS','CROP TOP','SANDO',
              'CLOGS','CROCS','STEP-IN','TATTERED','LEGGINGS','SHORT','SKIRT','OUTFIT',
              'IMPROPER USE OF I.D.','NOT WEARING OF I.D.','LONG HAIR',
              'IMPROPER WEARING','NOT WEARING OF UNIFORM','UNIFORM',
              'SMOK','VAPING','LIQUOR','CIGARET','DRUG','ALCOHOL',
              'WEAPON','EXPLOSIVE','EARRING'] as $kw) {
        if (strpos($l, $kw) !== false && strpos($vname, $kw) !== false) {
            $best = max($best, strlen($kw));
        }
    }
    return $best;
}

$ALLCOLS = [$COL1, $COL2, $COL3, $COL4];
$ticked  = null;
foreach ($ALLCOLS as $col) {                       // pass 1 — exact wins outright
    foreach ($col as $k => $label) { if (slip_exact($vname, $label)) { $ticked = $k; break 2; } }
}
if ($ticked === null) {                            // pass 2 — best keyword score
    $bestScore = 0;
    foreach ($ALLCOLS as $col) {
        foreach ($col as $k => $label) {
            $s = slip_score($vname, $label);
            if ($s > $bestScore) { $bestScore = $s; $ticked = $k; }
        }
    }
}
$othersText = $ticked === null ? trim($v['violation'] ?? '') : '';

function box($key, $label, $ticked) {
    $on = ($ticked === $key) ? ' on' : '';
    echo '<div class="cbrow"><span class="cb' . $on . '"></span><span class="cbl">' . $label . '</span></div>';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<!-- These slips build their own <head> instead of including header.php,
     so they never inherited the viewport tag the rest of the app has.
     Without it a phone lays the page out at ~980px and zooms out, which
     is exactly how staff read a slip at the gate. -->
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Student Violation Slip — <?php echo htmlspecialchars($v['fullname']); ?></title>
<style>
  @page { size: 8.5in 6.9in; margin: 0; }
  *{ box-sizing:border-box; }
  body{ margin:0; padding:16px; background:#eef1f6; font-family:Arial, Helvetica, sans-serif; color:#000; }

  .toolbar{ width:8.5in; max-width:100%; margin:0 auto 12px; display:flex; gap:8px; justify-content:flex-end; }
  .toolbar button, .toolbar a{
    padding:8px 14px; border-radius:6px; border:1px solid #1a3a6b; background:#fff;
    color:#1a3a6b; font-weight:700; font-size:.82rem; cursor:pointer; text-decoration:none; }
  .toolbar .go{ background:#1a3a6b; color:#fff; }
  .hint{ width:8.5in; max-width:100%; margin:0 auto 10px; font-size:.8rem; color:#4a5570; }

  /* ---- the slip itself: exactly 8.5in x 6.9in ---- */
  .slip{
    width:8.5in; height:6.9in; margin:0 auto; background:#fff; color:#000;
    border:1.6px solid #000; position:relative; overflow:hidden;
    page-break-inside:avoid; break-inside:avoid; page-break-after:avoid;
  }
  .slip *{ font-family:Arial, Helvetica, sans-serif; }
  [contenteditable]{ outline:none; }
  [contenteditable]:focus{ background:#fff8c9; }

  /* ---------- header ---------- */
  .head{ display:flex; border-bottom:1.6px solid #000; height:1.24in; }
  .head .brand{
    width:3.05in; border-right:1.6px solid #000; padding:6px 8px;
    display:flex; flex-direction:column; justify-content:center; }
  .brand .top{ display:flex; align-items:center; gap:7px; }
  .brand .seal{ width:.34in; height:.34in; object-fit:cover; border-radius:50%;
                clip-path:circle(48% at 50% 50%); mix-blend-mode:multiply; flex:0 0 auto; }
  .brand .nm{ font-size:15.5px; font-weight:800; letter-spacing:.2px; }
  .brand .addr{ font-size:8.2px; font-weight:700; text-align:center; margin-top:1px; }
  .brand .ttl{ font-size:16px; font-weight:800; margin:7px 0 0; }

  .head .fields{ flex:1; display:flex; flex-direction:column; }
  .frow{ display:flex; border-bottom:1px solid #000; flex:1; align-items:center; }
  .frow:last-child{ border-bottom:none; }
  .cell{ padding:2px 6px; display:flex; align-items:center; gap:4px; height:100%; }
  .cell .lb{ font-size:9.6px; font-weight:700; white-space:nowrap; }
  .cell .val{ flex:1; font-size:10.5px; min-height:12px; }
  .grow{ flex:1; border-right:1px solid #000; }
  .w-date{ width:1.28in; border-right:1px solid #000; }
  .w-time{ width:1.02in; }
  .w-course{ width:2.3in; }
  .w-year{ width:2.3in; }

  .statusrow{ display:flex; align-items:center; gap:12px; }
  .offbox{ display:flex; align-items:center; gap:5px; }
  .obx{ width:14px; height:14px; border:1.4px solid #000; display:inline-block; position:relative; }
  .obx.on::after{ content:"\2713"; position:absolute; inset:-3px 0 0 1px;
                  font-size:15px; font-weight:900; line-height:1; }
  .offbox span{ font-size:9.4px; font-weight:700; }

  /* ---------- type of violations ---------- */
  .types{ border-bottom:1.6px solid #000; padding:3px 6px 6px; height:2.42in; }
  .types .cap{ font-size:10.5px; font-weight:800; margin-bottom:3px; }
  .grid{ display:flex; gap:4px; }
  .col{ flex:1; display:flex; flex-direction:column; gap:6.5px; }
  .col.c4{ flex:1.12; }
  .cbrow{ display:flex; align-items:flex-start; gap:6px; }
  .cb{ width:15px; height:15px; border:1.4px solid #000; flex:0 0 auto; margin-top:1px; position:relative; }
  .cb.on::after{ content:"\2713"; position:absolute; inset:-4px 0 0 1px;
                 font-size:16px; font-weight:900; line-height:1; }
  .cbl{ font-size:8.9px; font-weight:700; line-height:1.15; }
  .others .ln{ border-bottom:1px solid #000; height:13px; margin-top:3px; font-size:9.5px; padding:0 2px; }

  /* ---------- bottom ---------- */
  .bottom{ display:flex; height:calc(6.9in - 1.24in - 2.42in - 0.26in); }
  .reason{ width:4.32in; border-right:1.6px solid #000; padding:3px 6px 4px;
           display:flex; flex-direction:column; }
  .reason .cap, .action .cap{ font-size:10.5px; font-weight:800; }
  .reason .body{ flex:1; font-size:10.5px; padding-top:3px; }
  .sigs{ display:flex; gap:14px; }
  .sig{ flex:1; }
  .sig .ln{ border-bottom:1px solid #000; height:15px; }
  .sig .cp{ font-size:8.4px; font-weight:700; margin-top:1px; }
  .mob{ display:flex; gap:14px; margin-top:6px; }
  .mob .m{ flex:1; display:flex; align-items:flex-end; gap:3px; }
  .mob .m .lb{ font-size:8.4px; font-weight:700; white-space:nowrap; }
  .mob .m .ln{ flex:1; border-bottom:1px solid #000; height:13px; font-size:9.5px; }

  .action{ flex:1; padding:3px 8px 4px; display:flex; flex-direction:column; }
  .acts{ margin-top:7px; display:flex; flex-direction:column; gap:9px; }
  .osahead{ margin-top:auto; text-align:center; }
  .osahead .ln{ border-bottom:1px solid #000; height:16px; margin:0 auto; width:2.35in; }
  .osahead .cp{ font-size:10px; font-weight:800; margin-top:2px; }

  .foot{ height:.26in; display:flex; align-items:center; justify-content:flex-end;
         padding-right:6px; font-size:8px; font-weight:700; }

  @media print{
    html, body{ width:8.5in; height:6.9in; background:#fff; padding:0; margin:0;
                overflow:hidden; -webkit-print-color-adjust:exact; print-color-adjust:exact; }
    .toolbar, .hint{ display:none !important; }
    .slip{ margin:0; width:8.5in; height:6.9in; border:1.6px solid #000; }
    [contenteditable]:focus{ background:transparent; }
  }
</style>
</head>
<body>

<div class="toolbar">
  <button type="button" onclick="vtsBack()">&larr; Back</button>
  <a href="violation_slip.php?id=<?php echo $vid; ?>">ID Confiscation Slip</a>
  <button class="go" onclick="window.print()">Print slip</button>
</div>
<p class="hint">Every field below is editable — click any blank or box to fill it in, then Print. Tick a box by clicking it.</p>

<div class="slip">

  <!-- ================= HEADER ================= -->
  <div class="head">
    <div class="brand">
      <div class="top">
        <img class="seal" src="../assets/images/logo.jpg" alt="">
        <div class="nm">GOLDEN WEST COLLEGES</div>
      </div>
      <div class="addr">San Jose Drive, Alaminos City, Pangasinan</div>
      <h1 class="ttl">STUDENT VIOLATION SLIP</h1>
    </div>

    <div class="fields">
      <div class="frow">
        <div class="cell grow"><span class="lb">Student Name:</span>
          <span class="val" contenteditable><?php echo htmlspecialchars($v['fullname']); ?></span></div>
        <div class="cell w-date"><span class="lb">Date:</span>
          <span class="val" contenteditable><?php echo htmlspecialchars(vts_date($v['date_reported'])); ?></span></div>
        <div class="cell w-time"><span class="lb">Time:</span>
          <span class="val" contenteditable><?php echo htmlspecialchars(vts_time($v['date_reported'])); ?></span></div>
      </div>
      <div class="frow">
        <div class="cell grow"><span class="lb">Reporting Officer:</span>
          <span class="val" contenteditable><?php echo htmlspecialchars($v['recorder'] ?? ''); ?></span></div>
        <div class="cell w-course"><span class="lb">Course:</span>
          <span class="val" contenteditable><?php echo htmlspecialchars($v['course'] ?? ''); ?></span></div>
      </div>
      <div class="frow">
        <div class="cell grow statusrow">
          <span class="lb">Status of Violation :</span>
          <?php foreach ([1 => '1st Offense', 2 => '2nd Offense', 3 => '3rd Offense'] as $n => $lbl): ?>
            <span class="offbox"><span class="obx<?php echo ($offenseNum === $n ? ' on' : ''); ?>"></span><span><?php echo $lbl; ?></span></span>
          <?php endforeach; ?>
        </div>
        <div class="cell w-year"><span class="lb">Year/Set:</span>
          <span class="val" contenteditable><?php
            echo htmlspecialchars(trim(($v['year_level'] ?? '') . ' ' . ($v['section'] ?? ''))); ?></span></div>
      </div>
    </div>
  </div>

  <!-- ================= TYPE OF VIOLATIONS ================= -->
  <div class="types">
    <div class="cap">TYPE OF VIOLATIONS:</div>
    <div class="grid">
      <div class="col"><?php foreach ($COL1 as $k => $l) box($k, $l, $ticked); ?></div>
      <div class="col"><?php foreach ($COL2 as $k => $l) box($k, $l, $ticked); ?></div>
      <div class="col"><?php foreach ($COL3 as $k => $l) box($k, $l, $ticked); ?></div>
      <div class="col c4">
        <?php foreach ($COL4 as $k => $l) box($k, $l, $ticked); ?>
        <div class="cbrow"><span class="cb<?php echo ($ticked === null && $othersText !== '') ? ' on' : ''; ?>"></span>
          <span class="cbl">Others <i style="font-weight:600;">(Please Specify)</i></span></div>
        <div class="others">
          <div class="ln" contenteditable><?php echo htmlspecialchars($othersText); ?></div>
          <div class="ln" contenteditable></div>
          <div class="ln" contenteditable></div>
          <div class="ln" contenteditable></div>
        </div>
      </div>
    </div>
  </div>

  <!-- ================= REASON + ACTION ================= -->
  <div class="bottom">
    <div class="reason">
      <div class="cap">REASON FOR VIOLATION:</div>
      <div class="body" contenteditable><?php echo htmlspecialchars($v['description'] ?? ''); ?></div>
      <div class="sigs">
        <div class="sig"><div class="ln" contenteditable></div><div class="cp">SIGNATURE OF STUDENT</div></div>
        <div class="sig"><div class="ln" contenteditable></div><div class="cp">PARENT/GUARDIAN SIGNATURE</div></div>
      </div>
      <div class="mob">
        <div class="m"><span class="lb">MOBILE Nos:</span><span class="ln" contenteditable></span></div>
        <div class="m"><span class="lb">MOBILE Nos:</span><span class="ln" contenteditable></span></div>
      </div>
    </div>

    <div class="action">
      <div class="cap">ACTION TAKEN:</div>
      <div class="acts">
        <div class="cbrow"><span class="cb"></span><span class="cbl" style="font-size:9.6px;">Denial of Entry</span></div>
        <div class="cbrow"><span class="cb"></span><span class="cbl" style="font-size:9.6px;">Admit to Class</span></div>
        <div class="cbrow"><span class="cb"></span><span class="cbl" style="font-size:9.6px;">Leave School Premises</span></div>
      </div>
      <div class="osahead">
        <div class="ln" contenteditable></div>
        <div class="cp">OSA-HEAD</div>
      </div>
    </div>
  </div>

  <div class="foot">GWC-OSA_violationslip2025</div>
</div>

<script>
/* Click any box to tick/untick it before printing. */
document.querySelectorAll('.cb, .obx').forEach(function (b) {
  b.style.cursor = 'pointer';
  b.addEventListener('click', function () { b.classList.toggle('on'); });
});
</script>

<script>
function vtsBack(){
  if (document.referrer && history.length > 1) { history.back(); return; }
  if (window.opener && !window.opener.closed) { window.close(); return; }
  location.href = "../admin/violations.php";
}
</script>
</body>
</html>
