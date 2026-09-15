<?php
/* Welcome / front page. One clear action — "Look yourself up" → the enrolled-
   student search page. New students Register. Staff use the hidden shortcut
   (Ctrl+Shift+S on a desktop; on a phone, five taps or a long press on the
   seal opens a pad to type the access key into — see assets/js/staff-key.js).
   Already-logged-in users skip straight to their dashboard. */
require_once __DIR__ . "/auth/session.php";   // hardened session start (strict mode, httponly/samesite cookie, fresh ID on first use) + security headers

if (isset($_SESSION['role'])) {
    switch ($_SESSION['role']) {
        case "Admin":        header("Location: admin/dashboard.php");       exit();
        case "OSA":          header("Location: admin/dashboard.php");       exit();
        case "OSA Staff":    header("Location: osa_staff/dashboard.php");   exit();
        /* There is no guard/ folder -- a marshal's home IS the scanner. This
           used to point at guard/dashboard.php, and because .htaccess sends
           anything that is not a real file back to index.php, the redirect
           landed here again and redirected again, one "guard/" longer each
           time, until the browser gave up: /SAD/guard/guard/guard/... That
           is the sign-in that "gets stuck loading". student_search.php and
           vts_deny_access() both already send a Guard to the scanner. */
        case "Guard":        header("Location: spck_scanner.html");        exit();
        case "Student":      header("Location: student/dashboard.php");     exit();
        default:             unset($_SESSION['role']);                      break;
    }
}
$loggedOut = isset($_GET['logout']);   // show a logout-success pop-up
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>QR Shield — Student Violation Tracking System | Golden West Colleges</title>
<!-- Inter + Plus Jakarta Sans, served from this server (assets/vendor/fonts).
     NOT from fonts.googleapis.com: a stylesheet <link> is render-blocking, so
     with no internet every page sat blank until that request timed out. -->
<link rel="stylesheet" href="assets/vendor/fonts/css/fonts.css">
<link rel="stylesheet" href="assets/vendor/fontawesome/css/all.min.css">
<link rel="stylesheet" href="assets/css/landing.css?v=<?php echo @filemtime(__DIR__."/assets/css/landing.css"); ?>">
<!-- The phone's way into the staff login; self-contained, borrows nothing
     from landing.css. -->
<link rel="stylesheet" href="assets/css/staff-key.css?v=<?php echo @filemtime(__DIR__."/assets/css/staff-key.css"); ?>">
</head>
<body>
<?php include __DIR__ . '/includes/bfcache_guard.php'; ?>

<?php /* ===================================================================
         THE BAR
         Four things, which is all the brief asked for: the logo, a search,
         a way in for people who already have an account, and Register. It
         is transparent over the photograph and turns solid the moment the
         page scrolls onto paper -- one bar cannot be legible on a mountain
         and on white without changing.
         =================================================================== */ ?>
