<?php
/* THE IN-APP USER MANUAL — one manual per role, on that role's own profile
   page (account.php for staff, student/profile.php for students).

   WHY IT IS HERE AND NOT IN A FILE ON SOMEONE'S DESKTOP. USER_MANUAL.md is
   the same material and is the version that gets maintained alongside the
   code, but nobody signed in at 7:30 AM on the gate is going to find a
   markdown file in a repository. The question this answers is "I am looking
   at this screen and I do not know what to press" — so the answer has to be
   one click from the screen, and it has to say the exact words that are
   printed on the buttons.

   RULES FOR EDITING THE CONTENT BELOW
   1. Every step names what to click, in the words the UI actually uses.
      "Violations & Reports -> Official Sheet" beats "navigate to reports".
   2. If the system can refuse something, the refusal is in a table with the
      reason and the fix. A refusal nobody can explain is the thing this
      manual exists to kill.
   3. Anything one role can do and another cannot is marked, not omitted —
      "why is that button not there" is also an unknown.
   4. Keep it in step with USER_MANUAL.md. Both are written for the same
      reader; this one is just the copy they can reach.

   The strings below are author-written constants and may carry simple inline
   markup (<b>, <code>, <a>), so they are printed as-is. NOTHING from the
   database or from $_GET reaches this file — if that ever changes, escape it
   at the point it is added, not here. */

$vtsManualRole = $_SESSION['role'] ?? '';
$vtsManualBase = isset($assetBase) ? $assetBase : '';

/* ---------------------------------------------------------------------
   SECTION SHAPE
     id        anchor + filter key
     icon      Font Awesome name
     title     what the reader is trying to do, in their words
     where     the exact click path, shown as a chip under the title
     intro     one sentence of context (optional)
     steps     the numbered instructions (optional)
     table     ['head' => [...], 'rows' => [[...], ...]] (optional)
     notes     [['type' => 'tip'|'warn'|'info', 'text' => '...'], ...]
     only      'Admin' — drawn with an "Admin only" tag (optional)
   --------------------------------------------------------------------- */

// ── STUDENT ────────────────────────────────────────────────────────────
$vtsManualStudent = [
  [
    'id' => 'stu-register', 'icon' => 'fa-user-plus',
    'title' => 'Getting an account and your QR code',
    'where' => 'Front page → Register',
    'intro' => 'You only do this once. Your QR code is created with the account and never changes.',
    'steps' => [
      'Open the site and choose <b>Register</b>.',
      'Type your <b>School ID</b> exactly as the school issued it.',
      'Fill in your name, course, year, section, email and a password.',
      'Submit. You are signed in straight away.',
      'Check your email — <b>your QR code is attached</b>. Save it to your phone.',
    ],
    'notes' => [
      ['type' => 'warn', 'text' => 'If your School ID is rejected, it is not on the enrolled list yet. Only the Office of Student Affairs can add it — you cannot register until they do.'],
      ['type' => 'tip',  'text' => 'Use an email address you actually read. Sign-in codes and violation notices go there.'],
    ],
  ],
  [
    'id' => 'stu-qr', 'icon' => 'fa-qrcode',
    'title' => 'Showing your QR code at the gate',
    'where' => 'Menu → QR Code',
    'steps' => [
      'Open <b>QR Code</b> in the menu.',
      'Show it from your phone screen, or print it and carry it.',
    ],
    'notes' => [
      ['type' => 'info', 'text' => 'It is always there and it never changes, so losing the email is not a problem — sign in and open the page again.'],
    ],
  ],
  [
    'id' => 'stu-violations', 'icon' => 'fa-triangle-exclamation',
    'title' => 'Checking your violations',
    'where' => 'Menu → Violations',
    'steps' => [
      'Open <b>Violations</b>. Each row is one record.',
      'Read the number on the row — <b>Violation #1</b>, <b>#2</b> and so on — that is where it sits on your record.',
      'Click a row to open it in full, with the reason and any note from the office.',
    ],
    'notes' => [
      ['type' => 'info', 'text' => 'You will never be shown which marshal recorded it. That is deliberate — students are not shown the name of the person on the gate.'],
    ],
  ],
  [
    'id' => 'stu-notif', 'icon' => 'fa-bell',
    'title' => 'Notifications',
    'where' => 'The bell at the top right',
    'steps' => [
      'A number on the bell is how many you have not read.',
      'Click the bell to read them without leaving the page.',
    ],
    'notes' => [
      ['type' => 'info', 'text' => 'A new violation on your record, and anything the office decides about one, both arrive here.'],
    ],
  ],
  [
    'id' => 'stu-profile', 'icon' => 'fa-user-gear',
    'title' => 'Your details and your password',
    'where' => 'Menu → Profile (this page)',
    'steps' => [
      'Correct your name, course, year, section or email in the top form, then <b>Save</b>.',
      'Change your password in the second form. You need your current password first.',
      'Both forms report their own result — a password rule cannot block a change of course.',
    ],
    'notes' => [
      ['type' => 'warn', 'text' => 'Keep your email current. It is where sign-in codes and notices are sent, and nobody can recover an account pointed at an address you no longer read.'],
    ],
  ],
];

