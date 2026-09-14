<?php
/* Mail (SMTP) settings. Set MAIL_USERNAME to your Gmail and MAIL_PASSWORD to a Gmail App Password (myaccount.google.com/apppasswords). Needs PHPMailer in vendor/. */

// ---- Toggle: set to false to disable email sending entirely ----------
define('MAIL_ENABLED', true);

// ---- Gmail SMTP credentials — the system's own mailbox ---------------
// STILL TO DO: MAIL_PASSWORD needs a 16-character Gmail App Password for this
// account (myaccount.google.com/apppasswords). Mail cannot send until it's set.
define('MAIL_HOST',       'smtp.gmail.com');
define('MAIL_PORT',       587);
define('MAIL_USERNAME',   'goldenweststudentaffairs@gmail.com');
define('MAIL_PASSWORD',   'your_app_password_here');  // <-- 16-char app password
define('MAIL_FROM_NAME',  'GWC QR Shield');
define('MAIL_FROM_EMAIL', 'goldenweststudentaffairs@gmail.com');

/* =====================================================================
   BREVO HTTP API (needed for InfinityFree — SMTP/port 587 is BLOCKED there)
   ---------------------------------------------------------------------
   InfinityFree's free servers block outgoing SMTP, so Gmail SMTP will NOT
   send from the live site. Brevo (free: 300 emails/day) sends over HTTPS
   (port 443), which is never blocked. On localhost, Gmail SMTP still works.

   SETUP (5 minutes, free):
   1. Sign up at https://www.brevo.com  (free plan).
   2. Verify a sender email (Senders & IPs -> add + verify your email).
   3. Go to SMTP & API -> API Keys -> Generate a new API key ("v3").
   4. Paste the key + your verified sender below.
   The system auto-uses Brevo online and Gmail SMTP on localhost.
   ===================================================================== */
define('BREVO_API_KEY',    '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');
define('BREVO_SENDER_EMAIL','goldenweststudentaffairs@gmail.com');  // must be verified in Brevo
define('BREVO_SENDER_NAME', 'Golden West');

/* Office of Student Affairs inbox — every Guard/Marshal's daily scan report
   is sent here directly (plus every active Admin/OSA/OSA Staff), so it
   arrives even when no admin is on duty. Change to the real OSA address if needed. */
define('OSA_REPORT_EMAIL', 'goldenweststudentaffairs@gmail.com');
define('OSA_REPORT_NAME',  'GWC Office of Student Affairs');

/* CHED (Commission on Higher Education) inbox — where Admin/OSA transfers the
   consolidated violation report. Change to the real CHED address. */
define('CHED_REPORT_EMAIL', 'ched.report@gwc.edu.ph');
define('CHED_REPORT_NAME',  'CHED');