<nav class="lp-nav" id="lpNav">
  <a class="lp-brand" href="index.php" aria-label="QR Shield home">
    <?php /* The seal is also the hidden staff door: five taps or a long
             press opens the access-key pad (assets/js/staff-key.js). */ ?>
    <img src="assets/images/logo-sm.jpg" alt="" data-staff-key-trigger>
    <span class="lp-brand-t">
      <span class="lp-brand-n">QR Shield</span>
      <span class="lp-brand-s">Golden West Colleges</span>
    </span>
  </a>

  <div class="lp-nav-links">
    <a href="#why">Why it exists</a>
    <a href="#how">How it works</a>
    <a href="#proof">Tested</a>
  </div>

  <div class="lp-nav-right">
    <?php /* The search IS the student lookup, and it ANSWERS now.

             It used to resolve nothing: whatever you typed was handed to
             student_search.php and this box could not tell you whether your
             record existed. So "Find your record" could not find a record.

             It calls auth/student_lookup_search.php as you type - the same
             endpoint student_search.php already calls one click further on,
             so nothing is exposed here that was not already public. That file
             is strict about it and the reasoning is written out at the top of
             it: names only, never a School ID, three characters minimum for a
             name, and an ID prefix answers only for accounts that already
             have a password. Picking a name carries it to student_search.php,
             which is still the only page that signs anyone in. */ ?>
    <form class="lp-search" id="lpSearchForm" action="student_search.php" method="GET" role="search">
      <i class="fas fa-magnifying-glass ic" aria-hidden="true"></i>
      <label for="lpSearchInput" class="sr-only">Find your student record</label>
      <input type="text" id="lpSearchInput" name="q" placeholder="Find your record"
             autocomplete="off" autocapitalize="off" autocorrect="off" spellcheck="false"
             role="combobox" aria-expanded="false" aria-autocomplete="list"
             aria-controls="lpResults" aria-describedby="lpSearchHelp">
      <span id="lpSearchHelp" class="sr-only">Type at least three letters of your name. Matching students appear below.</span>
      <div class="lp-results" id="lpResults" role="listbox" aria-label="Matching students"></div>
      <?php /* A one-field form submits on Enter, which is why this box worked
               for years without a button. It is not enough on its own: the
               form had no submit control at all, so anything driving the page
               without a keystroke -- a screen reader's forms mode, voice
               control, an automated check -- had nothing to activate. The
               button is hidden because the magnifier beside the field already
               says what the box does; it is here to be reachable, not seen.
               The whole box is display:none on phones, where the hero's own
               lookup takes over. */ ?>
      <button type="submit" class="sr-only">Search for your record</button>
    </form>
    <a class="lp-login" href="student_search.php">Log in</a>
    <a class="lp-pill magnetic" href="register.php">Register</a>
    <button class="lp-burger" id="lpBurger" type="button"
            aria-label="Menu" aria-expanded="false" aria-controls="lpDrawer">
      <i class="fas fa-bars" aria-hidden="true"></i>
    </button>
  </div>
</nav>

<div class="lp-drawer" id="lpDrawer">
  <a href="#why">Why it exists</a>
  <a href="#how">How it works</a>
  <a href="#proof">Tested</a>
  <a href="student_search.php">Log in / Find my record</a>
</div>

<?php /* THE PHOTOGRAPH, with nothing written on it. It drifts at a sixth
         of the scroll speed, inside a box taller than itself so an edge
         never shows. */ ?>
<header class="lp-hero">
  <img class="lp-hero-img" id="lpHeroImg" src="assets/images/school.jpg"
       alt="Golden West Colleges campus">
</header>

<section class="lp-head">
  <h1 class="reveal">Every violation, recorded once.</h1>
  <p class="reveal d1">
    QR Shield replaces the handwritten slip with one scan &mdash; so a record cannot be
    lost, written twice, or argued about a month later. Students can check their own
    standing any time.
  </p>
  <div class="lp-cta reveal d2">
    <a class="lp-btn lp-btn-primary magnetic" href="student_search.php">
      <i class="fas fa-magnifying-glass"></i> Find my record
    </a>
    <a class="lp-btn lp-btn-ghost" href="register.php">Register</a>
  </div>
  <div class="lp-sdg reveal d3">
    <i class="fas fa-graduation-cap" aria-hidden="true"></i>
    Supporting UN SDG&nbsp;4 &mdash; Quality Education
  </div>
</section>

<?php /* =================================================================
         WHY IT EXISTS
         The four findings from the study, in the study's own terms. This
         is the section the brief asked for by name, and it is placed
         before "how it works" on purpose: nobody needs to know how a
         thing works until they know what it was for.
         ================================================================= */ ?>