// ── GUARD / MARSHAL ────────────────────────────────────────────────────
$vtsManualGuard = [
  [
    'id' => 'g-setup', 'icon' => 'fa-mobile-screen',
    'title' => 'Setting the phone up — first time only',
    'where' => 'Open the scanner → the setup screen',
    'intro' => 'Two ways in. Pick by whether there is Wi-Fi at the gate.',
    'steps' => [
      '<b>No Wi-Fi — Import file.</b> Get the student list from the Head Marshal on a USB (a <code>.vtsl</code> or <code>Students.csv</code> file), tap <b>Import file</b>, choose it. Done — no internet, no login.',
      '<b>Wi-Fi — Connect online.</b> Tap <b>Connect online</b>, type the laptop\'s address (for example <code>192.168.1.5</code>, or <code>192.168.1.5/SAD</code> if the site is in a folder), then connect. The list downloads and your scans sync as you make them.',
    ],
  ],
  [
    'id' => 'g-duty', 'icon' => 'fa-user-shield',
    'title' => 'Going on duty',
    'where' => 'Scanner → "Who is on duty?"',
    'intro' => 'Every shift starts here. There are only two slots.',
    'steps' => [
      'Type your <b>School ID</b>.',
      'Check the name that comes up is yours, and confirm.',
    ],
    'table' => [
      'head' => ['If you are refused', 'Why', 'What to do'],
      'rows' => [
        ['"Two marshals are already on duty"', 'Both slots are taken', 'Ask the office to sign one off, or wait for a shift to end'],
        ['"The gate is scanned Mon–Sat, 7:30 AM – 6:00 PM"', 'Outside duty hours', 'Come back when it says it opens'],
        ['"Scanning is currently turned off by the office"', 'The office paused scanning', 'Ask the office'],
        ['"That School ID does not match an active student or guard account"', 'Wrong ID, or the account is inactive', 'Check the ID; ask the office if it is right'],
      ],
    ],
  ],
  [
    'id' => 'g-kicked', 'icon' => 'fa-power-off',
    'title' => 'The phone threw me back to the sign-in screen mid-shift',
    'where' => 'It happens by itself — read the red message on the duty screen',
    'intro' => 'The phone checks with the server every 20 seconds. If your shift has ended it stops and comes back here, and the message says which of these it was.',
    'table' => [
      'head' => ['The message says', 'What happened', 'What to do'],
      'rows' => [
        ['Scanning has been switched off by the office', 'An Admin turned the whole scanner feature off. Every marshal was signed off, not just you', 'Nothing is wrong with your phone. Ask the office; sign on again once it is back on'],
        ['The office signed you off', 'Someone ended your shift to free your slot', 'Ask the office before signing back on — they may have given the slot to someone else'],
        ['This account was opened on another scanner', 'Your School ID was used to sign on to a different phone', 'Only one phone per account. Work out which phone should have it'],
      ],
    ],
    'notes' => [
      ['type' => 'tip', 'text' => '<b>Your scans are not lost.</b> Anything already on the phone stays on the phone and still goes out in the end-of-shift <code>.vtsl</code> file. Do not tap <b>Clear</b>.'],
      ['type' => 'warn', 'text' => 'Queued scans will <b>not</b> sync while you are signed off — syncing needs a live shift. Finish the session and hand the file to the Head Marshal as normal.'],
    ],
  ],
  [
    'id' => 'g-record', 'icon' => 'fa-qrcode',
    'title' => 'Recording a violation',
    'where' => 'Scanner → Scan',
    'steps' => [
      'Tap <b>Scan</b> and point the camera at the student\'s QR code.',
      'The student\'s name comes up. <b>Check it is the right person</b> before going on.',
      'Pick the violation from the list. Choose <b>Others</b> if it is not listed, and type what happened.',
      '<b>Hold the phone steady</b> — the camera takes a proof photo by itself after a short pause. Frame what you are recording.',
      'The scan is saved.',
    ],
  ],
  [
    'id' => 'g-badges', 'icon' => 'fa-signal',
    'title' => 'Reading the badges — did my scan go through?',
    'where' => 'On each record, and at the top right of the screen',
    'table' => [
      'head' => ['Badge', 'What it means'],
      'rows' => [
        ['synced', 'On the server. Done.'],
        ['queued', 'On the phone, waiting. It will sync on its own.'],
        ['held', 'The server would not take this one — it still goes out in the end-of-shift export.'],
        ['Connected (green)', 'Scans are syncing as you make them.'],
        ['No server (amber)', 'You have Wi-Fi but the system is not reachable. Your scans are safe.'],
        ['Offline (grey)', 'No network at all. Your scans are safe.'],
      ],
    ],
    'notes' => [
      ['type' => 'tip', 'text' => '<b>Nothing is ever lost.</b> A queued scan is on the phone and is in the end-of-shift file. Amber and grey are not errors — keep scanning.'],
    ],
  ],
  [
    'id' => 'g-handover', 'icon' => 'fa-right-left',
    'title' => 'Handing the phone to the next marshal',
    'where' => 'Scanner → Switch marshal',
    'steps' => [
      'Tap <b>Switch marshal</b>.',
      'Anything still waiting is sent first, so it is filed under <b>you</b> and not the next person.',
      'Your duty slot frees at once.',
    ],
  ],
  [
    'id' => 'g-finish', 'icon' => 'fa-flag-checkered',
    'title' => 'Ending your shift',
    'where' => 'Scanner → Finish session',
    'steps' => [
      'Tap <b>Finish session</b>.',
      'It writes an encrypted <code>.vtsl</code> file of everything you scanned.',
      'Read what it reports: how many records, and how many carry a proof photo.',
      'Your duty slot frees.',
      '<b>Give the USB to the Head Marshal</b>, who imports it into the system.',
    ],
    'notes' => [
      ['type' => 'warn', 'text' => 'Keep the records on the phone until the office confirms the import. Do <b>not</b> tap <b>Clear</b> before then.'],
    ],
  ],
  [
    'id' => 'g-noconnect', 'icon' => 'fa-plug-circle-xmark',
    'title' => 'The scanner will not connect',
    'where' => 'Work down this list in order',
    'intro' => 'These are ordered by how often each one turns out to be the cause.',
    'steps' => [
      '<b>Same Wi-Fi.</b> The phone and the laptop must be on one network. Mobile data will not reach it.',
      '<b>Current IP.</b> Run <code>ipconfig</code> on the laptop and use the IPv4 address. <b>It changes whenever the laptop rejoins Wi-Fi</b> — a stale address is the most common cause by far.',
      '<b>Include the folder.</b> If the site is not at the server root, add it: <code>192.168.1.5/SAD</code>.',
      '<b>Apache running</b> in the XAMPP control panel.',
      '<b>Windows Firewall</b> must allow Apache on private networks, or the phone\'s request is dropped with no error at all.',
    ],
    'notes' => [
      ['type' => 'tip', 'text' => 'Cannot sort it now? Use <b>Import file</b> and work offline. The shift is not blocked by this.'],
    ],
  ],
  [
    'id' => 'g-profile', 'icon' => 'fa-user-gear',
    'title' => 'Your details and your password',
    'where' => 'Menu → My Profile (this page)',
    'steps' => [
      'Correct your name, email, contact number or photo, then <b>Save Changes</b>.',
      'To change your password, type a new one in <b>New Password</b> and save. Leave it blank to keep the one you have.',
    ],
  ],
];

