<?php
/* =====================================================================
   WHAT THIS SYSTEM IS CALLED.

   The name was hardcoded in about thirty places — page titles, the navbar,
   e-mail subjects, printed report footers, two PWA manifests — so renaming
   it meant finding every one of them. It is defined here once instead.

   There are four forms because one string cannot do every job. The formal
   title is a sentence; a navbar is about twenty characters wide and a PWA
   short name is twelve. Using the long title everywhere would push the
   layout apart and truncate to nonsense in a phone's app drawer, so each
   place takes the longest form that fits it:

     APP_NAME        the product, for chrome that has no room to explain
     APP_TAGLINE     what it does, shown under or beside APP_NAME
     APP_NAME_FULL   both, for browser tabs and e-mail subjects
     APP_TITLE_FULL  the complete formal title — landing page and documents,
                     where a title is being STATED rather than used as a label
   ===================================================================== */

define('APP_NAME',    'QR Shield');
define('APP_TAGLINE', 'Student Violation Tracking System');
define('APP_NAME_FULL', APP_NAME . ' — ' . APP_TAGLINE);

/* The full title as submitted. Note the en dash before "Quality Education"
   and the comma in "Golden West Colleges, Inc." — both are part of it. */
define('APP_TITLE_FULL',
    'QR Shield: A QR Code-Based Student Violation Tracking System for Promoting '
  . 'Safe and Inclusive Learning at Golden West Colleges, Inc. Supporting SDG 4 '
  . '– Quality Education');

/* The institution, kept beside the name because almost everywhere that
   prints one prints the other. */
define('APP_SCHOOL', 'Golden West Colleges, Inc.');

/* =====================================================================
   STAFF TWO-STEP SIGN-IN (login OTP).

   A correct staff password used to be the whole of the door. These accounts
   can edit and delete another student's disciplinary record, so a password
   that leaks — reused, shoulder-surfed at the gate, typed into the shared
   scanner phone — is the whole system. With this on, the password is only
   the first half: the second is a 6-digit code emailed to the address the
   office holds for that account, entered on login_otp.php before any
   session is granted.

   STAFF_OTP_ROLES is deliberately every non-Student role. Students are not
   listed because they have no password at all — they sign in by looking
   themselves up — so there is no first factor for a second one to follow.

   A code lives 10 minutes, not the 30 that email verification uses: this
   one is typed straight away, from an inbox already open, and a shorter
   life is a smaller window for a code read off someone's lock screen.
   ===================================================================== */
define('STAFF_OTP_ENABLED', false);
define('STAFF_OTP_ROLES',   ['Admin', 'OSA', 'OSA Staff', 'Guard']);
define('STAFF_OTP_TTL',     600);   // seconds a code stays valid (10 minutes)
define('STAFF_OTP_RESEND',  60);    // seconds before another code may be sent

/* =====================================================================
   STUDENT PASSWORDS — the switch that ends the grace period.

   Students signed in with no password: they looked themselves up and typed
   their School ID. Registration now asks for a password and login asks for
   it, but the students already enrolled do not have one yet.

   While this is false, a student WITHOUT a password can still sign in the
   old way (School ID + last name) and is asked to set one. A student WITH a
   password must always use it — nobody can downgrade themselves back to the
   weaker path by clearing a field.

   Set it to true once everyone has moved across (Admin > Students shows who
   has not). From then on the last-name route is closed for good.
   ===================================================================== */
/* Flipped to true when the "Last name" box was removed from the sign-in card.
   That box was the ONLY way a student without a password could supply the
   second half of their credential; with it gone, leaving this false would
   refuse them for a field the page no longer offers — the exact deadlock this
   was reported for. True means: no password, no sign-in — use Forgot password
   (it accepts a School ID) or ask the OSA. Set it back to false only if the
   Last name box comes back with it. */
define('STUDENT_PASSWORD_REQUIRED', true);

/* =====================================================================
   FOOTER LINKS ON THE PUBLIC AUTH PAGES.

   The login card names a privacy policy, a student handbook and a security
   helpdesk. Leave a URL blank and that item renders as plain text instead of
   a link — a footer link that 404s is worse than no link, and a student
   entering a password is exactly the wrong audience for a dead end.
   Fill these in when the real pages exist.
   ===================================================================== */
define('LINK_PRIVACY',  '');                                  // e.g. 'privacy.php'
define('LINK_HANDBOOK', '');                                  // e.g. 'assets/docs/student-handbook.pdf'
define('LINK_HELPDESK', 'mailto:goldenweststudentaffairs@gmail.com');

/* =====================================================================
   WHEN THE GATE IS STAFFED — the scanning duty schedule.

   Marshals may only go on duty inside this window. Outside it the scanner
   refuses to start a shift and says when it next opens, rather than letting
   someone sign on at 11pm and quietly record violations against a gate
   nobody is standing at.

   DAYS use date('N'): Monday is 1 … Sunday is 7. Saturday (6) is in;
   Sunday (7) is not.

   THE GRACE PERIOD is not extra scanning time — a shift that starts inside
   the window gets a session that ends at DUTY_END plus this, purely so a
   marshal closing up at 6pm can still finish syncing and export the
   hand-over file. Claiming a NEW slot in the grace is still refused.

   All of this is read in Philippine time (includes/functions.php sets
   Asia/Manila, and the DB connection sets +08:00 to match).
   ===================================================================== */
define('DUTY_DAYS',       [1, 2, 3, 4, 5, 6]);   // Mon–Sat
define('DUTY_START',      '07:30');
define('DUTY_END',        '18:00');
define('DUTY_SYNC_GRACE', 120);                  // minutes after DUTY_END, for sync/export only

/* =====================================================================
   DEV ONLY — show the password-reset code on screen.

   With this true, forgot_password.php prints the 6-digit code on the page
   when it cannot email it, so a reset can be completed on a machine with no
   working mailbox. That is the whole point of it, and it is also exactly
   what an account takeover looks like: anyone who can load the page can
   reset anybody's password.

   So it is guarded twice, and BOTH have to hold:
     1. this constant is true, AND
     2. the request is coming from localhost or a private LAN address
        (vts_dev_reveal_ok() in includes/functions.php).

   The second guard is the one that matters — flip this to true and deploy it
   by accident and the live site still refuses, because the live site is not
   localhost. Leave it false unless you are actively testing.
   ===================================================================== */
define('DEV_SHOW_RESET_CODE', false);   // TESTING ONLY — set false before deploying