<section class="lp-sec" id="why">
  <div class="lp-wrap">
    <span class="lp-eyebrow reveal">Why it exists</span>
    <h2 class="reveal">The paper it replaced could not be trusted to still be there.</h2>
    <p class="lp-lead reveal d1">
      Before this, discipline at Golden West Colleges was tracked by hand. The research
      behind QR Shield found four problems with that, and each one is a thing the system
      now makes impossible rather than merely discourages.
    </p>
    <div class="lp-grid">
      <article class="lp-card reveal">
        <span class="n">1</span>
        <h3>Handwriting loses things</h3>
        <p>Paper records were prone to errors, data loss, duplication and delay &mdash; the same
           incident written twice, or not at all, or arriving weeks late.</p>
      </article>
      <article class="lp-card reveal d1">
        <span class="n">2</span>
        <h3>No one place to look</h3>
        <p>There was no central database of violation histories, so a student&rsquo;s record
           depended on which folder, which officer, and which year you asked about.</p>
      </article>
      <article class="lp-card reveal d2">
        <span class="n">3</span>
        <h3>Students could not check</h3>
        <p>A student had no way to verify their own standing independently. Being told what
           is on your record is not the same as being able to see it.</p>
      </article>
      <article class="lp-card reveal d3">
        <span class="n">4</span>
        <h3>People move on</h3>
        <p>Staff turnover made record-keeping unstable: the filing system was whoever
           happened to be keeping it, and it left when they did.</p>
      </article>
    </div>
  </div>
</section>

<div class="lp-band">
  <img id="lpBandImg" src="assets/images/bg.jpg" alt="">
  <div class="cap">One scan at the gate. One record, for good.</div>
</div>

<?php /* =================================================================
         WHY IT IS BUILT THE WAY IT IS
         Three modes, and the reason for them: a gate does not always have
         Wi-Fi, and a violation that cannot be recorded until it does is a
         violation that does not get recorded.
         ================================================================= */ ?>
<section class="lp-sec navy" id="how">
  <div class="lp-wrap">
    <span class="lp-eyebrow reveal">Why it works the way it does</span>
    <h2 class="reveal">A campus gate does not always have a signal.</h2>
    <p class="lp-lead reveal d1">
      That single fact shaped the whole design. A marshal cannot wait for a connection with
      a student standing in front of them, so the scanner works whether or not there is one
      and settles up afterwards.
    </p>
    <div class="lp-modes">
      <article class="lp-mode reveal">
        <span class="ic"><i class="fas fa-plug-circle-xmark"></i></span>
        <h3>Offline</h3>
        <p>Guards scan QR codes with no network at all. The shift is carried back on a USB
           as one encrypted file and imported by the office.</p>
      </article>
      <article class="lp-mode reveal d1">
        <span class="ic"><i class="fas fa-bolt"></i></span>
        <h3>Online</h3>
        <p>Where there is a server, a scan reaches it as it happens, and the notifications
           that follow go out by email straight away.</p>
      </article>
      <article class="lp-mode reveal d2">
        <span class="ic"><i class="fas fa-rotate"></i></span>
        <h3>Hybrid</h3>
        <p>Signal comes and goes. Anything recorded while it was gone syncs by itself the
           moment it returns &mdash; nobody has to remember to do it.</p>
      </article>
    </div>
  </div>
</section>

<?php /* =================================================================
         WHAT IT WAS MEASURED AGAINST
         The numbers, because a thesis is judged on them and because a
         claim of "it works" is worth what the evaluation behind it is.
         ================================================================= */ ?>
<section class="lp-sec paper" id="proof">
  <div class="lp-wrap">
    <span class="lp-eyebrow reveal">Tested, not assumed</span>
    <h2 class="reveal">Seventy-three people used it before anyone called it finished.</h2>
    <p class="lp-lead reveal d1">
      QR Shield was built with Agile &mdash; planning, requirements, design, development,
      testing, feedback, repeat &mdash; and assessed against <b>ISO/IEC&nbsp;25010</b> for
      functionality, usability, reliability, efficiency and security.
    </p>
    <div class="lp-stats">
      <div class="lp-stat reveal"><div class="v">60</div><div class="k">students, across five departments</div></div>
      <div class="lp-stat reveal d1"><div class="v">10</div><div class="k">marshals</div></div>
      <div class="lp-stat reveal d2"><div class="v">2</div><div class="k">guards</div></div>
      <div class="lp-stat reveal d3"><div class="v">1</div><div class="k">OSA administrator</div></div>
    </div>
    <p class="lp-lead reveal d2" style="margin-top:30px;">
      Built by an eight-member team as a bachelor&rsquo;s thesis, on PHP, MySQL, JavaScript,
      Node.js, HTML/CSS and JSON.
    </p>
  </div>