// ── OSA STAFF ──────────────────────────────────────────────────────────
$vtsManualStaff = [
  [
    'id' => 'st-menu', 'icon' => 'fa-bars',
    'title' => 'What is on your menu',
    'where' => 'The blue rail on the left',
    'table' => [
      'head' => ['Item', 'What it is for'],
      'rows' => [
        ['Dashboard', 'The overview — what has come in and what needs attention'],
        ['Students', 'Look a student up, add one, export the list'],
        ['Violations &amp; Reports', 'Both the official sheet and the per-record working list'],
        ['My Profile', 'Your own details and password (this page)'],
      ],
    ],
    'notes' => [
      ['type' => 'info', 'text' => 'Users, Backup, Audit Log and Settings are <b>not</b> on your menu. Those are OSA and Admin. Nothing is broken if you cannot see them.'],
    ],
  ],
  [
    'id' => 'st-find', 'icon' => 'fa-magnifying-glass',
    'title' => 'Looking a student up',
    'where' => 'Students → search box',
    'steps' => [
      'Open <b>Students</b>.',
      'Type a name, School ID or course into the search box and press <b>Search</b>.',
      'Click the <b>eye</b> icon on the row to open that student\'s full record, including every violation.',
    ],
  ],
  [
    'id' => 'st-add-violation', 'icon' => 'fa-plus',
    'title' => 'Recording a violation from the office',
    'where' => 'Violations &amp; Reports → Add Violation',
    'intro' => 'Use this when something is reported in the office rather than caught at the gate.',
    'steps' => [
      'Open <b>Violations &amp; Reports</b>, then <b>Add Violation</b>.',
      'Find the student.',
      'Pick the violation type and severity.',
      'Attach evidence if you have it, and write a description.',
      '<b>Save</b>.',
    ],
    'notes' => [
      ['type' => 'warn', 'text' => 'If the system refuses a <b>Major</b> violation, that student already has an unresolved Major one. Only one at a time is allowed — the first has to be resolved.'],
    ],
  ],
  [
    'id' => 'st-views', 'icon' => 'fa-table-list',
    'title' => 'Official Sheet vs Records &amp; Actions',
    'where' => 'Violations &amp; Reports → the two tabs above the table',
    'intro' => 'Same data, two jobs. Knowing which tab you are on is most of using this page.',
    'table' => [
      'head' => ['Tab', 'What it is', 'Use it to'],
      'rows' => [
        ['Official Sheet', 'One row per student, in the layout the office submits', 'Print or export the report'],
        ['Records &amp; Actions', 'One row per violation, with buttons', 'Edit, print or delete a single record'],
      ],
    ],
    'notes' => [
      ['type' => 'info', 'text' => 'On the Official Sheet the <b>warning, slip and remarks columns are left blank on purpose</b> — they are filled in by hand after printing.'],
      ['type' => 'info', 'text' => 'You will not see which marshal recorded each violation. That is shown only to OSA and Admin.'],
    ],
  ],
  [
    'id' => 'st-filter', 'icon' => 'fa-filter',
    'title' => 'Narrowing the list down',
    'where' => 'Violations &amp; Reports → search box, department picker, Filters panel',
    'steps' => [
      'Type into the search box for a name or School ID.',
      'Use the department picker to scope to one department.',
      'Open <b>Filters</b> for date range, year level, course, section and violation type.',
      'If the table comes back empty, the page names the filters that emptied it and offers the way back — use that rather than reloading.',
    ],
  ],
  [
    'id' => 'st-print', 'icon' => 'fa-print',
    'title' => 'Printing the paperwork',
    'where' => 'Violations &amp; Reports → Records &amp; Actions → open a record',
    'table' => [
      'head' => ['Document', 'When you use it'],
      'rows' => [
        ['ID Confiscation Slip', 'The student\'s ID was taken at the gate'],
        ['Student Violation Slip', 'The formal record of the violation'],
        ['Undertaking', 'The student signs a promise after a violation'],
        ['Parent Notice', 'The parent or guardian must be informed'],
        ['Violators List', 'A printable list matching the on-screen table'],
      ],
    ],
  ],
  [
    'id' => 'st-export', 'icon' => 'fa-file-excel',
    'title' => 'Exporting',
    'where' => 'The Export button above the table',
    'steps' => [
      'Filter the list to exactly what you want first — the export is <b>what is on screen</b>, not everything.',
      'Press <b>Export</b> and choose the Excel copy.',
      'The same menu offers a scanner file for provisioning a marshal\'s phone.',
    ],
  ],
  [
    'id' => 'st-profile', 'icon' => 'fa-user-gear',
    'title' => 'Your details and your password',
    'where' => 'Menu → My Profile (this page)',
    'steps' => [
      'Correct your name, email, contact number or photo, then <b>Save Changes</b>.',
      'To change your password, type a new one in <b>New Password</b> and save. Leave it blank to keep the one you have.',
    ],
  ],
];

