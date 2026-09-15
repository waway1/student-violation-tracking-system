<?php
/* ID CONFISCATION SLIP — 5.5in wide x 4.5in tall, yellow stock.
   This is a DIFFERENT document from the Student Violation Slip: this one is
   issued when the student's ID is taken, and is what they present to claim it.
   Layout mirrors the printed GWC-OSA_idconfiscationslip2026 form.
   Every field is editable on screen (contenteditable) before printing. */
require_once "../auth/auth.php";
require_once "../config/database.php";
require_once "../includes/functions.php";

if (!in_array($_SESSION['role'] ?? '', ['Admin','OSA'], true)) {
    exit("Access Denied");
}

$vid = (int)($_GET['id'] ?? 0);
$stmt = $conn->prepare("
    SELECT v.*, u.student_id AS sid, u.fullname, u.course, u.year_level, u.section
    FROM violations v
    INNER JOIN users u ON v.student_id = u.id
    WHERE v.id = :id LIMIT 1");
$stmt->execute([':id' => $vid]);
$v = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$v) { exit("Violation not found."); }

$slipNo   = 'V-' . str_pad((string)$vid, 5, '0', STR_PAD_LEFT);
$yearSet  = trim(($v['year_level'] ?? '') . ' ' . ($v['section'] ?? ''));
$validTil = date('F j, Y', strtotime(($v['date_reported'] ?? 'now') . ' +7 days'));
$osaHead  = defined('OSA_HEAD_NAME') ? OSA_HEAD_NAME : 'ALMA G. VIRAY';
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
<title>ID Confiscation Slip — <?php echo htmlspecialchars($v['fullname']); ?></title>
<style>
  @page { size: 5.5in 4.5in; margin: 0; }
  *{ box-sizing:border-box; }
  body{ margin:0; padding:16px; background:#eef1f6; font-family:Arial, Helvetica, sans-serif; }

  .toolbar{ width:5.5in; max-width:100%; margin:0 auto 12px; display:flex; gap:8px; justify-content:flex-end; }
  .toolbar button, .toolbar a{
    padding:8px 14px; border-radius:6px; border:1px solid #1a3a6b; background:#fff;
    color:#1a3a6b; font-weight:700; font-size:.82rem; cursor:pointer; text-decoration:none; }
  .toolbar .go{ background:#1a3a6b; color:#fff; }
  .hint{ width:5.5in; max-width:100%; margin:0 auto 10px; font-size:.8rem; color:#4a5570; }

  /* ---- the slip itself: exactly 5.5in x 4.5in ---- */
  .slip{
    width:5.5in; height:4.5in; margin:0 auto; background:#fdf4b8; color:#000;
    padding:.17in .2in .1in; position:relative; overflow:hidden;
    page-break-inside:avoid; break-inside:avoid; page-break-after:avoid;
  }
  .slip *{ font-family:Arial, Helvetica, sans-serif; }
  [contenteditable]{ outline:none; }
  [contenteditable]:focus{ background:#fffbe0; }

  /* ---------- header ---------- */
  .head{ display:flex; align-items:center; justify-content:center; gap:10px; }
  .seal{ width:.4in; height:.4in; object-fit:cover; border-radius:50%;
         clip-path:circle(48% at 50% 50%); mix-blend-mode:multiply; flex:0 0 auto; }
  .htext{ text-align:center; }
  .htext .nm{ font-size:12.6px; font-weight:800; letter-spacing:.2px; }
  .htext .ad{ font-size:8.4px; font-weight:700; }
  .htext .of{ font-size:8.6px; font-weight:800; }

  /* Title: centered, on its own line, above Date / Slip No. */
  .ttl{ font-size:15px; font-weight:800; text-align:center; margin:7px 0 0; letter-spacing:.3px; }
  /* Date (left) and Slip No. (right) share one line. */
  .daterow .ln{ flex:1; }

  /* ---------- field lines ---------- */
  .fl{ display:flex; align-items:flex-end; gap:5px; margin-top:8px; }
  .fl .lb{ font-size:9.8px; font-weight:700; white-space:nowrap; }
  .fl .ln{ flex:1; border-bottom:1px solid #000; height:15px; font-size:10.4px; padding:0 3px; }
  .fl .ln.sm{ flex:0 0 1.5in; }

  /* ---------- action checkboxes ----------
     Label + "Warning/Parent/Other" column on the left; the "Written
     explanation / Payment of penalty" pair sits to their right, top-aligned
     with the label — exactly as on the printed slip. */
  .actwrap{ display:flex; gap:10px; margin-top:8px; align-items:flex-start; }
  .actleft{ flex:1; }
  .actlabel{ font-size:9.8px; font-weight:700; }
  .actright{ flex:0 0 1.95in; display:flex; flex-direction:column; gap:4px; padding-top:2px; }
  .acol{ display:flex; flex-direction:column; gap:4px; margin-top:4px; }
  .cbrow{ display:flex; align-items:center; gap:5px; }
  .cb{ width:11px; height:11px; border:1.2px solid #000; flex:0 0 auto; position:relative; cursor:pointer; }
  .cb.on::after{ content:"\2713"; position:absolute; inset:-4px 0 0 0;
                 font-size:13px; font-weight:900; line-height:1; }
  .cbl{ font-size:9.2px; font-weight:700; }
  .cbrow .ln{ flex:1; border-bottom:1px solid #000; height:11px; font-size:9px; }

  /* ---------- claiming box ---------- */
  .claim{ border:1.3px solid #000; padding:5px 7px; margin-top:9px; }
  .claim .cap{ font-size:9.6px; font-weight:800; }
  .claim .txt{ font-size:8.9px; line-height:1.25; margin-top:1px; }
.rel{
    position:absolute;
    right:.22in;
    bottom:.37in;   /* Raise it from the footer */
    text-align:center;
}
.rel .box{
    width:1.45in;
}

.rel .lb{
    font-size:8.6px;
    font-weight:700;
}

.rel .nm{
    display:block;
    border-bottom:1px solid #000;
    font-size:9.4px;
    font-weight:800;
    margin-top:2px;
    padding-bottom:2px;
}

.rel .cp{
    font-size:8.4px;
    font-weight:700;
    margin-top:2px;
}
.sig{
    display:flex;
    align-items:flex-end;
    gap:5px;

    position:absolute;
    left:.20in;      /* Move left/right */
    bottom:.48in;    /* Move up/down */
}

.sig .lb{
    font-size:9.8px;
    font-weight:700;
}

.sig .ln{
    flex:0 0 2.1in;
    border-bottom:1px solid #000;
    height:13px;
}
  .foot{ position:absolute; right:.14in; bottom:.06in; font-size:7px; font-weight:700; }

  @media print{
    html, body{ width:5.5in; height:4.5in; background:#fdf4b8; padding:0; margin:0;
                overflow:hidden; -webkit-print-color-adjust:exact; print-color-adjust:exact; }
    .toolbar, .hint{ display:none !important; }
    .slip{ margin:0; width:5.5in; height:4.5in; }
    [contenteditable]:focus{ background:transparent; }
  }
</style>
</head>
<body>

<div class="toolbar">
  <button type="button" onclick="vtsBack()">&larr; Back</button>
  <a href="student_violation_slip.php?id=<?php echo $vid; ?>">Student Violation Slip</a>
  <button class="go" onclick="window.print()">Print slip</button>
</div>
<p class="hint">Every blank below is editable — click to fill it in, click a box to tick it, then Print.</p>

<div class="slip">

  <div class="head">
    <img class="seal" src="../assets/images/logo.jpg" alt="">
    <div class="htext">
      <div class="nm">GOLDEN WEST COLLEGES, INC.</div>
      <div class="ad">San Jose Drive, Alaminos City, Pangasinan</div>
      <div class="of">Office of Student Affairs</div>
    </div>
    <img class="seal" src="../assets/images/student affairs logo.jpg" alt="">
  </div>

  <!-- Title is CENTERED on its own line, above Date / Slip No. -->
  <h1 class="ttl">ID CONFISCATION SLIP</h1>

  <!-- Date on the left, Slip No. on the same line to its right. -->
  <div class="fl daterow">
    <span class="lb">Date:</span>
    <span class="ln" contenteditable><?php echo htmlspecialchars(vts_date($v['date_reported'])); ?></span>
    <span class="lb">Slip No.:</span>
    <span class="ln" contenteditable><?php echo htmlspecialchars($slipNo); ?></span>
  </div>

  <div class="fl"><span class="lb">Student ID No.:</span>
    <span class="ln" contenteditable><?php echo htmlspecialchars($v['sid'] ?? ''); ?></span></div>

  <div class="fl"><span class="lb">Name of Student:</span>
    <span class="ln" contenteditable><?php echo htmlspecialchars($v['fullname']); ?></span>
    <span class="lb">Year &amp; Set:</span>
    <span class="ln sm" contenteditable><?php echo htmlspecialchars($yearSet); ?></span></div>

  <div class="fl"><span class="lb">Course:</span>
    <span class="ln" contenteditable><?php echo htmlspecialchars($v['course'] ?? ''); ?></span></div>

  <div class="fl"><span class="lb">Reason for ID Confiscation:</span>
    <span class="ln" contenteditable><?php echo htmlspecialchars($v['violation'] ?? ''); ?></span></div>

  <!-- On the printed form the label sits on the LEFT with the "Written
       explanation / Payment of penalty" pair beside it, and the Warning /
       Parent-Guardian / Other column runs underneath. -->
  <div class="actwrap">
    <div class="actleft">
      <div class="actlabel">Action required for ID Release:</div>
      <div class="acol">
        <div class="cbrow"><span class="cb"></span><span class="cbl">Warning</span></div>
        <div class="cbrow"><span class="cb"></span><span class="cbl">Parent/Guardian Conference</span></div>
        <div class="cbrow"><span class="cb"></span><span class="cbl">Other:</span><span class="ln" contenteditable></span></div>
      </div>
    </div>
    <div class="actright">
      <div class="cbrow"><span class="cb"></span><span class="cbl">Written explanation</span></div>
      <div class="cbrow"><span class="cb"></span><span class="cbl">Payment of penalty (if applicable)</span></div>
    </div>
  </div>

  <div class="fl"><span class="lb">Valid Until:</span>
    <span class="ln" contenteditable><?php echo htmlspecialchars($validTil); ?></span></div>

<div class="claim">
    <div class="cap">CLAIMING OF IDENTIFICATION CARD</div>
    <div class="txt">
        I acknowledge that my school ID was confiscated due to the violation stated above
        and agree to comply with school policies before claiming it.
    </div>
</div>

<div class="sig">
    <span class="lb">Student Signature:</span>
    <span class="ln" contenteditable></span>
</div>

<div class="rel">
    <div class="box">
        <div class="lb">Released by:</div>
        <div class="nm" contenteditable><?php echo htmlspecialchars($osaHead); ?></div>
        <div class="cp">Head, OSA</div>
    </div>
</div>
  <div class="foot">GWC-OSA_idconfiscationslip2026</div>
</div>

<script>
document.querySelectorAll('.cb').forEach(function (b) {
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