</section>

<section class="lp-sec">
  <div class="lp-wrap u-center">
    <h2 class="reveal" style="max-width:none;margin-inline:auto;">Check what is on your record.</h2>
    <p class="lp-lead reveal d1" style="margin-inline:auto;">
      No password needed to look yourself up. New students register once.
    </p>
    <div class="lp-cta reveal d2">
      <a class="lp-btn lp-btn-primary magnetic" href="student_search.php">
        <i class="fas fa-magnifying-glass"></i> Find my record
      </a>
      <a class="lp-btn lp-btn-ghost" href="register.php">Register</a>
    </div>
  </div>
</section>

<footer class="lp-foot">
  <div class="row">
    <a href="student_search.php">Find my record</a>
    <a href="register.php">Register</a>
    <a href="#why">Why it exists</a>
    <a href="#how">How it works</a>
  </div>
  <div>&copy; <?php echo date('Y'); ?> Golden West Colleges, Inc. &middot;
    QR Shield &mdash; Student Violation Tracking System</div>
</footer>

<button id="lpTop" type="button" aria-label="Back to top"><i class="fas fa-arrow-up"></i></button>

<?php if ($loggedOut): ?>
<div id="logoutToast" style="position:fixed;top:88px;left:50%;transform:translateX(-50%) translateY(-16px);z-index:9000;
     display:flex;align-items:center;gap:11px;background:#fff;color:#0f172a;border-radius:12px;
     padding:13px 20px;box-shadow:0 16px 40px rgba(8,18,40,.35);opacity:0;transition:opacity .35s ease, transform .35s ease;">
  <span style="width:30px;height:30px;border-radius:50%;background:#dcfce7;color:#16a34a;display:flex;align-items:center;justify-content:center;font-size:.95rem;"><i class="fas fa-check"></i></span>
  <span style="font-weight:700;font-size:.92rem;">Logged out successfully</span>
</div>
<?php endif; ?>

<script>
/* Hidden staff shortcut on the welcome page -> opens the staff login page.
   Keyboard only, so it is desktop only; the pad on the seal is the phone's
   equivalent and lands on the same URL. */
document.addEventListener('keydown', function(e){
  if (e.ctrlKey && e.shiftKey && (e.key === 'S' || e.key === 's')){ e.preventDefault(); window.location.href = 'student_search.php?staff=1'; }
});
/* Logout-success pop-up: slide in, then auto-dismiss + clean the URL. */
(function(){
  var t = document.getElementById('logoutToast');
  if (!t) return;
  requestAnimationFrame(function(){ t.style.opacity='1'; t.style.transform='translateX(-50%) translateY(0)'; });
  if (history.replaceState) history.replaceState(null,'',window.location.pathname);
  setTimeout(function(){ t.style.opacity='0'; t.style.transform='translateX(-50%) translateY(-16px)'; }, 3200);
  setTimeout(function(){ if (t.parentNode) t.parentNode.removeChild(t); }, 3700);
})();
</script>

<script src="assets/js/landing.js?v=<?php echo @filemtime(__DIR__."/assets/js/landing.js"); ?>" defer></script>
<!-- Staff access key. This page has no staff modal of its own, so a correct
     key travels to the page that does, carrying the flag Ctrl+Shift+S uses. -->
<script src="assets/js/staff-key.js?v=<?php echo @filemtime(__DIR__."/assets/js/staff-key.js"); ?>"
        data-key="gwcstaff" data-target="student_search.php?staff=1" defer></script>
</body>
</html>