// ── OSA + ADMIN ────────────────────────────────────────────────────────
/* One list. Entries only Admin can reach carry 'only' => 'Admin'; an OSA
   reader still sees them, marked, because "why is that not there for me" is
   exactly the kind of unknown this manual is for. */
$vtsManualAdmin = [
  [
    'id' => 'ad-menu', 'icon' => 'fa-bars',
    'title' => 'What is on your menu',
    'where' => 'The blue rail on the left',
    'table' => [
      'head' => ['Item', 'What it is for'],
      'rows' => [
        ['Dashboard', 'Recent violations and the charts. <span class="man-only">Admin only</span> also the Recent Activity panel'],
        ['Students', 'Registered accounts and the enrolled roster that controls who may register'],
        ['Violations &amp; Reports', 'The official sheet, the per-record list, and a shortcut per department'],
        ['Violation Proof', 'The photo attached to each violation, full size'],
        ['Violation Types <span class="man-only">Admin only</span>', 'What may be recorded at all, and how serious it is'],
        ['Users', 'Staff accounts and their roles'],
        ['Statistics', 'The charts in full, with filters'],
        ['Backup', 'Full database dump, and restore'],
        ['Audit Log <span class="man-only">Admin only</span>', 'Every significant action: who, what, when'],
        ['Settings <span class="man-only">Admin only</span>', 'School details, Drive folders, and the <b>Scanner &amp; duty</b> card'],
      ],
    ],
    'notes' => [
      ['type' => 'info', 'text' => 'The three rows marked <b>Admin only</b> are the system being configured or audited rather than operated — what may be recorded, the record of who did what, and the setup of the gate. If you are OSA they are not on your menu, and that is a permission, not a fault.'],
    ],
  ],
  [
    'id' => 'ad-dashboard', 'icon' => 'fa-house',
    'title' => 'Reading the dashboard',
    'where' => 'Menu → Dashboard',
    'intro' => 'It is ordered by what needs you first. Read it top down.',
    'steps' => [
      '<b>Recent Violations</b> — what has come in from the gate.',
      'The charts: violations over time, by type, by department, by year level, by section, top violations, and the top repeat offenders.',
      '<b>Recent Activity</b> — the last seven things anyone did. <span class="man-only">Admin only</span>',
    ],
    'notes' => [
      ['type' => 'info', 'text' => 'Recent Activity is a window onto the audit log, so it follows the same rule: Admin reads it, nobody else. If you are OSA your dashboard ends at the charts — nothing is missing or broken.'],
    ],
  ],
  [
    'id' => 'ad-duty', 'icon' => 'fa-satellite-dish',
    'title' => 'Managing the gate — Scanner &amp; duty',
    'where' => 'Menu → Settings → the Scanner &amp; duty card at the top',
    'only' => 'Admin',
    'intro' => 'The master switch, the hours, and who is holding a slot. It refreshes itself every 20 seconds.',
    'steps' => [
      '<b>To see who is out there:</b> read the two marshal slots. An empty one shows as <i>Slot 2 — free</i>.',
      '<b>To pause scanning entirely:</b> flip <b>Scanning</b> to Off. <b>Anyone scanning right now is signed off immediately</b> and their phone drops back to its sign-in screen within 20 seconds — it does not wait for their shift to end. Nobody can go on duty again until it is back on.',
      '<b>To change the days or hours:</b> tick the days, set the opening and closing times, then <b>Save duty hours</b>.',
      '<b>To sign a marshal off:</b> click the exit icon on their row. Their slot frees immediately and they are notified.',
    ],
    'notes' => [
      ['type' => 'info', 'text' => 'The system refuses a schedule with no days, or one that closes at or before it opens — either would shut the gate permanently.'],
      ['type' => 'warn', 'text' => '<b>Switching Scanning off ends every live shift.</b> Each marshal is told why, the roster empties, and the audit entry names who was signed off. Scans still sitting on their phones are safe — but they will <b>not</b> sync while scanning is off; they stay on the device and go out in the end-of-shift file. If you only want to stop new shifts starting, change the duty hours instead.'],
      ['type' => 'tip',  'text' => 'Signing someone off is what to do when a marshal walked away with a live session and is blocking the next one. Scans already on their phone are not affected.'],
      ['type' => 'warn', 'text' => '<b>If you are OSA, this card is not yours.</b> Setting the gate up — switching scanning on and off, posting the hours, ending a shift — is Admin\'s. You still work the gate; you do not configure it. A marshal stuck outside needs an Admin.'],
    ],
  ],
  [
    'id' => 'ad-proof', 'icon' => 'fa-camera',
    'title' => 'Checking proof photos',
    'where' => 'Menu → Violation Proof',
    'steps' => [
      'Open <b>Violation Proof</b>.',
      'Check the photo actually shows what the record claims — a reason and a picture that disagree are not evidence of anything.',
      'Press <b>Approve</b> or <b>Reject</b> on the row. That is the whole judgement — there is no note to write.',
    ],
    'table' => [
      'head' => ['Button', 'What happens'],
      'rows' => [
        ['Approve', 'The photo supports the record. Nothing changes — it counted already'],
        ['Reject', 'The photo does not support it. The row stays in history but stops counting'],
        ['Delete', 'The record and its photo are erased. Nothing is left'],
      ],
    ],
    'notes' => [
      ['type' => 'info', 'text' => 'A record with no photo is listed as <i>No proof attached</i> rather than hidden, so a missing photo is visible instead of silent.'],
      ['type' => 'info', 'text' => 'Nothing you type on this page reaches the student. <b>Approve</b> and <b>Reject</b> are the decision; <b>Clear</b> undoes it and the record counts as normal again.'],
      ['type' => 'warn', 'text' => '<b>Delete cannot be undone.</b> Use <b>Reject</b> when a record should stop counting but stay on file. Delete only when it should never have existed.'],
      ['type' => 'warn', 'text' => 'A <b>Major</b> cannot be rejected or deleted until you have recorded the conference with the student. <b>Admin</b> may delete one without it — Admin holds full control of the records, and the audit log says so when it happens. <b>OSA</b> cannot.'],
    ],
  ],
  [
    'id' => 'ad-students', 'icon' => 'fa-user-graduate',
    'title' => 'Managing students and the enrolled roster',
    'where' => 'Menu → Students',
    'intro' => 'Two tabs, and they do different jobs. The second one decides who is allowed to register at all.',
    'steps' => [
      '<b>Registered</b> — students who have accounts. Search and view. <span class="man-only">Admin only</span> edit and delete.',
      '<b>Enrolled Students</b> — the official roster. <b>Import</b> a list at the start of a term (<code>.xlsx</code>, CSV, or pasted rows).',
      '<b>Unregister</b> an ID to free it for use again.',
      '<b>Clear all</b> empties the roster — and while it is empty, <b>registration validation is off</b> and anyone can register.',
    ],
    'notes' => [
      ['type' => 'warn', 'text' => 'A student who cannot register is almost always missing from <b>Enrolled Students</b>. Import the list, or add that one ID.'],
      ['type' => 'tip',  'text' => 'Take a backup before importing a roster or rolling over a term.'],
    ],
  ],
  [
    'id' => 'ad-types', 'icon' => 'fa-list-check',
    'title' => 'Changing what can be recorded',
    'where' => 'Menu → Violation Types',
    'only' => 'Admin',
    'intro' => 'What is on the list, and how serious each one is, is a policy decision — so OSA record against the list but do not set it.',
    'steps' => [
      'Open <b>Violation Types</b>.',
      'Add a type, or change its severity.',
      'Mark the ones that should raise a critical alert.',
    ],
    'notes' => [
      ['type' => 'info', 'text' => 'Need a type that is not on the list? Record it as <b>Others</b> with a description, and ask an Admin to add it properly.'],
    ],
  ],
  [
    'id' => 'ad-users', 'icon' => 'fa-users',
    'title' => 'Staff accounts',
    'where' => 'Menu → Users',
    'steps' => [
      'Add, edit, deactivate or delete a staff account, and set its role.',
      'Deactivating is the softer option — the account stays but cannot sign in or go on duty.',
    ],
    'notes' => [
      ['type' => 'warn', 'text' => '<b>OSA cannot edit or delete an Admin account.</b> Those rows are locked, and that is why the buttons are missing rather than broken.'],
      ['type' => 'info', 'text' => 'Opening an account shows its role, its counts and its last sign-in to everyone. The <b>Recent activity</b> table underneath it — the trail of what its owner did — is <b>Admin only</b>.'],
    ],
  ],
  [
    'id' => 'ad-report', 'icon' => 'fa-paper-plane',
    'title' => 'Sending the report to CHED',
    'where' => 'Violations &amp; Reports',
    'steps' => [
      'Filter the list to exactly what the report should cover.',
      'Choose the CHED transfer action.',
      'It sends the consolidated report to the address set in the mail settings.',
    ],
  ],
  [
    'id' => 'ad-stats', 'icon' => 'fa-chart-pie',
    'title' => 'Statistics',
    'where' => 'Menu → Statistics',
    'steps' => [
      'The dashboard charts in full, with filters — over time, by type, by department, by year level, by section, and the repeat offenders.',
    ],
  ],
  [
    'id' => 'ad-audit', 'icon' => 'fa-clipboard-list',
    'title' => 'The audit log',
    'where' => 'Menu → Audit Log',
    'only' => 'Admin',
    'intro' => 'Every significant action: who did it, to what, when, and from which IP.',
    'steps' => [
      'Search by name to see one account\'s whole history. The <b>Users</b> page links straight in with the name already filled.',
      'Delete a single entry, or clear the log.',
    ],
    'notes' => [
      ['type' => 'info', 'text' => '<b>A purge is itself logged.</b> After any deletion there is still a line naming who did it, when, and from which IP — a log that can be silently emptied is not an audit log.'],
      ['type' => 'warn', 'text' => '<b>Admin only, to read as well as to clear.</b> The log reports on everyone with an account, OSA included, so the people it records are not the people who read it. That covers every window onto it: the <b>Recent Activity</b> panel on the dashboard and the one on a user\'s record are Admin\'s too.'],
      ['type' => 'tip',  'text' => 'If you suspect sign-in codes are not going out, look here — a mail failure is written to the log rather than blocking the sign-in.'],
    ],
  ],
  [
    'id' => 'ad-backup', 'icon' => 'fa-database',
    'title' => 'Backups and restore',
    'where' => 'Menu → Backup',
    'steps' => [
      'Press the backup action to produce a full <code>.sql</code> dump of every table.',
      'To restore, upload a dump and confirm.',
    ],
    'notes' => [
      ['type' => 'warn', 'text' => '<b>Restoring replaces live data.</b> Take a backup before importing a roster, before a term rollover, and before any bulk change.'],
    ],
  ],
  [
    'id' => 'ad-settings', 'icon' => 'fa-gear',
    'title' => 'Settings',
    'where' => 'Menu → Settings',
    'only' => 'Admin',
    'steps' => [
      '<b>Institution</b> — school name, address, email, contact. These head every printed slip, notice and export.',
      '<b>Current term</b> — academic year and semester. New violation records are stamped with what is set here.',
      '<b>Google Drive folders</b> — where each exported file gets filed. The Export buttons open these in a new tab.',
      '<b>Scanner &amp; duty</b> — the card at the top of the page; see <i>Managing the gate</i> above.',
      'Press <b>Save Settings</b>.',
    ],
    'notes' => [
      ['type' => 'warn', 'text' => '<b>Admin only.</b> Everything on this page configures the system rather than operating it, the gate setup included. If you are OSA it is not on your menu.'],
    ],
  ],
  [
    'id' => 'ad-trouble', 'icon' => 'fa-circle-question',
    'title' => 'When something goes wrong',
    'where' => 'The five questions worth checking first',
    'table' => [
      'head' => ['What you are told', 'What it actually is', 'Fix'],
      'rows' => [
        ['A marshal cannot go on duty', 'Outside hours, scanning off, both slots taken, or the account is inactive', 'Settings → Scanner &amp; duty answers the first three <span class="man-only">Admin only</span>; the fourth is on Users'],
        ['A student cannot register', 'Their School ID is not on the Enrolled Students roster', 'Import the list, or add that ID'],
        ['A violation was refused as Major', 'That student already has an unresolved Major one', 'Resolve the first; only one at a time is allowed'],
        ['Email is not arriving', 'A mail setup problem, not a system fault', 'Admin → Mail Test names the failing part and sends a live test'],
        ['Someone left a session open', 'Their slot is still held', 'Settings → Scanner &amp; duty → the exit icon on their row <span class="man-only">Admin only</span>'],
      ],
    ],
    'notes' => [
      ['type' => 'info', 'text' => 'Mail never blocks the system. If a code cannot be sent, the sign-in is allowed through and the gap is written to the <b>Audit Log</b>.'],
      ['type' => 'warn', 'text' => 'The rows tagged <b>Admin only</b> are fixes an OSA account cannot apply. Bring the exact message to an Admin — it is what tells them which of the causes it is.'],
    ],
  ],
  [
    'id' => 'ad-profile', 'icon' => 'fa-user-gear',
    'title' => 'Your details and your password',
    'where' => 'Menu → My Profile (this page)',
    'steps' => [
      'Correct your name, email, contact number or photo, then <b>Save Changes</b>.',
      'To change your password, type a new one in <b>New Password</b> and save. Leave it blank to keep the one you have.',
    ],
  ],
];

