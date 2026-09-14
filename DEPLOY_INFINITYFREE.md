# Deploying to InfinityFree

Live site: <https://qrshield-studentviolationtrackingsystem.site.je/>
Code: <https://github.com/wawa1/student-violation-tracking-system>

---

## Read this first: what "database on InfinityFree" can and cannot mean

InfinityFree's free plan **blocks MySQL connections from outside its own
servers.** There is no remote-access whitelist on the free tier.

That rules out the arrangement it is tempting to assume:

| Arrangement | Works? |
|---|---|
| Files on InfinityFree + DB on InfinityFree | **Yes** — this is the deploy |
| Files on XAMPP (localhost) + DB on InfinityFree | **No** — the connection is refused before it reaches MySQL |
| Files on XAMPP + local `svts` DB | **Yes** — unchanged, this is still how you develop |

So the PHP has to run *on InfinityFree*, next to the database. GitHub holds
the code; it does not serve it. Nothing here changes local development —
XAMPP keeps using the local `svts` database exactly as before.

---

## The five steps

### 1. Pick PHP 8.0 or newer

Control panel → **PHP Config** (or *Select PHP Version*) → **8.1** or **8.2**.

`vendor/` contains PhpSpreadsheet 2.x, which requires PHP ≥ 8.0 and will
fatal on 7.4. Also confirm `gd`, `mbstring`, `zip` and `intl` are enabled —
they are on by default, and the Excel import/QR code paths need them.

### 2. Import the database

Control panel → **phpMyAdmin** → sign in to the `if0_42480644_svts` database.

1. **Select `if0_42480644_svts` in the left sidebar first.** Importing with
   no database selected is the usual cause of *"No database selected"*.
2. **Import → Choose File →** `database/infinityfree_import.sql` **→ Go**

Use that file, not `database/student_violation_system.sql`.

**About the views.** A stock dump of the local database contains seven
`<course>_students` views. InfinityFree does **not** grant `CREATE VIEW` to a
free account, so importing any dump that contains them stops dead with:

```
#1142 - CREATE VIEW command denied to user 'if0_42480644'@'...'
```

This is a withheld privilege, not a syntax problem — no rewriting of the
statement gets around it. So `infinityfree_import.sql` simply does not
contain them. That is safe: they were added by `upgrade_2026-07.sql` and
**nothing in the application queries them** — zero references across every
`.php` file, and no code builds the name dynamically. Each was only
"students on one course, with their violation count", which the app works
out inline where it needs it. Your local XAMPP database keeps them.

**Re-running the import is safe.** Every table is preceded by
`DROP TABLE IF EXISTS`, so if a previous attempt died partway (on the views,
say) you can just import again over the top.

You should end up with **19 tables and no views**, including `users`
(12 rows), `students` (15), `violation_types` (54) and `student_roster` (52).

### 3. Upload the files to `htdocs/`

`.github/workflows/deploy.yml` does this for you on every push to `master`.
InfinityFree has no git and no SSH on the free plan, so the server cannot
pull from GitHub — the Action pushes to it over FTP instead.

**One-time setup.** GitHub repo → **Settings → Secrets and variables →
Actions → New repository secret**, three times:

| Secret | Value |
|---|---|
| `FTP_SERVER` | `ftpupload.net` |
| `FTP_USERNAME` | `if0_42480644` |
| `FTP_PASSWORD` | your InfinityFree **account** password |

`FTP_PASSWORD` is the hosting password from the control panel's **FTP
Accounts** page — *not* the MySQL password. The two are different
credentials that happen to share the `if0_42480644` username.

Then push, or run it by hand from the **Actions** tab. The first run uploads
everything (`vendor/` alone is ~800 files, so give it a few minutes); after
that it keeps a manifest on the server and uploads only what changed.

**What the Action will never touch.** It deletes server files that are
absent from the repo, which on a naive setup would wipe your uploaded
evidence images on the first deploy. These are excluded, so they are neither
uploaded nor deleted:

```
.env                        the live password
config/db_credentials.php   the fallback credentials
uploads/evidence/**         students' uploaded evidence
uploads/profile/**          profile pictures
backups/**                  autosaved DB dumps
```

**If you would rather upload by hand,** FileZilla to `ftpupload.net`, and put
the *contents* of the project in `htdocs/` — not in `htdocs/SAD/`:

```
htdocs/
├── index.php
├── .htaccess
├── .user.ini
├── config/
├── vendor/        <-- must be uploaded; InfinityFree has no Composer
└── ...
```

Put it in a subfolder and every URL gains `/SAD/`. (The app copes either way
— `base_path()` works it out — but the clean root is what the domain is for.)

