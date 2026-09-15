# QR Shield — User Manual

**How to use the system, written for each kind of user.**

Find your role below and read only that section. For the bigger picture of
who does what, see **USER_FLOW.md**.

> **This is also in the app.** Every role has the same material on their own
> profile page — **My Profile** for staff, **Profile** for students — rendered
> for their role only, searchable and printable. It is built from
> `includes/user_manual.php`; keep the two in step when either changes.

- [A. Student](#a-student)
- [B. Guard / Marshal](#b-guard--marshal)
- [C. OSA Staff](#c-osa-staff)
- [D. OSA and Admin](#d-osa-and-admin)
- [E. Signing in — applies to everyone](#e-signing-in--applies-to-everyone)
- [F. When something goes wrong](#f-when-something-goes-wrong)

---

## A. Student

### A1. Registering

1. Open the site. On the front page, choose **Register**.
2. Type your **School ID** exactly as the school issued it.
   - If it is rejected, your ID is not yet on the enrolled list. Ask the
     Office of Student Affairs to add you — you cannot register without it.
3. Fill in your name, course, year, section, email and a password.
4. Submit. You are signed in straight away.
5. Check your email — your **QR code is attached**. Save it to your phone.

### A2. Getting your QR code again

Sign in and open **QR Code** in the menu. It is always there; you can show it
from your phone screen or print it. It never changes.

### A3. Seeing your violations

Open **Violations**. Each row shows what was recorded, when, and which number
it is on your record (Violation #1, #2, and so on).

Click a violation to open it in full, with the reason and any note from the
office.

> You will not see which marshal recorded it. That is deliberate — students
> are never shown the name of the person on the gate.

### A4. Your profile

**Profile** lets you correct your details and change your password. Keep your
email address current — it is where sign-in codes and notices are sent.

---

## B. Guard / Marshal

You work in the **scanner app**. It is built to keep working with no internet.

### B1. Setting up the device — first time only

Open the scanner. It asks how you want to set up:

**Option 1 — Import file (fully offline).**
Use this when there is no Wi-Fi at the gate.
1. Get the student list from the Head Marshal on a USB (a `.vtsl` or
   `Students.csv` file).
2. Tap **Import file** and choose it.
3. Done. No internet, no login.

**Option 2 — Connect online (live sync).**
Use this when the laptop running the system is on the same Wi-Fi.
1. Tap **Connect online**.
2. Type the laptop's address — for example `192.168.1.5`, or
   `192.168.1.5/SAD` if the site is in a folder.
3. Tap connect. The student list downloads and scans will sync as you make them.

### B2. Going on duty

Every shift starts here.

1. On the **Who is on duty?** screen, type your **School ID**.
2. Check the name that comes up is yours, and confirm.

**You may be refused. The message tells you which:**

| Message | Why | What to do |
|---|---|---|
| *"Two marshals are already on duty"* | Both slots are taken | Ask the office to sign one off, or wait for a shift to end |
| *"The gate is scanned Mon–Sat, 7:30 AM – 6:00 PM"* | Outside duty hours | Come back when it says it opens |
| *"Scanning is currently turned off by the office"* | The office paused scanning | Ask the office |
| *"That School ID does not match an active student or guard account"* | Wrong ID, or the account is inactive | Check the ID; ask the office if it is right |

### B3. Recording a violation

1. Tap **Scan** and point the camera at the student's QR code.
2. The student's name comes up. Check it is the right person.
3. Pick the violation from the list.
   - Choose **Others** if it is not listed, and type what happened.
4. **Hold the phone steady** — the camera takes a proof photo by itself after
   a short pause. Frame what you are recording.
5. The scan is saved.

### B4. Checking your scans went through

Each record in the list carries a badge:

| Badge | Meaning |
|---|---|
| **synced** | On the server. Done. |
| **queued** | On the phone, waiting. It will sync on its own. |
| **held** | The server would not take this one — it still goes out in the end-of-shift export |

The badge at the top-right of the screen tells you about the connection:

| Badge | Meaning |
|---|---|
| **Connected** (green) | Scans are syncing as you make them |
| **No server** (amber) | You have Wi-Fi but the system is not reachable. **Your scans are safe.** |
| **Offline** (grey) | No network. **Your scans are safe.** |

> **Nothing is ever lost.** A queued scan is on the phone and is in the
> end-of-shift file. Amber and grey are not errors.

### B4a. The phone threw me back to the sign-in screen mid-shift

The phone checks with the server every 20 seconds. If your shift has ended it
stops and returns to the sign-in screen with a red message saying which:

| The message says | What happened | What to do |
|---|---|---|
| *Scanning has been switched off by the office* | An Admin turned the whole feature off — every marshal was signed off, not just you | Nothing is wrong with your phone. Ask the office; sign on again when it is back on |
| *The office signed you off* | Someone ended your shift to free your slot | Ask the office before signing back on |
| *This account was opened on another scanner* | Your School ID was used on another phone | Only one phone per account |

> **Your scans are not lost.** They stay on the phone and still go out in the
> end-of-shift `.vtsl` file. Do not tap **Clear**. They will not sync while you
> are signed off — finish the session and hand the file over as normal.

### B5. Handing the phone to the next marshal

Tap **Switch marshal**. Any scans still waiting are sent first, so they are
filed under *you* and not the next person. Your duty slot is freed at once.

### B6. Ending your shift

Tap **Finish session**.

1. It writes an **encrypted `.vtsl` file** of everything you scanned.
2. It tells you how many records, and how many carry a proof photo.
3. Your duty slot is freed.

**Give the USB to the Head Marshal**, who imports it into the system.

> Keep the records on the phone until the office confirms the import. Do not
> tap **Clear** before then.

---

## C. OSA Staff

You have four things in the menu: **Dashboard**, **Students**,
**Violations & Reports**, and **My Profile**.

### C1. Looking a student up

**Students** → type a name, School ID or course in the search box → **Search**.

Click the eye icon on a row to open that student's full record, including
every violation.

### C2. Recording a violation from the office

Use this when a violation is reported in the office rather than at the gate.

1. **Violations & Reports** → **Add Violation**.
2. Find the student.
3. Pick the violation type and severity.
4. Attach evidence if you have it, and add a description.
5. Save.

> If the system refuses a **Major** violation, that student already has an
> unresolved Major one. Only one at a time is allowed.

### C3. Reading the violations list

**Violations & Reports** opens on the **Official Sheet** — one row per
student, in the layout the office submits. Switch to **Records** for the
per-violation list with edit and delete buttons.

Filter with the search box, the department picker, and the **Filters** panel
(date range, year level, course, section, violation type).

> You will not see which marshal recorded each violation. That is shown only
> to OSA and Admin.

### C4. Printing paperwork

From the violations list, open a student's record and choose the document:

| Document | When you use it |
|---|---|
| **ID Confiscation Slip** | The student's ID was taken at the gate |
| **Student Violation Slip** | The formal record of the violation |
| **Undertaking** | The student signs a promise after a violation |
| **Parent Notice** | The parent/guardian must be informed |
| **Violators List** | A printable list matching the on-screen table |

### C5. Exporting

The **Export** button offers an Excel copy of what is on screen, and a
scanner file for provisioning a marshal's phone.

---

## D. OSA and Admin

You share the same pages. A few things are **Admin only** and are marked below.

### D1. The dashboard

It is ordered by what needs you first:

1. **Recent Violations** — what has come in from the gate.
2. Charts: violations over time, by type, by department, by year level, by
   section, top violations, and the top repeat offenders.
3. **Recent Activity** — the last seven things anyone did. *(Admin only — it
   is a window onto the audit log, so it follows the same rule. An OSA
   dashboard ends at the charts.)*

### D2. Managing the gate — Scanner & duty — *Admin*

**Settings** in the menu. The **Scanner & duty** card is at the top of it.

> **Admin only.** Setting the gate up — switching scanning on and off, posting
> the hours, ending a shift — is Admin's. OSA work the gate but do not
> configure it, and Settings is not on an OSA menu.

**To see who is out there:** both marshal slots are listed with names and
School IDs. It refreshes by itself every 20 seconds — no need to reload.
An empty slot shows as *Slot 2 — free*.

**To pause scanning entirely:** flip the **Scanning** switch to Off.

This takes effect **immediately, including on marshals who are already
scanning**. Every live shift ends, each marshal is told why, and their phone
drops back to its sign-in screen within 20 seconds. The audit entry names who
was signed off.

> Scans already on those phones are safe, but they will **not** sync while
> scanning is off — they stay on the device and go out in the end-of-shift
> file. If you only want to stop *new* shifts from starting, change the duty
> hours instead.

**To change the duty days or hours:**
1. Tick the days you want.
2. Set the opening and closing times.
3. **Save duty hours.**

The system refuses a schedule with no days, or one that closes before it
opens — both would shut the gate permanently.

**To sign a marshal off:** click the exit icon on their row. Their slot frees
immediately, and they are notified. Use this when someone left a session open
and is blocking the next marshal. Scans already on their phone are not affected.

### D3. Checking proof photos

**Violation Proof** shows the photo attached to each violation, full size.
Use it to confirm a photo actually shows what the record claims. A record with
no photo is listed as *No proof attached* rather than hidden.

Each row carries a decision:

| Button | Effect |
|---|---|
| **Approve** | The photo supports the record. Nothing changes — it counted already |
| **Reject** | The photo does **not** support it. The row stays in history but stops counting |
| **Delete** | The record and its photo are erased. Nothing is left |

Approve and Reject are the whole judgement — there is no note to write, and
nothing you type on this page reaches the student. A decision is reversible:
**Clear** puts the record back to counting as normal.

**Delete is not reversible.** Use **Reject** when a record should stop counting
but stay on file; delete only when it should never have existed at all.

A **Major** cannot be rejected or deleted until you have recorded a conference
with the student. Use **Record conference** on the row. *(An **Admin** may
delete one without it — Admin holds full control of the records, and the
audit log names it when that happens. OSA cannot.)*

### D4. Managing students

**Students** has two tabs:

**Registered** — students who have accounts. Search, view, and *(Admin only)*
edit or delete.

**Enrolled Students** — the official roster that controls who is allowed to
register.
- **Import** a list at the start of a term (`.xlsx`, CSV, or pasted rows).
- **Unregister** an ID to let it be used again.
- **Clear all** — while the list is empty, registration validation is OFF.

### D5. Managing staff accounts

**Users** — add, edit, deactivate, delete staff accounts, and set their role.

> **OSA cannot edit or delete an Admin account.** Those rows are locked.

### D6. Sending the report to CHED

From **Violations & Reports**, filter to what the report should cover, then
choose the CHED transfer action. It sends the consolidated report to the
address configured in the mail settings.

### D7. Backups — *Admin*

**Backup** produces a full `.sql` dump of every table, and can restore one.

> Take a backup before importing a roster, before a term rollover, and before
> any bulk change. Restoring replaces live data.

### D8. Statistics and the audit log

**Statistics** — the charts, in full, with filters.

**Audit Log** — *Admin only.* Every significant action: who did it, to what,
when, and from which IP. Admin can also clear it — and a purge is itself
logged, so there is always a line naming who did it.

> The log reports on everyone with an account, OSA included, so the people it
> records are not the people who read it. The same rule covers the
> **Recent Activity** panel on the dashboard and the one on a user's record.

### D9. Settings — *Admin*

**Settings** holds the school details, the current term, the Google Drive
folders that export buttons open, and the **Scanner & duty** card (D2).
**Violation Types** is where the list of recordable violations and their
severities is maintained.

> All three of Settings, Violation Types and the Audit Log are **Admin only**,
> and none of them appear on an OSA menu. They are the system being configured
> or audited rather than operated.

---

## E. Signing in — applies to everyone

### E1. Where to sign in

- **Students** sign in from the front page.
- **Staff** (Guard, OSA Staff, OSA, Admin) reach their own sign-in through a
  hidden door — there is no visible "Staff login" link:

| Where you are | How to open it |
|---|---|
| Desktop | **Ctrl + Shift + S** |
| Desktop, search page | Type the access key on the page |
| **Phone or tablet** | **Tap the seal five times, or press and hold it**, then type the access key |

This only decides whether the staff sign-in card is *shown*. Every role still
signs in with a real password checked by the server.

### E2. The codes you may be asked for

The system may email you a **6-digit code**. There are two reasons, and the
email says which:

1. **New device code** — the browser you are using has not been seen on this
   account before. This applies to **every role**, students included. Whoever
   is already signed in elsewhere is *not* logged out; this only decides
   whether the new device may join. Once confirmed, that browser will not ask
   again.
2. **Staff sign-in code** — a code on *every* staff sign-in, not just on a new
   device. **This is currently switched off.** It is turned on by setting
   `STAFF_OTP_ENABLED` to `true` in `config/app.php`; when on it applies to
   Admin, OSA, OSA Staff and Guard.

Type the code to continue. It expires in 10 minutes, and **Send a new code**
issues a fresh one.

> **If you get a code you did not ask for, do not enter it.** It means someone
> else has your password or School ID. Change your password and tell the
> Office of Student Affairs.

### E3. Forgot your password

**Forgot password** on the sign-in card emails a reset code.

---

## F. When something goes wrong

### The scanner will not connect

Work down this list — it is in order of how often each one is the cause:

1. **Same Wi-Fi.** The phone and the laptop must be on one network. Mobile
   data will not reach it.
2. **Current IP.** Run `ipconfig` on the laptop and use the IPv4 address.
   **It changes whenever the laptop rejoins Wi-Fi** — a stale address is the
   most common cause by far.
3. **Include the folder.** If the site is not at the server root, add it:
   `192.168.1.5/SAD`.
4. **Apache running** in the XAMPP control panel.
5. **Windows Firewall** must allow Apache on private networks, or the phone's
   request is dropped with no error at all.

Cannot sort it now? Use **Import file**. The shift is not blocked by this.

### The scanner says "No server" but I have Wi-Fi

That is the honest answer, not a fault: you have a network, but this system is
not reachable on it. **Your scans are safe** — they are on the phone and will
sync by themselves when the server comes back.

### A marshal cannot go on duty

Check, in this order:
1. Is it inside **Mon–Sat, 7:30 AM – 6:00 PM**?
2. Is **Scanning** switched on? (Settings → Scanner & duty — *Admin*)
3. Are **both slots already taken**? Sign one off if so.
4. Is their account **Active**?

### A student cannot register

Their School ID is not on the **Enrolled Students** roster. Import the list,
or add that ID.

### A violation was refused as Major

That student already has an unresolved Major violation. Only one at a time is
allowed — resolve the first.

### Email is not arriving

**Admin → Mail Test** shows exactly which part of the mail setup is failing
and sends a live test message.

> Mail never blocks the system. If a code cannot be sent, the sign-in is
> allowed through and the gap is written to the **Audit Log** — so check there
> if you suspect codes are not going out.

### Someone left a session open and is blocking a slot

**Settings** → **Scanner & duty** → the exit icon on their row. *(Admin — if
you are OSA, an Admin has to do this one.)*
