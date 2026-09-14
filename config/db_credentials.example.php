<?php
/* Real live-site DB credentials live ONLY here so this one file can be kept
   out of version control (see .gitignore) instead of the password sitting
   in plain text in a file that gets committed/zipped/shared.

   PREFER .env. Because this file is gitignored it is NOT in the GitHub
   checkout, so a deploy that pulls from GitHub will not have it. Putting
   the same four values in a .env file in the web root is the supported
   route and is what config/database.php reads first; this file is only a
   fallback for a local/manual copy. Either way the secret stays off GitHub.

   SECURITY NOTE: the PREVIOUS account's password was hardcoded directly in
   config/database.php and was shared outside the team twice while asking
   for help. That account (if0_42336276 / sql300) is superseded by the one
   below. Treat any password that has ever been pasted into a chat, an
   e-mail or a screenshot as compromised, and rotate it from the
   InfinityFree control panel. */

$LIVE_HOST     = "sql206.infinityfree.com";
$LIVE_USERNAME = "if0_42480644";
$LIVE_PASSWORD = "PUT-YOUR-INFINITYFREE-PASSWORD-HERE"; // never commit a real password
$LIVE_DBNAME   = "if0_42480644_svts";