### 4. Put `.env` on the server

`.env` is gitignored, so it is **not** in the GitHub checkout and the deploy
Action skips it. You place it on the server once, by hand; no redeploy will
overwrite it.

It is the **same file** as the one in your project folder — one `.env` serves
both machines. It carries two blocks:

```
DB_HOST=127.0.0.1              <- used on XAMPP
DB_NAME=svts
DB_USER=root
DB_PASS=

LIVE_DB_HOST=sql206.infinityfree.com    <- used on InfinityFree
LIVE_DB_NAME=if0_42480644_svts
LIVE_DB_USER=if0_42480644
LIVE_DB_PASS=<your MySQL password>
```

`config/env.php` works out which machine it is on and reads only the
matching set, so the file needs no editing when you deploy. Upload it as-is,
or paste the contents into a new `htdocs/.env` in the File Manager.

**Do not add `APP_ENV` to it.** Leaving it out is exactly what lets the app
decide for itself. Setting it pins BOTH machines to the same mode, and one
of them is then wrong — `APP_ENV=live` on your laptop makes XAMPP try to
reach InfinityFree's MySQL, which cannot work (see below).

**Why the two blocks exist at all.** InfinityFree's free plan does not accept
MySQL connections from outside its own network — `sql206.infinityfree.com`
has no public DNS record, so it does not even resolve from a home
connection. There is no arrangement where XAMPP talks to the live database.
Local development therefore keeps its own local `svts`, and a single
`DB_HOST` could not name both servers at once.

The root `.htaccess` refuses to serve any file whose name starts with a dot,
so `.env` is not readable over the web. (Verified: it answers 403.)

### 5. Load the site

<https://qrshield-studentviolationtrackingsystem.site.je/>

Sign in, then walk one violation end to end: **Login → Dashboard → Students →
Violations → Add violation → View violation.** That exercises a read, a
write and a join, which is what actually proves the database is wired up.

---

## If something goes wrong

| Symptom | Cause |
|---|---|
| *"The service is not configured yet."* | `.env` is missing, or `DB_PASS` is still the placeholder. The error log names the exact setting. |
| *"The service is temporarily unavailable."* | Credentials were found but MySQL refused them — wrong password, or the DB name is not `if0_42480644_svts`. Check the error log. |
| **500 on every page** | Almost always a `php_flag`/`php_value` line in a `.htaccess`. InfinityFree runs PHP as FastCGI and treats those as a fatal config error. The ones in this repo are `<IfModule>`-guarded for exactly that reason — if you add more, guard them too, or put them in `.user.ini`. |
| Blank page / fatal on an Excel import | PHP is set to 7.4. PhpSpreadsheet needs 8.0+. |
| `#1142 CREATE VIEW command denied` | You imported a dump that still contains the seven views. InfinityFree does not grant CREATE VIEW at all. Use `database/infinityfree_import.sql`, which has none. Re-importing over a partial import is safe. |
| *"No database selected"* on import | You did not click the database in phpMyAdmin's sidebar before importing. |
| Action fails "530 Login incorrect" | `FTP_PASSWORD` is the MySQL password. It needs the hosting ACCOUNT password from the control panel's FTP Accounts page. |
| Action succeeds but the site is unchanged | InfinityFree caches aggressively; hard-refresh. Also check `server-dir` is `/htdocs/`. |
| Uploaded evidence images vanished | Something removed the `uploads/**` excludes from `deploy.yml`. Those lines are what stop the sync deleting server-only files. |
| Emailed links point at the wrong host | Set `APP_URL=https://qrshield-studentviolationtrackingsystem.site.je` in `.env`. |
| Site loads but images/uploads 404 | `uploads/evidence/` is gitignored, so it is empty on a fresh deploy. The app recreates the folders on first write; the old files are not carried over. |

Errors are logged, never shown — that is `display_errors Off`. Read them in
the control panel's **Error Logs**, or via the file manager.

---

## Rotate the passwords

Two separate things to fix, both in the control panel:

1. **The old account.** `config/db_credentials.php` previously held
   credentials for a *different* account (`if0_42336276` on `sql300`), and
   that password was pasted into chat more than once. If the account still
   exists, delete it or change its password.

2. **The current one.** The `if0_42480644` MySQL password was shared in chat
   to get this wired up, so it should be treated as known and rotated once
   the site is confirmed working. It lives in exactly two places, both
   gitignored and neither on GitHub:

   - `htdocs/.env` on the server — the one that matters
   - `config/db_credentials.php` on your machine — the local fallback

   Change it in the control panel, then update those two. Nothing else
   references it, and no redeploy will overwrite either.
