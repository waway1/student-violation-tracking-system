# CHANGELOG — 2026-08 Role Restructure + Hybrid Scanner

## Update: student portal debugged (online/offline/hybrid) + backup & routing improvements

**Student dashboard/portal — bug fixes:**
- Fixed a real bug in `student/dashboard.php`: when a student has **zero
  violations**, the empty-state message referenced an undefined variable
  (`$v['id']` from a loop that never ran) — this threw a PHP warning and
  produced a broken link. It's exactly backwards from what should happen
  for a student in good standing, so it would have hit often.
- While fixing it, found the *intended* behavior — click a recent
  violation to see its details — had been wired to the wrong branch (the
  empty state) instead of the actual list items. Restored it where it
  belongs.
- `sw_student.js` (the student PWA service worker) was only precaching
  3 of the 6 CSS files the dashboard actually loads — could have left a
  first-ever offline visit unstyled. Completed the list and bumped the
  cache version so already-installed devices pick up the fix.

**Student portal — new online/offline/hybrid presentation:**
- Added a small connectivity pill (Online/Offline, matching the scanner's
  visual language) to the navbar on every student page.
- When offline, a banner now says plainly that you're viewing the last
  saved version of the page, with a timestamp — instead of silently
  showing possibly-stale numbers with no indication.
- Form submissions (delete a notification, save profile, etc.) now fail
  with a clear "you're offline" message instead of a confusing generic
  browser network error.

**Backup — Google Drive limitation + manual alternatives:**
- Added a notice on the Backup page: Google Drive has a storage cap, so
  don't rely on it alone.
- Relabeled the existing "Download .sql" as the explicit **USB backup**
  path (download, then copy to a flash drive) — this already existed,
  just wasn't framed that way.
- **New: Email Backup.** Sends the same Excel backup straight to an inbox
  over Gmail/Brevo (whichever this install already uses) instead of
  downloading it — for when you're online but don't have a USB on hand.
  Reuses the same scope/date filters as the Excel download, and is capped
  at 8&nbsp;MB so a huge "everything" export doesn't silently fail —
  narrow the scope or date range if it's too big.

**Two Guard/Marshal accounts only (leader + assistant):**
- `admin/add_user.php` and `admin/edit_user.php` now enforce a maximum of
  2 **active** Guard/Marshal accounts at a time, matching that policy.
  Creating a 3rd, or reactivating one that would make a 3rd, is blocked
  with a clear message; deactivate one first to swap someone in. The cap
  is a single constant (`vts_guard_slot_available()` in
  `includes/functions.php`) if it ever needs to change.

**Criminology's separate OSA — file transfers route directly to them:**
- New: **Settings → Department OSA Contacts** — every department shares
  the one general OSA inbox by default; give a specific department (e.g.
  Criminology) its own contact there and Guard/Marshal's daily report
  automatically **splits**: that department's violations go straight to
  their own OSA, everyone else still goes to the general inbox as before.
  Nothing to configure for departments that don't have their own OSA —
  they're unaffected.
- Migration: `database/upgrade_2026-08b_department_osa.sql` (adds the two
  new columns to `colleges`). Self-heals if skipped, same as the other
  upgrade scripts.
