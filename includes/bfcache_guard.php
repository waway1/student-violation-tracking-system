<?php
/* =====================================================================
   BACK/FORWARD MUST NOT RESURRECT A SIGNED-IN PAGE
   =====================================================================

   THE PROBLEM
   Two complaints, one cause:

     1. "If I press Back I can get to other pages — admin, then marshal,
        then admin again." Sign in as Admin, sign out, sign in as
        Guard/Marshal, press Back, and the Admin dashboard is on screen
        again, fully rendered, belonging to a session that no longer
        exists.

     2. "I'm stuck at loading when I log in and hit Back." The sign-in
        page shows "Opening your dashboard…" while it submits. Press Back
        afterwards and that page comes back exactly as it was left —
        spinner still turning, waiting for a navigation that already
        finished. It never resolves because nothing is running.

   WHY no-store DOES NOT COVER IT
   auth/session.php already sends `Cache-Control: no-store, no-cache,
   must-revalidate`, and that is correct and worth keeping — it governs
   the HTTP cache. But Back does not necessarily go through the HTTP
   cache at all. Browsers keep a BACK/FORWARD CACHE: the live page, DOM,
   scroll position, JS heap and all, frozen whole and thawed on Back.
   Nothing is re-fetched, so PHP never runs, so auth/auth.php never gets
   to check who is signed in now. Safari has always ignored no-store for
   this, and Chrome now admits no-store pages to the bfcache too. A
   header cannot fix it; only the restored page itself can.

   THE FIX
   `pageshow` fires on a bfcache restore with `persisted === true` — that
   flag is the one reliable signal that what the user is looking at was
   thawed rather than loaded. Then fetch the page again, so the server
   decides what should be on screen:

     - Signed out, or signed in as someone else? auth/auth.php redirects
       to the login page, or vts_deny_access() takes over. The old role's
       page cannot stay up.
     - Back onto the login page while signed in? student_search.php
       already redirects a live session to its dashboard at the top of
       the file — it just never had the chance to run. Now it does, and
       the stuck spinner goes with it.

   WHY replace() AND NOT reload()
   Several pages render straight from a POST (admin/students.php,
   admin/proof.php and others). reload() repeats the navigation, which
   on those means the browser's "Confirm Form Resubmission" prompt — and
   worse, a repeated write if the person clicks through it.
   location.replace() is always a fresh GET, and it overwrites the
   history entry being restored rather than stacking a new one, so
   pressing Back again keeps going back instead of landing here.

   NO LOOP: the replaced page arrives as a normal load, where pageshow
   fires with persisted === false and this does nothing.

   NOT LOADED BY spck_scanner.html ON PURPOSE. The scanner is a marshal's
   working tool, offline for a whole shift with queued scans held in the
   page. Re-fetching it out from under them mid-shift risks the shift's
   data, which is a far worse outcome than a stale screen — and it is a
   public static file that signs nobody in, so there is nothing to leak.
   ===================================================================== */
?>
<script>
(function () {
  window.addEventListener('pageshow', function (e) {
    if (!e.persisted) return;          // an ordinary load — nothing to undo
    window.location.replace(window.location.href);
  });
})();
</script>