/* Signing in is the same story for every role, so it is written once and
   appended last — it is the section people need when they are locked out,
   which is the moment they are least able to go looking for it. */
$vtsManualSignIn = [
  'id' => 'all-signin', 'icon' => 'fa-right-to-bracket',
  'title' => 'Signing in, codes, and forgotten passwords',
  'where' => 'Applies to everyone',
  'steps' => [
    $vtsManualRole === 'Student'
      ? 'Students sign in from the front page.'
      : 'Staff reach their own sign-in through a hidden door — there is no visible "Staff login" link. On a desktop press <b>Ctrl + Shift + S</b>; on a phone or tablet <b>tap the seal five times, or press and hold it</b>, then type the access key.',
    'The system may email you a <b>6-digit code</b>. The email says which of the two reasons it is.',
    '<b>New device code</b> — this browser has not been seen on your account before. Everyone gets these, students included. Whoever is signed in elsewhere is <b>not</b> logged out; this only decides whether the new device may join. Once confirmed, that browser stops asking.',
    '<b>Staff sign-in code</b> — a code on every staff sign-in. This is currently <b>switched off</b>.',
    'A code expires in 10 minutes. <b>Send a new code</b> issues a fresh one.',
    'Forgotten your password? <b>Forgot password</b> on the sign-in card emails a reset code.',
  ],
  'notes' => [
    ['type' => 'warn', 'text' => '<b>If a code arrives that you did not ask for, do not enter it.</b> It means someone else has your password or School ID. Change your password and tell the Office of Student Affairs.'],
  ],
];

