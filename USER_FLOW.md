# QR Shield — User Flow

**Who does what in this system, and the order they do it in.**

QR Shield records student violations at the campus gate and carries each one
through to a decision. This document describes the *people* and the *path a
violation takes*. For step-by-step instructions see **USER_MANUAL.md**; for
where each feature lives see **FEATURES.md**.

---

## 1. The five roles

| Role | Who they are | Where they land after signing in |
|---|---|---|
| **Student** | Any enrolled student | Student dashboard |
| **Guard / Marshal** | The person holding the scanner at the gate | The scanner app |
| **OSA Staff** | Office assistants who record and look up violations | OSA Staff dashboard |
| **OSA** | Office of Student Affairs — reviews and decides on records | Admin dashboard |
| **Admin** | System owner; everything OSA can do, plus the settings | Admin dashboard |

**OSA and Admin share the same pages.** The difference is a small set of
Admin-only powers, listed in §7.

A **student can also be a marshal.** There is no separate marshal account —
any active student may take a scanner slot during duty hours. Their student
record and their marshal duty are the same account.

---

## 2. The path a violation takes

This is the spine of the whole system. Everything else supports it.

```
   STUDENT                MARSHAL              OSA / ADMIN            STUDENT
   registers      →    scans the QR     →    reviews record    →   sees it on file
                                                   │
                                                   ▼
                                            PROOF REVIEWED
                                                   │
                                   ┌────────────┴────────────┐
                                APPROVED                  REJECTED
                             stays on record          stops counting
                                   └────────────┬────────────┘
                                                   ▼
                                    reports printed / sent to CHED
```

**Step by step:**

1. **A student registers** on the website using their School ID. The ID must
   already be on the enrolled-students list the office uploaded.
2. **They get a QR code** — shown on their dashboard and emailed to them.
3. **A marshal goes on duty** at the gate, during duty hours, and scans that
   QR code when they see a violation.
4. **The scanner takes a proof photo** and records the violation.
5. **The record reaches the office** — instantly if the scanner is connected
   to the server, or at the end of the shift via an exported file if not.
6. **The student is notified** and can see the violation on their dashboard.
7. **The office checks the proof photo** against the reason the violation
   was recorded for — see §5.
8. **The office prints or sends** whatever paperwork the case needs: a
   violation slip, a parent notice, an undertaking, or the consolidated
   report to CHED.

---

## 3. Student flow

### Getting an account

1. Open the site and choose **Register**.
2. Enter the School ID. The system checks it against the enrolled list —
   an ID that is not on that list cannot register.
3. Fill in the rest of the form and submit.
4. The account is created and signed in immediately. A welcome email
   carries the QR code as an attachment.

### Signing in afterwards

Students sign in from the front page. The system decides which test applies
from the account itself:

- **Has a password** → School ID + password.
- **No password yet** → School ID + surname. (Older accounts only; new
  registrations always set a password.)

If the browser is one the account has not used before, a **6-digit code is
emailed** and must be entered before the sign-in completes. The session
already open on another device is not disturbed.

### Day to day

| What they want | Where they go |
|---|---|
| See their QR code | **QR Code** |
| See their violations | **Violations** |
| Read a violation in full | Open the violation from the list |
| Messages from the office | **Notifications** |
| Change their details or password | **Profile** |

Students **cannot** see which marshal recorded a violation. They see the
violation, the date and the reason — never the name of the person on the gate.

---

## 4. Marshal (Guard) flow

The marshal works in the scanner app, which is built to keep working with no
internet at all.

### Setting the device up (once)

Two routes, chosen on first open:

- **Import file — fully offline.** The Head Marshal hands over the student
  list on a USB. No server, no internet, no login needed.
- **Connect online — live sync.** The phone is on the same Wi-Fi as the
  laptop running the system. Scans reach the office as they happen.

### Every shift

1. **Go on duty.** Enter the School ID and confirm the name shown.
   - Only **two marshals may be on duty at once**, system-wide.
   - Duty is only possible **Monday–Saturday, 7:30 AM – 6:00 PM**.
   - If two are already on, the third is refused — and the office is told
     by name who was turned away.
2. **Scan.** Point the camera at the student's QR code.
3. **Pick the violation** from the list.
4. **The camera takes a proof photo** automatically after a short pause.
5. **The scan is saved.** Connected, it goes to the office at once. Not
   connected, it waits safely on the phone.
6. **Finish the session** at the end of the shift. This exports an
   encrypted hand-over file and frees the duty slot for the next marshal.

### What happens with no signal

Nothing is lost. Scans are held on the phone, sync themselves the moment the
server is reachable again, and are in the end-of-shift export either way. The
connection badge at the top of the scanner says which of three states it is in:

| Badge | Meaning |
|---|---|
| **Connected** (green) | The server answered. Scans are syncing. |
| **No server** (amber) | There is a network, but this system cannot be reached. Scans are safe and will sync by themselves. |
| **Offline** (grey) | No network. Scans are saved here and go out in the export. |

---

## 5. Proof review flow

Every gate scan carries a photo. Proof review is the office reading that
photo against the reason the violation was recorded for, and saying whether
the two agree.

| State | What it means | What it does to the record |
|---|---|---|
| **Pending** | Nobody has looked at it yet | Nothing — it counts exactly as recorded |
| **Approved** | The photo supports the record | Nothing — it counts as recorded |
| **Rejected** | The photo does **not** support the record | It stays in history but stops counting |

**Pending is not a holding pen.** A violation counts from the moment it is
recorded, reviewed or not — the gate scanner works offline, and a shift's
scans can reach the office days later. A review can only ever **subtract**.

A decision is reversible: rejecting the wrong row must be undoable, and the
offence ladder is re-derived each time it changes.

A **Major** cannot be rejected or deleted until the office has recorded that
it was discussed with the student in person. A student who disputes a record
raises it with the Office of Student Affairs directly.

---

## 6. Office flow (OSA / Admin)

### Daily

1. **Open the dashboard.** It leads with recent violations, then activity.
2. **Check who is on duty** — **Settings** → **Scanner & duty** shows both
   marshal slots live, with names and School IDs. *(Admin only.)*
3. **Review new violations** as they arrive from the gate.
4. **Check the proof photos** where a record needs verifying.

### Periodically

- **Import the enrolled-student list** at the start of a term, so students
  can register.
- **Import a hand-over file** from a marshal who worked offline.
- **Print the paperwork** a case needs — slips, parent notices, undertakings.
- **Send the consolidated report to CHED.**
- **Take a backup.**

### Managing the gate

From **Settings** → the **Scanner & duty** card *(Admin only)*:

- Turn scanning **on or off** for everyone at once.
- Change the **duty days and hours**.
- **Sign a marshal off** who left a session open, freeing their slot.

---

## 7. Who can do what

| Action | Student | Marshal | OSA Staff | OSA | Admin |
|---|:--:|:--:|:--:|:--:|:--:|
| See their own violations | ✔ | ✔ | ✔ | ✔ | ✔ |
| Record a violation at the gate | — | ✔ | — | — | — |
| Record a violation on the website | — | — | ✔ | ✔ | ✔ |
| Look students up | — | — | ✔ | ✔ | ✔ |
| See **who recorded** a violation | — | — | — | ✔ | ✔ |
| Edit / delete a violation | — | — | ✔ | ✔ | ✔ |
| Review proof photos | — | — | — | ✔ | ✔ |
| Print reports and slips | — | — | ✔ | ✔ | ✔ |
| Send the report to CHED | — | — | — | ✔ | ✔ |
| Manage staff accounts | — | — | — | ✔ | ✔ |
| Statistics and backups | — | — | — | ✔ | ✔ |
| **Edit / delete a student record** | — | — | — | — | ✔ |
| **Change the violation types list** | — | — | — | — | ✔ |
| **Edit or delete an Admin account** | — | — | — | — | ✔ |
| **Manage scanning + duty hours** | — | — | — | — | ✔ |
| **System settings** | — | — | — | — | ✔ |
| **Read the audit log** (incl. Recent Activity) | — | — | — | — | ✔ |
| **Clear the audit log** | — | — | — | — | ✔ |

Two deliberate limits worth knowing:

- **OSA cannot touch Admin accounts.** An OSA may manage every other staff
  account, but not an Admin's.
- **Only Admin changes the violation list.** Recording against the list is
  everyday work; deciding what *can* be recorded is a policy decision.

---

## 8. Rules the system enforces on its own

These apply no matter who is working:

- **One Major at a time.** A student who already has an unresolved Major
  violation cannot be given a second one.
- **The offence ladder.** Violations are numbered per student — Violation #1,
  #2, #3. A rejected proof review takes a violation out of the count and the
  ladder renumbers.
- **Two marshals maximum**, at once, system-wide.
- **Duty hours.** Monday–Saturday, 7:30 AM – 6:00 PM. A shift that starts
  inside the window ends when the window closes (plus a short grace so the
  marshal can finish syncing).
- **Duplicate scans are ignored.** A record that syncs live and then arrives
  again in a hand-over file is not counted twice.
- **A marshal cannot file under someone else's name.** The name stored is
  read from the staff record, never from what was typed on the phone.
