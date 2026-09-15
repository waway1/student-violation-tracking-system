<?php
/* Printable Student Undertaking — a formal promise the student signs after a
   violation. Prints from the browser (Ctrl+P / Save as PDF); no PDF library
   needed. Opened from the Admin/OSA violations list or the ID claim desk. */
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

$severity = $v['severity'] ?? 'Minor';
$offNum   = offense_number($v['offense'] ?? '');
$refNo    = 'UND-' . str_pad((string)$vid, 5, '0', STR_PAD_LEFT);
$yearSet  = trim(($v['year_level'] ?? '') . ' ' . ($v['section'] ?? ''));

// Consequence line escalates with severity / repeat offense.
if ($severity === 'Grave') {
    $consequence = "I fully understand that this is a GRAVE offense and that any repetition, or violation of this undertaking, may result in suspension, non-readmission, or dismissal, and will remain part of my permanent record for academic and honors evaluation.";
} elseif ($severity === 'Major' || $offNum >= 3) {
    $consequence = "I fully understand that a repetition of this or any other offense may lead to a parent/guardian conference and more severe disciplinary action in accordance with the Student Handbook.";
} else {
    $consequence = "I understand that this serves as a formal warning and that repeated offenses will escalate the disciplinary action taken against me under the Student Handbook.";
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
<title>Undertaking <?php echo htmlspecialchars($refNo); ?></title>
<style>
  *{box-sizing:border-box;margin:0;padding:0;font-family:'Segoe UI',Arial,sans-serif;}
  body{background:#eef1f6;padding:24px;color:#1a2340;}
  .toolbar{max-width:700px;margin:0 auto 16px;display:flex;gap:10px;justify-content:flex-end;}
  .btn{padding:9px 18px;border-radius:8px;border:0;font-weight:700;cursor:pointer;text-decoration:none;font-size:.9rem;}
  .btn-print{background:#1a3a6b;color:#fff;}
  .btn-back{background:#fff;color:#1a3a6b;border:1px solid #ccd4e4;}
  .doc{max-width:700px;margin:0 auto;background:#fff;border:2px solid #1a2340;padding:34px 40px;}
  .head{text-align:center;border-bottom:2px solid #1a2340;padding-bottom:12px;margin-bottom:18px;}
  .head .sch{font-size:1.2rem;font-weight:800;letter-spacing:.02em;}
  .head .addr{font-size:.78rem;color:#333;margin-top:2px;}
  .head .osa{font-size:.82rem;font-weight:700;margin-top:2px;}
  .title{text-align:center;font-size:1.15rem;font-weight:800;letter-spacing:.1em;margin:12px 0 20px;text-decoration:underline;}
  .meta{display:flex;justify-content:space-between;font-size:.82rem;color:#444;margin-bottom:18px;}
  p.body{font-size:.95rem;line-height:1.9;text-align:justify;margin-bottom:14px;}
  .fillline{border-bottom:1px solid #333;padding:0 6px;font-weight:700;}
  .viol{font-weight:800;color:#b0122b;}
  .sign{display:flex;gap:40px;margin-top:46px;}
  .sign .s{flex:1;text-align:center;}
  .sign .line{border-top:1px solid #333;margin-top:30px;padding-top:5px;font-size:.8rem;}
  .sign .nm{font-weight:800;font-size:.9rem;}
  .sign .rl{font-size:.74rem;color:#444;}
  .foot{text-align:right;font-size:.66rem;color:#888;margin-top:20px;}
  @media print{ body{background:#fff;padding:0;} .toolbar{display:none;} .doc{border:1.5px solid #000;max-width:100%;margin:0;} }
</style>
</head>
<body>
  <div class="toolbar">
    <button type="button" onclick="vtsBack()" class="btn btn-back">&larr; Back</button>
    <button class="btn btn-print" onclick="window.print()">🖨 Print / Save as PDF</button>
  </div>

  <div class="doc">
    <div class="head">
      <div class="sch">GOLDEN WEST COLLEGES, INC.</div>
      <div class="addr">San Jose Drive, Alaminos City, Pangasinan</div>
      <div class="osa">Office of Student Affairs</div>
    </div>
    <h1 class="title">STUDENT UNDERTAKING</h1>

    <div class="meta">
      <span>Ref. No.: <b><?php echo htmlspecialchars($refNo); ?></b></span>
      <span>Date: <b><?php echo htmlspecialchars(vts_date($v['date_reported'])); ?></b></span>
    </div>

    <p class="body">
      I, <span class="fillline"><?php echo htmlspecialchars($v['fullname'] ?? ''); ?></span>,
      bearing Student ID No. <span class="fillline"><?php echo htmlspecialchars($v['sid'] ?? ''); ?></span>,
      of <span class="fillline"><?php echo htmlspecialchars(trim(($v['course'] ?? '') . ' — ' . $yearSet)); ?></span>,
      do hereby acknowledge that I committed the following violation of the Student Handbook of Golden West Colleges, Inc.:
    </p>
    <p class="body u-center">
      <span class="viol"><?php echo htmlspecialchars($v['violation'] . ' — ' . strtoupper($severity) . ' (' . offense_display($v['offense']) . ')'); ?></span>
    </p>
    <p class="body">
      I sincerely acknowledge my fault and hereby <b>undertake and solemnly promise</b> that I will not commit
      the same or any similar violation again, and that I will conduct myself in accordance with the rules,
      regulations, and code of conduct of the institution at all times.
    </p>
    <p class="body"><?php echo htmlspecialchars($consequence); ?></p>

    <div class="sign">
      <div class="s"><div class="line">Student's Signature over Printed Name</div></div>
      <div class="s"><div class="line">Parent's / Guardian's Signature</div></div>
    </div>
    <div class="sign" style="margin-top:34px;">
      <div class="s"><div class="line"><span class="nm">ALMA G. VIRAY</span><br><span class="rl">Head, Office of Student Affairs</span></div></div>
    </div>

    <div class="foot">GWC-OSA · QR Shield · <?php echo htmlspecialchars($refNo); ?></div>
  </div>

  <?php if (isset($_GET['print'])): ?>
  <script>window.addEventListener('load', () => window.print());</script>
  <?php endif; ?>

<script>
function vtsBack(){
  if (document.referrer && history.length > 1) { history.back(); return; }
  if (window.opener && !window.opener.closed) { window.close(); return; }
  location.href = "../admin/violations.php";
}
</script>
</body>
</html>
