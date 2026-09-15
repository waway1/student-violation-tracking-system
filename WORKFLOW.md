# QR Shield — Workflow

> **QR Shield: A QR Code-Based Student Violation Tracking System for Promoting Safe and Inclusive Learning at Golden West Colleges, Inc. Supporting SDG 4 – Quality Education**

How a violation travels from a scan at the gate to the records office, and how
the system runs **online and offline**.

---

## Roles (2026-08 restructure)

```
STUDENT ──scanned by──► GUARD/MARSHAL ──reports directly to──► OSA / ADMIN ──reports to──► CHED
```

- **Guard/Marshal** — scans students, records violations, and sends the day's
  report **straight to OSA/Admin**. There is no consolidation step anymore —
  the old Head Marshal role (which used to collect every guard's records
  before forwarding) has been folded straight into Guard/Marshal.
- **OSA Staff** — day-to-day office work: add/edit/view students & violations,
  run reports and exports. No delete access, no user management, no settings.
- **OSA** — admin-level. Same pages as Admin (students, violations, users,
  settings, backup, audit log), with one guard rail: OSA can't create, edit,
  or delete an **Admin** account (only an actual Admin can do that).
- **Admin** — full system control.

The Dean role has been removed — there is no more dean/college-level
notification step. OSA/Admin see everything as it's recorded.

Nobody is a bottleneck: a scan is saved the instant it is recorded — to the
database when online, to the device when not.

---

## The hybrid scanner (`spck_scanner.html`)

One app, works both ways — auto-detects the connection, no separate "online"
and "offline" tools anymore.

**Setup (first time on a device):**
- **Online** → tap *"Set up online now"* — pulls the live student list +
  violation types straight from the server. No file needed.
- **Offline** → import the student-list file (`.vtsl` or `Students.csv`) that
  OSA/Admin hands over on a USB. Carries no passwords/account hashes, so it's
  safe to hand around.

**Scanning — scan ▸ cooldown ▸ photo ▸ submit:**

1. **Scan** — the camera reads the student's QR and pulls up their record.
2. **Cooldown** — that ID is locked for a few seconds, so a code held in
   front of the lens (the detector runs several times a second) cannot file
   the same violation twice. This is a *short* lock, not the system's
   duplicate guard: the server keeps its own 120-second window as well.
3. **Photo** — when the cooldown ends the camera takes the shot by itself,
   copying a frame out of the video it is already running for the QR. No
   second permission prompt, no aiming twice. The marshal sees the frame
   and can **Retake** before filing — a picture of the pavement is worse
   than no picture, because the office cannot tell it is useless until it
   is judging the record.
4. **Submit** — the photo goes with the reason as the violation's proof,
   and lands on **Violations ▸ Violation Proof** for the OSA to read one
   against the other.

If the camera cannot supply a frame (permission refused mid-shift, older
device), the scan **still records** and is marked "No proof attached"
rather than being blocked — a visible gap the office can act on beats a
lost violation. The web Add Violation forms do require proof, because
someone at a desk can always attach one.

- Every scan saves to the device **instantly**, online or off.
- Proof photos travel with the record: straight to the server on a live
  sync, or inside the encrypted `.vtsl` when the shift ends with no signal.
- If the phone's storage fills, **photos** are dropped — never scans — and
  the marshal is told. Photos already synced go first, since the server
  has those.
- **Online**: the scan also syncs straight to the database in the background
  (retries automatically every 20s if a sync attempt drops).
- **Offline**: it queues locally and syncs automatically the moment the
  connection comes back — no action needed from the guard.
- If the shift ends before a connection ever comes back, **Finish Session**
  exports the day's scans as an encrypted `.vtsl` file to hand to OSA/Admin
  on a USB — nothing is ever lost.

**Install it as an app:** the scanner is an installable PWA
(`scanner.webmanifest` + `scanner-sw.js`) — "Add to Home Screen" on the gate
device once, and it opens instantly and keeps working with zero signal.

---

## Guard/Marshal's report (no more consolidation hop)

At the end of a shift, the Guard/Marshal dashboard (`guard/dashboard.php`)
shows the day's scans and one button: **Send Report to OSA/Admin**
(`guard/send_report.php`). This emails the OSA inbox plus every active
Admin/OSA/OSA Staff directly — the old two-step "submit to Head Marshal, who
then forwards to OSA" chain is gone.

If mail is unavailable, the button falls back to a downloadable CSV so the
report can still be handed over.

---

## File flow — online vs offline

```
             ONLINE                                   OFFLINE
             ------                                   -------
Set up:   web DB ──► scanner (one tap)             Students.csv ──► scanner (USB/LAN)
Scan:     saved to DB live + queued locally        saved on device, queued
Sync:     automatic, retries every 20s             Records.vtsl ──► hand-carry
Report:   Guard/Marshal ──► OSA/Admin (email)      Guard/Marshal exports ──► hand-carry
Escalate: Admin ──► CHED                           print / USB
```

---

## Counting rules

Two different counters, because they answer different questions:

| Counter | Meaning | Resets? |
|---|---|---|
| **Overall** | every violation the student has, all types | never — drives the offense ladder |
| **Same violation** | how many times *this* violation type | never, but counted per type |
| **Today** | how many the student got today | **yes, daily** |

A **cleared Minor** keeps its place in history but stops advancing the ladder.
Offense numbers are always **re-derived from the data** after any insert,
delete, clear or import — never remembered from the past.

---

## Documents

| Document | Size | Purpose |
|---|---|---|
| **Student Violation Slip** | 8.5 × 6.9 in | the checkbox slip issued for the violation |
| **ID Confiscation Slip** | 5.5 × 4.5 in, yellow | issued when the ID is taken; presented to claim it |

Both are **editable on screen** before printing — click any blank to type, click
any box to tick it.

---

## Who sees what

| Role | How they sign in | Sees the recorder? |
|---|---|---|
| Student | search name → confirm School ID (no password) | **No** — only date, type, kind, counts |
| Guard/Marshal | name + staff ID on the scanner (no password); or username + password on the website | own scans |
| OSA Staff | username + password | **No** |
| OSA / Admin | username + password | **Yes** |

The guard/marshal who reported a violation is **never shown to the student**.
`Violations ▸ Violations Only` gives OSA/Admin a full-detail view with the
reporter withheld — safe to print, project, or hand to anyone.

> Login lockout is **off**: staff mistype passwords on a shared gate device and
> being frozen out stopped real work. Attempts are still logged for audit. Set
> `VTS_LOGIN_LOCKOUT` to `true` in `config/database.php` to turn it back on.

---

## Migrating an existing install

Run `database/upgrade_2026-08_role_restructure.sql` once in phpMyAdmin. It:
- moves any existing Head Marshal accounts onto Guard/Marshal,
- deactivates any existing Dean accounts (data kept, just logged out until
  reassigned to a real role),
- and updates the `role` column to the final list:
  `Student, Guard, OSA Staff, OSA, Admin`.

A fresh install from `database/student_violation_system.sql` already has the
final role list — no upgrade script needed.