switch ($vtsManualRole) {
    case 'Student':   $vtsManualSections = $vtsManualStudent; $vtsManualFor = 'Student'; break;
    case 'Guard':     $vtsManualSections = $vtsManualGuard;   $vtsManualFor = 'Guard / Marshal'; break;
    case 'OSA Staff': $vtsManualSections = $vtsManualStaff;   $vtsManualFor = 'OSA Staff'; break;
    case 'OSA':       $vtsManualSections = $vtsManualAdmin;   $vtsManualFor = 'OSA'; break;
    case 'Admin':     $vtsManualSections = $vtsManualAdmin;   $vtsManualFor = 'Admin'; break;
    default:          $vtsManualSections = [];                $vtsManualFor = $vtsManualRole; break;
}
$vtsManualSections[] = $vtsManualSignIn;
?>
<link rel="stylesheet" href="<?php echo $vtsManualBase; ?>assets/css/user-manual.css?v=<?php echo @filemtime(__DIR__ . '/../assets/css/user-manual.css'); ?>">

<section class="man-card" id="userManual">
  <div class="man-head">
    <div class="man-head-text">
      <h2><i class="fas fa-book-open" aria-hidden="true"></i> How to use the system</h2>
      <p>Written for <b><?php echo htmlspecialchars($vtsManualFor); ?></b> — only what your role can actually do. Every step names the button by the words printed on it.</p>
    </div>
    <div class="man-head-actions">
      <?php /* Two buttons, and only two. "Open all" is what you press before
               printing or before reading it through the first time; the filter
               is what you use when you already know the word you are after. */ ?>
      <button type="button" class="btn-outline man-btn" id="manToggleAll" data-state="closed">
        <i class="fas fa-angles-down" aria-hidden="true"></i> Open all
      </button>
      <button type="button" class="btn-outline man-btn" id="manPrint">
        <i class="fas fa-print" aria-hidden="true"></i> Print
      </button>
    </div>
  </div>

  <div class="man-search">
    <i class="fas fa-magnifying-glass" aria-hidden="true"></i>
    <input type="search" id="manFilter" class="vts-input"
           placeholder="Search this manual — try &ldquo;password&rdquo;, &ldquo;proof&rdquo;, &ldquo;on duty&rdquo;&hellip;"
           aria-label="Search this manual" autocomplete="off">
    <span class="man-search-count" id="manCount" hidden></span>
  </div>

  <div class="man-sections" id="manSections">
    <?php if (!$vtsManualSections): ?>
      <p class="man-empty">No manual is written for this role yet.</p>
    <?php endif; ?>

    <?php foreach ($vtsManualSections as $i => $sec): ?>
      <details class="man-sec" id="man-<?php echo htmlspecialchars($sec['id']); ?>"<?php echo $i === 0 ? ' open' : ''; ?>>
        <summary class="man-sum">
          <i class="fas <?php echo htmlspecialchars($sec['icon']); ?> man-sum-icon" aria-hidden="true"></i>
          <span class="man-sum-title">
            <?php echo $sec['title']; ?>
            <?php if (!empty($sec['only'])): ?><span class="man-only"><?php echo htmlspecialchars($sec['only']); ?> only</span><?php endif; ?>
          </span>
          <i class="fas fa-chevron-down man-caret" aria-hidden="true"></i>
        </summary>

        <div class="man-body">
          <?php if (!empty($sec['where'])): ?>
            <p class="man-where"><i class="fas fa-location-arrow" aria-hidden="true"></i> <?php echo $sec['where']; ?></p>
          <?php endif; ?>

          <?php if (!empty($sec['intro'])): ?>
            <p class="man-intro"><?php echo $sec['intro']; ?></p>
          <?php endif; ?>

          <?php if (!empty($sec['steps'])): ?>
            <ol class="man-steps">
              <?php foreach ($sec['steps'] as $step): ?><li><?php echo $step; ?></li><?php endforeach; ?>
            </ol>
          <?php endif; ?>

          <?php if (!empty($sec['table'])): ?>
            <div class="man-table-wrap">
              <table class="man-table">
                <thead><tr><?php foreach ($sec['table']['head'] as $h): ?><th><?php echo $h; ?></th><?php endforeach; ?></tr></thead>
                <tbody>
                  <?php foreach ($sec['table']['rows'] as $row): ?>
                    <tr><?php foreach ($row as $cell): ?><td><?php echo $cell; ?></td><?php endforeach; ?></tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>

          <?php if (!empty($sec['notes'])): foreach ($sec['notes'] as $n): ?>
            <p class="man-note is-<?php echo htmlspecialchars($n['type']); ?>">
              <i class="fas <?php
                   echo $n['type'] === 'warn' ? 'fa-triangle-exclamation'
                      : ($n['type'] === 'tip' ? 'fa-lightbulb' : 'fa-circle-info'); ?>" aria-hidden="true"></i>
              <span><?php echo $n['text']; ?></span>
            </p>
          <?php endforeach; endif; ?>
        </div>
      </details>
    <?php endforeach; ?>
  </div>

  <p class="man-foot">
    Still stuck? Bring the exact words on screen to the Office of Student Affairs —
    the message is what tells them which of the causes above it is.
  </p>
