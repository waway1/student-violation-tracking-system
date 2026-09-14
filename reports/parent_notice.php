<?php
/* Printable Parent/Guardian Notification Letter — informs the parent of a
   student's violation. The requested action adapts to the severity/offense
   ("inform parents depending on the offense"). Browser-print; no PDF library. */
require_once "../auth/auth.php";
require_once "../config/database.php";
require_once "../includes/functions.php";

if (!in_array($_SESSION['role'] ?? '', ['Admin','OSA'], true)) {
    exit("Access Denied");
}

$vid = (int)($_GET['id'] ?? 0);
$stmt = $conn->prepare("
    SELECT v.*, u.student_id AS sid, u.fullname, u.course, u.year_level, u.section, u.gender
    FROM violations v
    INNER JOIN users u ON v.student_id = u.id
    WHERE v.id = :id LIMIT 1");
$stmt->execute([':id' => $vid]);
$v = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$v) { exit("Violation not found."); }

$severity = $v['severity'] ?? 'Minor';
$offNum   = offense_number($v['offense'] ?? '');
$refNo    = 'PN-' . str_pad((string)$vid, 5, '0', STR_PAD_LEFT);
$yearSet  = trim(($v['year_level'] ?? '') . ' ' . ($v['section'] ?? ''));
$childRel = ($v['gender'] ?? '') === 'Female' ? 'daughter' : (($v['gender'] ?? '') === 'Male' ? 'son' : 'child');

// Requested action escalates with severity / repeat offense.
if ($severity === 'Grave') {
    $action = "Given the gravity of this offense, you are REQUIRED to appear for a Parent/Guardian Conference with the Office of Student Affairs at the earliest possible time. This offense forms part of your {$childRel}'s permanent record.";
    $tone   = "serious";
} elseif ($severity === 'Major' || $offNum >= 3) {
    $action = "You are requested to appear for a Parent/Guardian Conference and to guide your {$childRel} in complying with the school's policies. Repeated offenses will result in more severe disciplinary action.";
    $tone   = "major";
} else {
    $action = "This letter serves as a formal notice and warning. We ask for your support in reminding your {$childRel} to observe the rules and regulations of the institution to avoid further offenses.";
    $tone   = "minor";
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
<title>Parent Notice <?php echo htmlspecialchars($refNo); ?></title>
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
  .title{text-align:center;font-size:1.1rem;font-weight:800;letter-spacing:.08em;margin:12px 0 18px;}
  .meta{font-size:.82rem;color:#444;margin-bottom:16px;}
  p.body{font-size:.94rem;line-height:1.8;text-align:justify;margin-bottom:12px;}
  .box{border:1px solid #1a2340;border-radius:6px;padding:10px 14px;margin:14px 0;font-size:.9rem;}
  .box .r{display:flex;gap:8px;margin:3px 0;}
  .box .k{font-weight:700;min-width:120px;color:#333;}
  .viol{font-weight:800;color:#b0122b;}
  .tag{display:inline-block;padding:1px 10px;border-radius:999px;font-size:.72rem;font-weight:800;}
  .tag.serious{background:#fdeaee;color:#b0122b;} .tag.major{background:#fdf3e0;color:#c47f00;} .tag.minor{background:#e9f7ef;color:#2a7a4b;}
  .sign{display:flex;gap:40px;margin-top:40px;}
  .sign .s{flex:1;}
  .sign .line{border-top:1px solid #333;margin-top:30px;padding-top:5px;font-size:.8rem;}
  .sign .nm{font-weight:800;font-size:.9rem;}
  .sign .rl{font-size:.74rem;color:#444;}
  .ack{border-top:1px dashed #999;margin-top:30px;padding-top:14px;font-size:.85rem;line-height:1.8;}
  .foot{text-align:right;font-size:.66rem;color:#888;margin-top:18px;}
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
    <h1 class="title">PARENT / GUARDIAN NOTIFICATION</h1>

    <div class="meta">Ref. No.: <b><?php echo htmlspecialchars($refNo); ?></b> &nbsp;·&nbsp; Date: <b><?php echo htmlspecialchars(vts_date($v['date_reported'])); ?></b></div>

    <p class="body">Dear Parent / Guardian,</p>
    <p class="body">
      This is to formally inform you that your <?php echo htmlspecialchars($childRel); ?>,
      <b><?php echo htmlspecialchars($v['fullname'] ?? ''); ?></b>, was found to have committed the following
      violation of the Student Handbook:
    </p>

    <div class="box">
      <div class="r"><span class="k">Student ID</span><span><?php echo htmlspecialchars($v['sid'] ?? ''); ?></span></div>
      <div class="r"><span class="k">Course / Year &amp; Set</span><span><?php echo htmlspecialchars(trim(($v['course'] ?? '') . ' — ' . $yearSet)); ?></span></div>
      <div class="r"><span class="k">Violation</span><span class="viol"><?php echo htmlspecialchars($v['violation']); ?></span></div>
      <div class="r"><span class="k">Classification</span><span><span class="tag <?php echo $tone; ?>"><?php echo htmlspecialchars(strtoupper($severity)); ?></span> &nbsp; <?php echo htmlspecialchars(offense_display($v['offense'])); ?></span></div>
      <div class="r"><span class="k">Date Recorded</span><span><?php echo htmlspecialchars(vts_datetime($v['date_reported'])); ?></span></div>
    </div>

    <p class="body"><?php echo htmlspecialchars($action); ?></p>
    <p class="body">
      We value our partnership with you in the formation and discipline of our students. Thank you for your
      understanding and cooperation.
    </p>

    <div class="sign">
      <div class="s"><div class="line"><span class="nm">ALMA G. VIRAY</span><br><span class="rl">Head, Office of Student Affairs</span></div></div>
    </div>

    <div class="ack">
      <b>ACKNOWLEDGEMENT</b> — I have received and read this notice regarding my <?php echo htmlspecialchars($childRel); ?>'s violation.<br><br>
      _______________________________&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;Date: ____________________<br>
      <span style="font-size:.76rem;color:#555;">Parent / Guardian Signature over Printed Name</span>
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
