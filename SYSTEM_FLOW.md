# QR Shield — System Flow

**How the whole system fits together, and what makes it hybrid.**

The other three documents describe the system from a person's point of view.
This one describes it from the *system's* point of view: where it runs, how
the pieces talk to each other, and how a violation travels from a camera at
the gate to a report on the Commission on Higher Education's desk.

| Document | Answers |
|---|---|
| **USER_FLOW.md** | Who does what, and in what order |
| **USER_MANUAL.md** | How to operate it, per role |
| **FEATURES.md** | What exists, where it lives |
| **SYSTEM_FLOW.md** ← *this file* | How the parts connect, and the hybrid design |
| `WORKFLOW.md` | Developer notes on the scanner and counting rules |

---

## 1. What this system actually is

QR Shield is **not** a cloud application. It is a web application that runs on
**one laptop**, on campus, and serves everyone around it.

```
                         ┌─────────────────────────────┐
                         │   THE LAPTOP (XAMPP)        │
                         │                             │
                         │   Apache  ──►  PHP  ──►  MariaDB
                         │     │                   (svts)
                         └─────┼───────────────────────┘
                               │
          ┌────────────────────┼────────────────────┐
          │                    │                    │
    ┌─────▼─────┐      ┌───────▼───────┐    ┌──────▼───────┐
    │  OFFICE   │      │   MARSHALS'   │    │  STUDENTS'   │
    │ computers │      │    phones     │    │   phones     │
    │  (LAN)    │      │    (LAN)      │    │ (LAN or net) │
    └───────────┘      └───────────────┘    └──────────────┘
```

**The stack:** Apache 2.4 · PHP 8.0 · MariaDB 10.4, bundled as XAMPP on
Windows. Database `svts` (falling back to `student_violation_system` if that
is what the install has).

**This choice is deliberate.** A campus gate cannot depend on an internet
connection that may not be there. Everything essential works on the local
network alone.

---

## 2. The hybrid design — the heart of it

"Hybrid" here means **four independent things can each be present or absent**,
and the system keeps working in every combination.

| Layer | Present | Absent |
|---|---|---|
| **Internet** | Email sends; the site can be reached from off campus | Everything on campus still works; email is skipped and logged |
| **Local network** | Marshals' phones sync live to the laptop | Marshals scan offline and hand over a file |
| **The server** | Scans land in the database as they happen | Scans queue on the phone until it comes back |
| **The camera** | A proof photo is attached | The scan is still recorded, flagged *No proof attached* |

### The rule the whole design follows

> **A scan that really happened must reach the office.**
> Never block the gate to protect a nicety.

Every fallback in this system is an application of that one rule. Mail cannot
be sent? Sign in anyway, log the gap. No server? Queue it. No camera? Record
it without a photo and let the office see the gap.

### Online, offline, and the state in between

The critical insight is that **"has network" and "can reach the server" are
different questions**, and the second is the one that matters.

A phone on the laptop's own hotspot has *no internet* — a browser will often
call that "offline" — while the server sits one hop away and answers
instantly. So the scanner **never trusts the browser's opinion**; it asks the
server directly, with a timeout, and reports one of three states:

| State | Network? | Server reachable? | What happens to scans |
|---|:--:|:--:|---|
| **Connected** | yes | **yes** | Sent as they are made |
| **No server** | yes | no | Queued on the phone, sync themselves later |
| **Offline** | no | no | Queued on the phone, go out in the export |

Only the first is "online". The other two are safe, normal, and expected.

---

## 3. The three routes into the database

There is one destination and three roads to it. All three end at the same
function, so the rules cannot differ between them.

```
  ROUTE 1 — LIVE SYNC (online)
  phone ──HTTP POST──► api/scan_submit.php ──┐
                                             │
  ROUTE 2 — HAND-OVER FILE (offline)         ├──► import_scan_file()
  phone ──.vtsl on USB──► office imports ────┤         │
                                             │         ▼
  ROUTE 3 — OFFICE FORM (walk-in)            │   record_violation()
  admin/add_violation.php ───────────────────┘         │
                                                       ▼
                                                  violations table
```

| Route | When it is used | Speed |
|---|---|---|
| **Live sync** | The phone can reach the laptop | Seconds |
| **Hand-over file** | The gate had no network at all | End of shift |
| **Office form** | Reported at the counter, not the gate | Immediate |