</section>

<script>
/* Filter, open-all and print. No dependencies: this sits on the profile page,
   which loads no page-specific JS of its own. */
(function () {
  var wrap   = document.getElementById('manSections');
  var filter = document.getElementById('manFilter');
  var count  = document.getElementById('manCount');
  var toggle = document.getElementById('manToggleAll');
  var print  = document.getElementById('manPrint');
  if (!wrap) return;

  var secs = Array.prototype.slice.call(wrap.querySelectorAll('.man-sec'));

  /* Searching the rendered text, not a duplicate index — the manual cannot
     then drift out of step with its own search. Read once, on load. */
  secs.forEach(function (s) { s._text = (s.textContent || '').toLowerCase(); });

  function apply() {
    var q = (filter.value || '').trim().toLowerCase();
    if (!q) {
      secs.forEach(function (s) { s.hidden = false; });
      count.hidden = true;
      return;
    }
    var hits = 0;
    secs.forEach(function (s) {
      var hit = s._text.indexOf(q) !== -1;
      s.hidden = !hit;
      /* A match buried inside a shut section is a match nobody can see. */
      if (hit) { s.open = true; hits++; }
    });
    count.hidden = false;
    count.textContent = hits === 0 ? 'Nothing matches that'
                      : hits + (hits === 1 ? ' section' : ' sections');
  }

  if (filter) filter.addEventListener('input', apply);

  if (toggle) toggle.addEventListener('click', function () {
    var opening = toggle.getAttribute('data-state') === 'closed';
    secs.forEach(function (s) { if (!s.hidden) s.open = opening; });
    toggle.setAttribute('data-state', opening ? 'open' : 'closed');
    toggle.innerHTML = opening
      ? '<i class="fas fa-angles-up" aria-hidden="true"></i> Close all'
      : '<i class="fas fa-angles-down" aria-hidden="true"></i> Open all';
  });

  /* Print opens everything first — a printed manual with half its sections
     collapsed is a printed manual with half its sections missing. */
  if (print) print.addEventListener('click', function () {
    secs.forEach(function (s) { s.hidden = false; s.open = true; });
    window.print();
  });
})();
</script>