- This covers the **emailed** report. The scanner's offline `.vtsl`
  export (USB hand-off when there's no connection at all) is one file
  covering everyone the guard scanned that day, since it's built
  on-device without a live department lookup — the department split only
  applies once it's imported and reported from the website. Flagging
  this in case you specifically need the *offline* USB hand-off itself
  split by department too — that would need a further change to the
  scanner.

## Concerns noted, not code changes

A few items from your notes are hardware/procurement/staffing decisions
rather than something the software can decide for you — noted so they
don't get lost, not guessed at:
- **What device the marshals use** — the hybrid scanner works on any
  phone/tablet with a camera and a browser (Chrome/Edge recommended for
  the offline PWA install). No specific device required.
- **OTG connector + 4 flash drives** — for the USB hand-off path, this is
  the physical kit; nothing in the software depends on a particular
  count or brand.

---

## Update: scanner rebuilt again — real authentication, both online and offline

`spck_scanner.html` was rebuilt a third time around a clearer 3-step flow —
**Setup → Sign In → Scan** — using the polished navy/gold kiosk UI (topbar,
step indicator, panel cards, sheet-based student picker, big green
confirmation) instead of the plainer version from the previous round.

The real change is Step 2. Earlier versions either had no real check
(name-only, self-serve) or only supported one connection type. Now sign-in
is genuine either way:

- **File import (offline):** import the `.vtsl` file Admin/OSA already
  exports from Students → Export .vtsl. It's the same encrypted file the
  backend has supported for a while — it just wasn't wired into the scanner
  UI's actual sign-in step until now. That file carries bcrypt password
  hashes for every active Guard/OSA Staff/OSA/Admin account, so **Sign In
  checks a real username + password on-device**, with zero internet,
  using the bundled `bcrypt.min.js`. A plain CSV/Excel (students only, no
  account list) still works — Step 2 just falls back to a labeled
  name-only entry in that case, clearly flagged as lower-trust.
- **Connect Online:** pulls the live student list, then Step 2 checks the
  username + password against the live server (`api/scan_api.php
  action=login`) and gets a real session token.

Both paths feed the same Step 3 scanner. Recording still saves instantly
either way — live to the database when online and signed in with a valid
token, queued on-device and auto-synced (every 20s, and instantly on
reconnect) otherwise. Finish Session exports whatever's still unsynced as
an encrypted `.vtsl` for OSA/Admin, same as before.

One honest edge case worth knowing: if a device only ever gets a plain-CSV
import (no `.vtsl`, never connected online), its violation list falls back
to a built-in name-only list with no server-side IDs. Those specific
records can never auto-sync — the app now says so plainly at the moment
you log one ("this violation type has no server ID on this device, so it
can't auto-sync") instead of quietly promising a sync that will never
happen. It's still saved and still exportable at Finish Session either way.

**Packaging fix:** the last few deliveries were missing `qr/phpqrcode`
(the vendor library `includes/qr_helper.php` needs to generate student QR
codes) — an oversight in how the ZIP was being built, not something in
your project. It's back in this one; earlier ZIPs would have broken QR
generation if deployed as-is. Apologies for that.

---

## Update: scanner merged with the SPCK-editor connect flow

`spck_scanner.html` was rebuilt again to merge in a cleaner UI (two-step
"confirm identity → confirm violation" flow, a proper Back button, an
IP-connect screen for running it standalone in SPCK Editor) while keeping
full hybrid capability:

- **Auto-detects how it's being run.** Opened from the website → connects
  same-origin automatically, no IP needed (correctly respects a subfolder
  deployment, e.g. `htdocs/SAD`). Opened standalone (SPCK Editor, or any
  browser pointed straight at the file) → asks for the XAMPP laptop's IP
  (optionally with a subfolder, e.g. `192.168.1.5/SAD`).
- **Picks online or offline up front** — "Connect Online" (personal
  username/password login, live lookups with real violation counts) or
  "Work Offline" (uses the last cached list, or import `Students.csv` once).
- **Same hybrid guarantees as before:** scans save instantly either way;
  online ones sync live; offline ones queue and sync automatically the
  moment a connection appears (retried every 20s, and instantly on the
  browser's `online` event); a manual "Sync Now" tap is available on the
  status pill; **Finish Session** exports any still-unsynced records as a
  CSV so nothing is ever stuck only on one device.
- Uses the `record_open` device-key endpoint (already in `api/scan_api.php`,
  unchanged) to sync queued offline scans — this doesn't need the guard's
  personal login token to still be valid, only the shared device key, so a
  queue from hours ago still syncs even after an 8-hour token would have
  expired.
- No backend/PHP changes were needed for this — `api/scan_api.php` and
  `api/scanner_data.php` already supported everything this needed.
- Fixed a real bug while wiring the offline CSV import: the admin's
  `Students.csv` export writes a UTF-8 BOM at the start of the file, which
  would have silently broken the very first column match on import.
- `scanner-sw.js` trimmed to only precache what this version actually uses
  (dropped `bcrypt.min.js` / `xlsx.full.min.js`, unused here); the QR
  camera library still loads from a CDN (matches how you're running it in
  SPCK Editor) and gets cached the first time it loads successfully, so
  camera scanning keeps working offline after that first load.
- A copy of just the scanner (`spck_scanner.html`) is included separately
  in this delivery too, for pasting straight into SPCK Editor to test.

---

## Roles: 6 → 5

**Before:** Student, Guard, Head Marshal, OSA, Dean, Admin
**After:** Student, Guard (displayed as "Guard/Marshal"), OSA Staff, OSA, Admin

- **Head Marshal removed.** Its only job — collect every guard's day-end
  report and forward it — is gone. Guard/Marshal now sends straight to
  OSA/Admin (`guard/send_report.php`, rewritten). `guard/escalate.php`
  (the old "no one available" fallback) was deleted since there's no longer
  a normal path to fall back from — the direct send *is* the normal path.
- **Dean removed entirely.** No more college-level dean notification.
  `dean/` folder deleted, `notify_college_deans()` removed from
  `includes/functions.php`.
- **OSA Staff added** — a new, lighter tier. The old `osa/` folder was
  renamed to `osa_staff/`; delete actions were hard-blocked (buttons removed
  from the UI, and the delete endpoints redirect back with an error even if
  reached directly).
- **OSA elevated to admin-level.** `admin/*.php` auth now accepts `Admin`
  **or** `OSA`, so OSA uses the exact same pages as Admin. One guard rail:
  OSA can't create, edit, or delete an **Admin** account — only an Admin can
  touch another Admin account (`admin/add_user.php`, `edit_user.php`,
  `delete_user.php`).

## Database

Run **`database/upgrade_2026-08_role_restructure.sql`** once in phpMyAdmin
on an existing install. It:
1. Moves existing Head Marshal accounts → Guard.
2. Deactivates existing Dean accounts (data kept, logged out until
   reassigned).
3. Updates the `role` ENUM to the final 5-role list.

A fresh install from `database/student_violation_system.sql` already ships
with the final role list, updated seed accounts, and updated
`osa_officers` / `osa_staff` / `guards` views — no upgrade script needed.

`includes/functions.php` also got a matching `vts_ensure_role_enum()`
safety-net (replaces the old `vts_ensure_head_marshal()`), so the app
self-heals the enum even if the SQL script hasn't been run yet.

## Hybrid online/offline scanner

`spck_scanner.html` is now the **single** scanner app — `scanner_app.php`
(the old online-only, PHP-session kiosk) is retired to a one-line redirect
stub so old links/bookmarks still work.

- **New: one-tap online setup.** If the device has a connection when you
  first open the scanner, tap *"Set up online now"* — it pulls the live
  student list straight from `api/scanner_data.php` (a public, no-login
  feed). No more USB file required for the common case; the file import
  is still there as the offline fallback.
- **Confirmed working (was undocumented): background auto-sync.** Scans
  sync to the database automatically while online, retrying every 20s if a
  sync attempt drops, and flush the offline queue the moment the connection
  returns. A misleading old comment claimed this was "removed" — it wasn't;
  it just wasn't wired to the setup screen. That comment (and a dead stub
  function it left behind) has been cleaned up.
- **New: `scanner-sw.js` + `scanner.webmanifest`.** The scanner referenced a
  service worker and a manifest that didn't exist in this ZIP, so the app
  shell was never actually cached — meaning true zero-signal offline use
  (opening the app itself with no connection at all) wasn't reliable. Both
  files now exist, so the scanner installs as a real offline-first PWA.
- Removed an orphaned, unregistered `sw.js` and an orphaned, unlinked
  `manifest.json` left over from the old `scanner_app.php`-based setup.

## Everywhere else

Every role picker, sidebar, login redirect, CSV/export role filter,
recorder-visibility check, and report recipient list across the codebase
was updated to the new 5-role list. A full-codebase grep for `Dean` /
`Head Marshal` returns clean except for:
- the historical `database/upgrade_2026-07*.sql` scripts (left untouched —
  they're a record of what was actually run against the live DB in the
  past), and
- this changelog / `WORKFLOW.md`, which document the change.

`WORKFLOW.md` was rewritten to describe the new chain, the hybrid scanner,
and the migration step.

## Things to sanity-check after import (no local PHP available to test with)

- Log in as OSA and confirm the `admin/` pages load and the Admin-account
  guard rails behave as expected (try editing/deleting an Admin account
  while logged in as OSA — it should be blocked).
- Log in as OSA Staff (seed account `osa.staff`) and confirm delete
  buttons are gone from Students/Violations and the direct-URL block works.
- Open `spck_scanner.html` on a phone with a connection and try
  "Set up online now".
- Do one offline scan (airplane mode), then reconnect and confirm it
  syncs automatically without touching anything.