**Why one function matters:** the one-Major rule, the offence ladder, the
duplicate guard and the audit entry are written once and apply to all three.
A violation recorded offline and imported an hour later is numbered and
checked exactly as a live one.

**The duplicate guard** is what makes the two scanner routes safe to combine.
It matches on the student, the violation and the time it was *scanned*, within
a 120-second window — so the same scan arriving twice by two different roads is
recognised as one. A marshal who syncs live *and* hands over the file at the
end of the shift is doing the right thing: the office imports the file as a
backup and nothing is counted twice.

This is also why the scanner records **when a scan happened**, not when it was
uploaded. Without that, a whole offline shift imported at 6 PM would land on
one timestamp, the offence order would follow file order instead of real order,
and the duplicate guard would have nothing to match on.

---

## 4. The life of one violation

```
 1. SCAN            Marshal points the camera at the student's QR code
                    │
 2. IDENTIFY        Phone looks the ID up in the roster it holds locally
                    │
 3. CHOOSE          Marshal picks the violation type
                    │
 4. PROOF           Camera takes a still by itself after a cooldown
                    │
 5. HOLD            Record saved on the phone, marked "queued"
                    │
        ┌───────────┴────────────┐
        │ reachable?             │ not reachable?
        ▼                        ▼
 6a. SYNC NOW              6b. WAIT, then sync by itself
     api/scan_submit.php       (retries every 20s, and on
        │                       every foreground return)
        └───────────┬────────────┘
                    │
 7. VERIFY          Server re-checks WHO is filing this:
                    · is the School ID an active Guard or Student?
                    · do they hold a valid, unexpired duty session?
                    · the stored name comes from the STAFF RECORD,
                      never from what the phone sent
                    │
 8. APPLY RULES     One-Major check · offence number · duplicate guard
                    │
 9. STORE           violations row + proof photo on disk
                    │
10. NOTIFY          Student is told; it appears on their dashboard
                    │
11. PROOF REVIEW    Office reads the photo against the reason
                    │
        ┌───────────┴───────────┐
    APPROVED                REJECTED
    stays on record         stops counting; ladder renumbers
        │                       │
        └───────────┬───────────┘
                    │
12. REPORT          Slips · parent notice · undertaking · CHED transfer
```

**Step 7 is the security boundary.** Everything before it happened on a device
the school does not control. Nothing the phone says about *who* is filing is
taken at face value.

---

## 5. Where the system thinks it is running

The same code runs on a laptop and on a live host, and it has to know which.
Getting this wrong is how a development machine ends up trying to use the
production database.

`config/env.php` decides, in this order:

1. **An explicit setting.** `APP_ENV=local` or `APP_ENV=live` in `.env` wins
   over everything.
2. **The hostname asked for** — `localhost`, `127.0.0.1`, `192.168.x`, `*.test`.
3. **The machine's own address** (`SERVER_ADDR`). This is the one that cannot
   be faked by a client.
4. **Command line** is never the live host.

**Why step 3 exists.** The decision used to be made from the hostname alone.
Reached through a tunnel, the public hostname matched none of the local
patterns, so a laptop running XAMPP concluded it was the live host and tried
to reach a remote database it had no business talking to. Every page answered
*"The service is temporarily unavailable."* Nothing was wrong with the
configuration — the app had simply decided it was somewhere else. A client can
ask for any hostname it likes; it cannot change which machine Apache is
running on.

---

## 6. The four ways in

| Route | Reaches | Needs internet? |
|---|---|---|
| **`localhost`** on the laptop | Everything | No |
| **`192.168.x.x/SAD`** from a phone or another PC | Everything | No — same Wi-Fi only |
| **A tunnel** (e.g. ngrok) | Everything, from anywhere | Yes |
| **The `phone_scanner/` folder** copied onto a phone | The scanner alone | No — no server either |

The last one is the true offline floor: a marshal with the folder on their
phone and a student list on a USB can work a whole shift with no laptop, no
Wi-Fi and no internet.

**HTTPS is not forced on the local network.** Every private range is exempt
from the redirect, because a laptop has no trusted certificate — forcing it
would bounce phones to a certificate error that looks exactly like a wrong IP
address.

---

## 7. How the system stays usable with no internet

Nothing on any page is fetched from the internet.

