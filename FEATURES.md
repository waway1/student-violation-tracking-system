# QR Shield — Features

**Every feature, where it lives, and how it works.**

This is the reference map of the system. For who does what see
**USER_FLOW.md**; for how to operate it see **USER_MANUAL.md**.

Paths are relative to the project root. Pages under `admin/` are shared by
**Admin and OSA** unless marked *Admin only*.

---

## Contents

1. [Accounts and sign-in](#1-accounts-and-sign-in)
2. [The QR code](#2-the-qr-code)
3. [The gate scanner](#3-the-gate-scanner)
4. [On-duty control](#4-on-duty-control)
5. [Recording violations](#5-recording-violations)
6. [Viewing and filtering violations](#6-viewing-and-filtering-violations)
7. [Proof photos](#7-proof-photos)
8. [Students and the enrolled roster](#8-students-and-the-enrolled-roster)
9. [Staff accounts](#9-staff-accounts)
10. [Reports and printables](#10-reports-and-printables)
11. [Exports](#11-exports)
12. [Notifications](#12-notifications)
13. [Statistics](#13-statistics)
14. [Audit log](#14-audit-log)
15. [Backup and restore](#15-backup-and-restore)
16. [Settings](#16-settings)
17. [Email](#17-email)
18. [Working offline](#18-working-offline)
19. [Security](#19-security)

---

## 1. Accounts and sign-in

**Where:** `index.php`, `student_search.php`, `register.php`, `verify.php`,
`login_otp.php`, `auth/device_verify.php`, `forgot_password.php`, `account.php`

| Feature | How it works |
|---|---|
| **Student registration** | `register.php` → `auth/register_process.php`. The School ID is checked against `student_roster`; an ID not on that list cannot register. Creates the account, generates the QR code, emails it, signs the student in. |
| **Student sign-in** | Two tests, and the **server** picks which from the account: School ID + password if the account has one, School ID + surname if it does not. Set by `STUDENT_PASSWORD_REQUIRED` in `config/app.php`. |
| **Live name search** | `auth/student_lookup_search.php`. Type-ahead on the sign-in box. Deliberately **never returns a School ID** — it matches on one but only ever answers with names, and an ID prefix only matches accounts that already have a password. |
| **Hidden staff door** | `assets/js/staff-key.js`. The staff sign-in card is hidden until an access key is typed (Ctrl+Shift+S on desktop; five taps or a long press on the seal for touchscreens, which opens a password field so mobile keyboards do not autocorrect the key). A doorway, not a lock — every role still signs in with a real password. |
| **Staff two-step code** | **Currently switched OFF** (`STAFF_OTP_ENABLED` is `false` in `config/app.php`). When switched on it applies to every non-Student role: a 6-digit emailed code on **every** sign-in, with `login_otp.php` as step 2 and nothing counting as signed in until the code is accepted. `vts_issue_login_otp()` stores only the hash. |
| **New-device code** | `vts_complete_login()` → `auth/device_verify.php`. A browser the account has not used is held at a 6-digit code. The session already open elsewhere is untouched. A long-lived `httponly` cookie marks the recognised browser. |
| **Password reset** | `forgot_password.php`. Emails a code, stored hashed in its own columns. |
| **Profile** | `account.php` (staff), `student/profile.php` (students). |

**Fails open by design:** if a code cannot be emailed (no usable address, mail
outage) the sign-in is allowed through and the gap is written to the audit log.
A login feature must never be the reason the office cannot get into its own
system.

---

## 2. The QR code

**Where:** `student/qr.php`, `qr/`, `includes/qr_helper.php`

Generated at registration, tied to the student's School ID, and never changes.
Shown on the student's dashboard, attached to the welcome email, and printable.
It is what the marshal scans at the gate.

---

## 3. The gate scanner

**Where:** `spck_scanner.html` (the master), `phone_scanner/` (the copy you put
on a phone), `scanner-sw.js`, `api/scanner_data.php`, `api/scan_submit.php`

A single self-contained page with three screens: **setup → duty → scan**.

| Feature | How it works |
|---|---|
| **Two setup routes** | **Import file** — fully offline, student list from a USB (`.vtsl` or CSV), no server or login. **Connect online** — points at the laptop's address and syncs live. |
| **Adopts its own address** | If the scanner was opened *from* the site, it works out the server address from the URL rather than asking the marshal to type what the phone already knows. |
| **QR camera** | `html5-qrcode.min.js`, bundled locally. |
| **Automatic proof photo** | The camera captures a still by itself after a short cooldown once a violation is picked. Stored in IndexedDB, sent with the scan. |
| **Offline queue** | Scans are held on the device and sync themselves when the server is reachable. Each carries a badge: **synced / queued / held**. |
| **Three-state connection badge** | **Connected** / **No server** / **Offline** — the middle state exists because reporting "Online" while nothing was syncing let marshals finish a shift believing their scans had reached the office. |
| **Encrypted hand-over file** | **Finish session** writes an encrypted `.vtsl` of the shift. Refuses to fall back to plain text — it used to, which meant a readable file of names and offences whenever encryption was unavailable. |
| **Duplicate guard** | A scan that synced live and then arrives again inside a hand-over file is not counted twice. |
| **Installable** | `scanner.webmanifest` + `scanner-sw.js` cache the whole app, so it opens with no signal. |

**Keeping the two copies in step:** `spck_scanner.html` in the root is the
master. `tools/sync-scanner.bat` refreshes `phone_scanner/` from it. Do not
hand-edit files inside `phone_scanner/` — the copies drifted apart once and
each ended up with fixes the other was missing.

---

## 4. On-duty control

**Where:** `includes/on_duty_panel.php`, included by `admin/setting.php`;
handlers `admin/toggle_scanning.php`, `admin/save_duty_hours.php`,
`admin/sign_off_duty.php`, `admin/on_duty_status.php`; logic in
`includes/functions.php`

A card at the top of **Settings** — **Admin only**. It used to be a folding
panel in the sidebar, drawn on every page in the app and open to OSA; the
switch, the schedule and the roster are all settings, so they live with the
settings now, and setting the gate up is Admin's while working it is OSA's.

| Feature | How it works |
|---|---|
| **Live roster** | Both marshal slots, with names and School IDs, refreshed every 20 seconds. An empty slot renders as *Slot N — free*. "On duty" is derived from a live, unexpired scanner session — there is no second table to drift out of sync with reality. |
| **Two slots, enforced** | `vts_claim_duty_slot()` locks the rows and counts before granting, so two simultaneous claims cannot both become slot #2. |
| **Third-marshal alert** | A refused third claimant is reported to every Admin/OSA **by name and School ID** — being turned away leaves a record rather than a silent lockout. |
| **Duty schedule** | Monday–Saturday, 7:30 AM – 6:00 PM by default. Editable on the same card: day toggles plus opening/closing times. Stored in `system_settings`; the constants in `config/app.php` are the fallback defaults. |
| **Schedule validation** | Refuses no-days, and a closing time at or before the opening time — either would shut the gate permanently. |
| **Session ends with the window** | A shift claimed inside the window expires when the window closes, plus a short grace for end-of-shift syncing. Sessions used to run a flat 8 hours, so a 5:55 PM start ran to 1:55 AM on an unstaffed gate. |
| **Master switch** | Turns scanning off for everyone without touching any account — **including shifts already running**. Switching it off clears every live session token, notifies each marshal, and names them in the audit entry; their phone's 20-second check then returns them to its sign-in screen with the reason. It used to set the flag and nothing else, so anyone already holding a slot kept scanning and "off" meant "off for people who are not currently scanning". |
| **Sign-off** | Frees a slot someone left open, and notifies them. |
| **Live from anywhere** | The 20-second poll runs on the Settings page rather than on every page in the app, so the roster is still current the moment it is opened. |

---

## 5. Recording violations

**Where:** `admin/add_violation.php`, `osa_staff/add_violation.php`,
`api/scan_submit.php`, `record_violation()` in `includes/functions.php`

Two doors, one function — the gate scanner and the office form both end at
`record_violation()`, so the rules cannot differ between them.

| Rule | How it works |
|---|---|
| **Offence ladder** | Violations are numbered per student (Violation #1, #2, #3) from their active prior violations. A cleared one, or one a proof review rejected, no longer counts, and the ladder renumbers. |
| **One Major at a time** | `major_violation_blocked()`. A student with an unresolved Major cannot be given a second. Enforced inside `record_violation()` itself, so no path present or future can slip one past. |
| **Identity is server-side** | `vts_resolve_marshal()`. The name filed against a gate scan is read off the staff record, never from what the device sent — a phone cannot choose whose name a scan is recorded under. |
| **Violation types** | Maintained in `admin/violation_rules.php` *(Admin only)*. The scanner also carries a built-in list so it works offline out of the box. |
| **"Others"** | Free text the marshal types is kept as the record's *description*, not folded into the violation name — so picking Others does not mint a new violation type per incident. |

---

## 6. Viewing and filtering violations

**Where:** `admin/violations.php`, `osa_staff/violations.php`,
`student/violations.php`, `student/view_violation.php`

| Feature | How it works |
|---|---|
| **Two views** | **Official Sheet** — one row per student, the layout the office submits. **Records** — the per-violation list with row actions. |
| **Search** | Student name, School ID, or violation text. |
| **Filters** | Date range or a preset period, year level, course, section, violation type, department. Filters are carried into the export link and across the view toggle. |
| **Department shortcuts** | The sidebar's Violations drawer jumps straight to a department's Official Sheet. |
| **Recorder visibility** | `vts_can_see_recorder()` — only **OSA and Admin** see which marshal recorded a violation. OSA Staff and students see the violation but never the name of the person on the gate. |
| **Student's own list** | `student/violations.php`, searchable, each row linking to the record's own detail page. |

## 7. Proof photos

**Where:** `admin/proof.php`, `uploads/evidence/`

Every gate scan carries an automatically captured photo. This page shows them
full size, because the only place a photo could previously be seen was a 34px
thumbnail — too small to tell whether it matched the record.

A violation with no photo is listed as **No proof attached** rather than
hidden: a gate device can be an older build or have had its camera permission
refused mid-shift, and a scan that really happened must still reach the office.
A visible gap is something the office can act on.

| Control | What it does |
|---|---|
| **Approve** | The photo supports the record. Nothing changes — an unreviewed violation counted already. |
| **Reject** | The photo does not support it. The row stays in history but stops advancing the offence ladder, and the student is told. |
| **Clear** | Undoes a decision. The record counts as normal again. |
| **Record conference** | Logs the meeting a Major needs before it can be rejected or deleted. |
| **Delete** | Erases the record and unlinks its photo. Not reversible. |

**There is no reviewer note field, deliberately.** The page asks one question
— does the photo show what the record claims — and Approve/Reject is the
whole answer. A free-text box beside that invited written commentary on a
student from someone whose job on that screen is to read a photograph, and it
was then posted to the student inside their notification. What a meeting with
the student established is a different fact and has its own field
(`discussion_note`).

**Who may delete.** Admin, always — full CRUD, and a Major with no conference
on record is named as such in the audit log. OSA, only once that conference is
recorded. See `vts_can_delete_violation()`.

---

## 8. Students and the enrolled roster

**Where:** `admin/students.php`, `osa_staff/students.php`,
`admin/view_student.php`, `admin/edit_student.php` *(Admin)*,
`admin/delete_student.php` *(Admin)*, `admin/import_roster.php`

Two tabs:

**Registered** — students with accounts. Search by ID, name or course; view;
*(Admin)* edit or delete. Deleting purges the account, its violations, its
notifications and its roster entry, so the record cannot be resurrected.

**Enrolled Students** — the official roster that gates registration.

| Feature | How it works |
|---|---|
| **Import** | `.xlsx`, CSV, or pasted rows. |
| **Unregister** | Frees a School ID so it can register again. |
| **Clear all** | Empties the list. **While it is empty, registration validation is off.** |
| **Manual email verify** | For when the verification email could not be delivered. |

---

## 9. Staff accounts

**Where:** `admin/users.php`, `admin/add_user.php`, `admin/edit_user.php`,
`admin/view_user.php`, `admin/delete_user.php`

Add, edit, deactivate and delete staff, and set roles
(`Student`, `Guard`, `OSA Staff`, `OSA`, `Admin`). Search by name, username,
email or role.

**OSA cannot create, edit or delete an Admin account.** Those rows are locked
in the list and refused server-side.

---

## 10. Reports and printables

**Where:** `reports/`

| Document | File | Purpose |
|---|---|---|
| **ID Confiscation Slip** | `violation_slip.php` | 5.5×4.5in, yellow stock — issued when an ID is taken at the gate |
| **Student Violation Slip** | `student_violation_slip.php` | 8.5×6.9in — the formal record of the violation |
| **Undertaking** | `undertaking.php` | The promise a student signs after a violation |
| **Parent Notice** | `parent_notice.php` | Letter informing a parent/guardian |
| **Violators List** | `violators_list.php` | Printable list mirroring the admin table's columns |
| **CHED Transfer** | `transfer_ched.php` | Sends the consolidated report to CHED — the last hop of the chain |

---

## 11. Exports

**Where:** `admin/export_*.php`, `osa_staff/export_*.php`,
`includes/functions.php`

| Export | What it is |
|---|---|
| **Violations → Excel** | The current view with its filters applied |
| **Official Sheet → Excel** | The submission-format sheet |
| **Students → Excel / CSV** | The student list |
| **Scanner file** | Provisions a marshal's phone offline |
| **Users → Excel** | The staff list |
| **Categorized export** | Violations split by category |

Export buttons also open the matching Google Drive folder, configured per
department in `config/drive.php`. The app does **not** upload to Drive — a
service account has no Drive storage of its own, so every upload failed with a
quota error. The buttons open the right folder so the downloaded file can be
dragged in; that needs no account, key or subscription.

---

## 12. Notifications

**Where:** `admin/notifications.php`, `student/notifications.php`,
`api/notifications.php`, `notify_once()` in `includes/functions.php`

A bell with an unread count in the navbar.

| Sent when | To |
|---|---|
| A violation is recorded | The student |
| Compliance proof is submitted | Admin + OSA |
| A marshal goes on duty | Admin + OSA |
| A third marshal is turned away | Admin + OSA |
| A marshal is signed off | That marshal |

`notify_once()` collapses duplicates inside a 60-second window, so a phone
reconnecting repeatedly cannot spam the office.

---

## 13. Statistics

**Where:** `admin/statistic.php`, plus the dashboard charts;
`assets/vendor/chart.umd.min.js` (local, no CDN)

Highest violation type · department with the most violations · by offence
number · violations over time · students with the most violations · by year
level · by section.

---

## 14. Audit log

**Where:** `admin/audit_log.php`, `audit_log()` in `includes/functions.php`

Every significant action, with who did it, what it touched, and when —
logins (including any that bypassed a code, and why), violations, proof
decisions, student and staff changes, scanner sign-offs, duty-schedule
changes, imports, exports and backups.

Searchable. **Admin only, to read as well as to clear** — the log reports on
everyone with an account, OSA included, so the people it records are not the
people who read it.

That covers every window onto it, not just the page: the **Recent Activity**
panel on the dashboard and the **Recent activity** table on a user's record
are both Admin's, and for a non-Admin the query behind each is skipped rather
than the markup merely hidden.

---

## 15. Backup and restore

**Where:** `admin/backup.php` *(Admin)*, `includes/restore.php`,
`backups/`

A one-click full `.sql` dump of every table, written in pure PHP so it does
not need `mysqldump` on the host. Restore replays a dump and merges the
students side-table back into `users` — without that step a restore would
report success while leaving every student in a table the app never reads.

`backups/auto/` also collects automatic dumps taken around bulk operations.

---

## 16. Settings

**Where:** `admin/setting.php` *(Admin)*, `config/app.php`, `config/drive.php`,
`config/mail.php`

| Setting | Where |
|---|---|
| School name, address, email, contact | `admin/setting.php` |
| Academic year and semester | `admin/setting.php` |
| Google Drive folder links | `admin/setting.php` / `config/drive.php` |
| Duty days and hours, scanning on/off | `admin/setting.php` → **Scanner & duty** (defaults in `config/app.php`) |
| App name and tagline | `config/app.php` |
| Whether students must set a password | `STUDENT_PASSWORD_REQUIRED` |
| Staff two-step sign-in | `STAFF_OTP_ENABLED`, `STAFF_OTP_ROLES` |
| Mail credentials | `config/mail.php` |

---

## 17. Email

**Where:** `includes/mailer.php`, `config/mail.php`, `admin/mail_test.php`

Two providers, chosen automatically: the **Brevo HTTP API** (works where SMTP
is blocked) and **Gmail SMTP via PHPMailer**.

Carries: the welcome email with the QR code, sign-in codes, new-device codes,
password resets, violation notices, and the CHED report.

**Mail never blocks a page.** Every send is time-capped, a connection failure
is remembered for the rest of the request so a bulk send does not pay a
timeout per recipient, and a failure is logged rather than shown.

**Admin → Mail Test** reports exactly which part of the setup is failing and
sends a live test message.

---

## 18. Working offline

The system is built to run on a laptop with **no internet at all** — only a
local network between that laptop and the marshals' phones.

| Feature | How it works |
|---|---|
| **No external requests** | Fonts (Inter, Plus Jakarta Sans), Font Awesome, Chart.js and the scanner's libraries are all served from `assets/`. Nothing is fetched from a CDN, including from inside stylesheets. |
| **Scanner works with zero signal** | Its service worker caches the whole app; scans queue locally and sync later. |
| **The server is tested, not guessed** | The scanner probes the actual server rather than trusting the browser's opinion of whether it is "online" — a browser reports *offline* on a laptop hotspot with no internet, even though the server is one hop away. |
| **Student portal installable** | `sw_student.js` caches the shell so it opens offline. |
| **LAN access is not forced to HTTPS** | Every private address range is exempt from the HTTPS redirect, so phones on a hotspot are not bounced to a certificate that does not exist. |
| **Time-capped mail** | So a page never hangs waiting on a mail server that cannot be reached. |

---

## 19. Security

**Where:** `auth/session.php`, `auth/auth.php`, `.htaccess`,
`includes/functions.php`

| Measure | How it works |
|---|---|
| **Password hashing** | `password_hash()` / `password_verify()`. |
| **CSRF tokens** | On every state-changing form. |
| **Prepared statements** | Throughout — no query is built by string concatenation. |
| **Session hardening** | httponly, SameSite, secure-when-HTTPS; ID regenerated on sign-in; pinned to a browser fingerprint. |
| **Timeouts** | 2 hours idle, 12 hours absolute. |
| **Two-step staff sign-in** | A code on every staff login — built, but **off by default** (`STAFF_OTP_ENABLED`). |
| **New-device gate** | A code for any browser an account has not used. **Active for every role**, independent of the setting above. |
| **Rate limiting** | 6 wrong codes per email and 20 per IP in 15 minutes, shared across every code type. |
| **Role gates** | Checked server-side on every page, not by hiding menu items. |
| **No backend detail in messages** | Database errors are logged server-side; users get a sentence they can act on. A raw driver error used to reach the scanner's response, where it was visible in a phone's network tab. |
| **Security headers** | `X-Frame-Options`, `nosniff`, `Referrer-Policy`, `Permissions-Policy`, and a CSP with `form-action 'self'` — which is what stops a rewritten form action from posting a password somewhere else. |
| **Protected files** | `.htaccess` blocks `.sql`, `.env`, backups and dotfiles; `auth/`, `config/`, `database/` and `includes/` are not web-reachable except for the specific form handlers that must be. |
| **Back-button guard** | A signed-out page cannot be thawed from the browser's back/forward cache. |
| **Audit trail** | Every significant action is recorded, including sign-ins that skipped a code and why. |

### What client-side code can and cannot protect

Anything running in a browser — the scanner included — **can be read and
edited** by whoever is holding the device. That is how browsers work, and no
amount of obfuscation changes it.

So the client is treated as a convenience, never as a control. The rules that
matter are enforced on the server:

- The marshal's identity is resolved from the staff record, not from what the
  device sent.
- A scan is only accepted with a valid, unexpired duty session.
- The two-marshal cap, the duty hours and the one-Major rule are all decided
  server-side.
- Every page re-checks the role of whoever is asking.

Editing the scanner's JavaScript changes what that phone *displays*. It does
not change what the server will *accept*.
