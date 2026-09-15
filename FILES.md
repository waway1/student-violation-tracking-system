# What every file and folder is

A map of the project, so you can tell at a glance which file does what and
which of two similar-looking files is the one to edit.

---

## The short version

| I want to… | Go to |
|---|---|
| Change an Admin/OSA page | `admin/` |
| Change an OSA Staff page | `osa_staff/` |
| Change the phone scanner | **`spck_scanner.html` at the root**, then run `tools/sync-scanner.bat` |
| Put the scanner on a phone | copy the **`phone_scanner/`** folder |
| Change how something looks | `assets/css/` |
| Change shared PHP logic | `includes/functions.php` |
| Change Drive folders / mail / DB | `config/` |

---

## Folders

| Folder | What lives there |
|---|---|
| `admin/` | Pages for **Admin and OSA** — violations, students, users, backup, settings, statistics. |
| `osa_staff/` | The same jobs for **OSA Staff**, who have fewer powers (no delete, no user management). Many files mirror `admin/` by design. |
| `student/` | What a **student** sees: their own record, notifications, profile. |
| `api/` | JSON endpoints the phone scanner talks to. `scanner_data.php` (get students), `scan_submit.php` (send a scan), `provision.php`. |
| `auth/` | Login, session hardening, access guards. `session.php` sets the timeouts and the stolen-cookie check. |
| `includes/` | Shared PHP. **`functions.php` is the big one** — helpers used everywhere. Also `header.php` / `navbar.php` / `sidebar.php` / `footer.php`, which every page wraps itself in. |
| `config/` | Settings you actually edit: `database.php`, `mail.php`, `drive.php` (which Drive folder each export goes to). |
| `assets/` | `css/`, `js/`, `images/`, `icons/`, and `vendor/fontawesome/` (icons served locally so they work offline). `js/vts-ui.js` + `css/vts-forms.css` are the shared menu/validation behaviour every page loads. |
| `reports/` | The printable documents — violation slips, parent notices, the transfer form. |
| `database/` | `.sql` schema and upgrade scripts, in date order. Run them in order on a fresh install. |
| `phone_scanner/` | **A copy** of the scanner, packaged to drop onto a phone. See below. |
| `tools/` | Maintenance scripts, denied over HTTP. `sync-scanner.bat` refreshes `phone_scanner/`; `_retired/` holds files pulled out of the web root. |
| `backups/` | Where `.sql` / Excel backups are written. |
| `uploads/` | Evidence photos and profile pictures uploaded through the app. |
| `qr/` | QR code generation. |
| `vendor/` | Composer packages. Don't hand-edit. |

---

## The scanner exists twice — this is the bit that confuses people

```
spck_scanner.html          <-- THE MASTER. Edit this one.
                               Served by the website; Guards open it
                               from the sidebar.

phone_scanner/             <-- A COPY, packaged for a phone.
  spck_scanner.html            Do NOT hand-edit anything in here.
  html5-qrcode.min.js
  xlsx.full.min.js             Copy this whole folder to the phone and
  bcrypt.min.js                open THE FOLDER in SPCK Editor.
  scanner-sw.js
  scanner.webmanifest
  assets/icons/
  README.md                    <-- full phone setup instructions
```

**Edit the master, then run `tools/sync-scanner.bat`.** The two copies drifted
apart once — each ended up with fixes the other was missing, and there was no
way to tell which was newer. The sync script is what stops that happening
again.

The libraries (`html5-qrcode`, `xlsx`, `bcrypt`) sit at the root **and** in
`phone_scanner/` for the same reason: the scanner has to run with the phone
completely offline, so nothing may come from a CDN.

---

## Loose files at the root

| File | What it is |
|---|---|
| `index.php` | Landing page |
| `student_search.php` | The entry / login page (login was folded into it) |
| `register.php`, `verify.php`, `forgot_password.php`, `logout.php`, `account.php` | Account flows |
| `spck_scanner.html` | The scanner — master copy, see above |
| `scanner-sw.js`, `scanner.webmanifest` | Make the scanner installable + offline |
| `sw_student.js`, `student_manifest.json` | The same, for the student app |
| `scanner_app.php` | Redirect stub for old bookmarks |
| `PHPMailer.php` | Thin wrapper; the real mailer is `includes/mailer.php` |
| `*.md` | Changelogs and fix notes, newest last |

---

## Two things to deal with

> **Update:** the two files below have been **moved to `tools/_retired/`**
> (not deleted) and that folder is denied over HTTP. Delete the folder when
> you are sure you do not need them.

**`setup_passwords.php`** — a setup-time script that sets twelve seeded
accounts (including `admin` and `osa`) to `password123`. It needs no login. It
was still live in the web root, so anyone who loaded
`/setup_passwords.php` could take over the system.

It is now blocked two ways: a guard inside the file (off by default, and
localhost-only even when on) and a deny rule in `.htaccess`. **Once you're
sure you don't need it, delete the file** — that's what its own comment told
you to do after setup.

**`vts_scanner_list_2026-07-06.json`** — an exported student list (real names,
student numbers, sections) that was readable by anyone with the URL. Now
blocked in `.htaccess`, along with any other `vts_scanner_list_*.json` or
`Students*.csv` left at the root. Delete it when you no longer need it, and
keep exports out of the web root.

**`New folder/`** — was empty and unreferenced. Removed.

**`violations_only.php`** (in `admin/` and `osa_staff/`) — nothing in the app
links to either, and `violations.php` does the same job. Left in place, but
they are dead pages worth deleting once you have confirmed that.