| Asset | Served from |
|---|---|
| Inter, Plus Jakarta Sans | `assets/vendor/fonts/` |
| Font Awesome icons | `assets/vendor/fontawesome/` |
| Chart.js | `assets/vendor/chart.umd.min.js` |
| QR camera, Excel, bcrypt | Bundled next to the scanner |

A render-blocking request to a font CDN does not fail quickly on a machine with
no route out — it hangs until it times out, so every page sat blank for
seconds before drawing. Self-hosting removes the question entirely.

**Two service workers** cache their apps so they open with no signal at all:
`scanner-sw.js` for the gate scanner, `sw_student.js` for the student portal.
Both deliberately **never cache API calls** — a scan must be either genuinely
saved or genuinely queued, never answered from a stale cache.

---

## 8. Time, and why it is pinned

Two clocks have to agree or the reports are wrong.

- PHP runs on `Asia/Manila` (set in `includes/functions.php`).
- The database connection sets `+08:00` on every connect.

Everything that depends on time reads the same one: the duty window
(Mon–Sat, 7:30 AM – 6:00 PM), session expiry, the offence ladder's ordering,
and every date on a printed report.

A phone's clock is **not** trusted. A scan carries the time it was taken, but a
stamp in the future is discarded rather than stored.

---

## 9. Data at rest

| Where | What |
|---|---|
| `svts` database | Accounts, students, roster, violations, notifications, audit log, settings |
| `uploads/` | Proof photos and evidence |
| `qr/` | Generated QR codes |
| `backups/` | `.sql` dumps, automatic and manual |
| The marshal's phone | Queued scans and their photos, until the office confirms the import |
| A `.vtsl` file | An encrypted shift export, in transit on a USB |

**The phone is a staging area, not a filing cabinet.** Records stay on it
until the office confirms — which is why *Finish session* exports rather than
deletes, and why the marshal is told to keep the records until the import is
confirmed.

---

## 10. What happens when each piece fails

| Failure | Effect | Recovery |
|---|---|---|
| **No internet** | Email is skipped; the gap is written to the audit log | Nothing breaks on campus |
| **Phone loses Wi-Fi** | Scans queue on the device | Sync themselves within 20s of the server returning |
| **Laptop asleep / Apache stopped** | Scanner reports *No server*; scans queue | Same |
| **Camera permission refused** | Scan is recorded without a photo | Shows as *No proof attached* for the office to chase |
| **Mail server unreachable** | Sign-in continues without a code, logged | Check Admin → Mail Test |
| **Marshal leaves a session open** | Their slot stays taken | Office signs them off from the sidebar |
| **Wrong hand-over file imported twice** | Nothing duplicates | The duplicate guard catches it |
| **Database lost** | Everything stops | Restore from `backups/` |

---

## 11. The chain of custody

The point of the whole system is that a violation can be traced from the gate
to the final report, and that each hop is recorded.

```
  MARSHAL              OSA STAFF            OSA / ADMIN              CHED
  records it     ──►   files it       ──►   decides it        ──►   receives it
     │                     │                     │                      │
  identity              cannot see           can see who            consolidated
  verified              the recorder         recorded it            report
  server-side                                                        
     │                     │                     │
     └─────────────────────┴─────────────────────┘
                           │
                    every hop written to the AUDIT LOG
```

**Who recorded a violation is restricted on purpose.** Only OSA and Admin can
see it. OSA Staff and students see the violation itself but never the name of
the person standing at the gate.

---

## 12. Setting it up on a new machine

1. Install XAMPP; start **Apache** and **MySQL**.
2. Put the project in `htdocs/SAD`.
3. Import `database/student_violation_system.sql`, then the `upgrade_*.sql`
   files in date order.
4. Set `DB_HOST` / `DB_PORT` in `.env` if they are not the defaults.
5. Fill in `config/mail.php` if email is wanted.
6. Open `http://localhost/SAD`.
7. **Import the enrolled-student roster** — until it exists, nobody can
   register.
8. Find the laptop's IPv4 address with `ipconfig` and give it to the marshals.

> The address **changes whenever the laptop rejoins Wi-Fi.** A stale address is
> by far the most common reason a scanner "will not connect".

Most schema changes apply themselves on first run — the app adds missing
columns as it finds them, so an older database catches up rather than breaking.
