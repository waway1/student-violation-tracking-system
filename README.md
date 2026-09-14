# QR Shield

**QR Shield: A QR Code-Based Student Violation Tracking System for Promoting
Safe and Inclusive Learning at Golden West Colleges, Inc.
Supporting SDG 4 – Quality Education**

Short name **QR Shield** is what the interface uses — a navbar, a browser tab
and a phone home screen cannot carry a 169-character title. The full title
above is stated on the landing page and here; `config/app.php` defines every
form in one place, so renaming the system is one edit rather than thirty.

| Constant | Value | Used by |
|---|---|---|
| `APP_NAME` | QR Shield | navbar, PWA short name, e-mail sender, report footers |
| `APP_TAGLINE` | Student Violation Tracking System | the line under the name |
| `APP_NAME_FULL` | QR Shield — Student Violation Tracking System | browser tabs |
| `APP_TITLE_FULL` | the complete title above | landing page, documents |

---

## Staff sign-in (there is no "Staff login" link)

Students get the whole front of the site. Staff reach their own login through
a hidden door on the landing page (`index.php`) and the search page
(`student_search.php`). The access key is **`gwcstaff`**.

| Where you are | How to open it |
|---|---|
| Desktop, either page | **Ctrl+Shift+S** |
| Desktop, search page | Type `gwcstaff` — in the search box, or just on the page |
| **Phone or tablet, either page** | **Tap the seal five times, or press and hold it**, then type `gwcstaff` into the pad that appears |

The phone route exists because neither desktop route works on a touchscreen.
There is no Ctrl key, and a mobile keyboard will not let `gwcstaff` through an
ordinary text field untouched — iOS capitalises the first letter, Android's
suggestion strip swaps the word for a real one, so the exact match the page
was waiting for never arrived. The pad's field is a **password** field, which
is the one input every mobile keyboard leaves alone: no autocorrect, no
capitalisation, and the code is not remembered in the suggestion strip
afterwards.

Five wrong keys in a row quiet the pad for 30 seconds.

**This is a doorway, not a lock.** The key lives in the page, exactly as the
desktop shortcut always has, and all it decides is whether the login *card* is
shown. Every role still signs in with a real password checked on the server.
Change it in one place per page — the `data-key` attribute on the
`assets/js/staff-key.js` script tag — and in `SECRET_CODE` in
`student_search.php` for the desktop typing route.

---

> **Note:** everything below this line documents the **phone scanner** only.
> It is a byte-for-byte copy of `phone_scanner/README.md` that predates this
> change, not a description of the whole system.

# VTS Scanner — the copy you put on a phone

This folder is the **whole scanner app, ready to copy onto a phone**. It has
no website in it on purpose — a marshal on duty needs the scanner, not the
admin system.

> **Don't edit files in here.** This folder is a *copy*. The master lives in
> the project root (`..\spck_scanner.html`). Edit that, then run
> `..\tools\sync-scanner.bat` to refresh this folder. The two copies drifted
> apart once and each ended up with fixes the other was missing.

---

## What's in here

| File | What it is |
|---|---|
| `spck_scanner.html` | **The app. Open this one.** |
| `scanner.webmanifest` | Lets it install to the home screen with a proper icon |
| `scanner-sw.js` | Caches the app so it opens with no signal |
| `html5-qrcode.min.js` | QR camera |
| `xlsx.full.min.js` | Reads/writes the Excel files |
| `bcrypt.min.js` | Offline hash checks |
| `assets/icons/` | Home-screen icons |

Everything is bundled — **no internet needed** once the folder is on the
phone. Keep the folder structure exactly as-is; `spck_scanner.html` expects
the other files next to it at these paths.

---

## Putting it on the phone (SPCK Editor)

1. Copy this **whole folder** to the phone (USB, cloud drive, however you
   normally move files).
2. In SPCK Editor, open the **folder** — not just the single HTML file. If you
   open only the file, it can't see the libraries sitting next to it and the
   camera won't start.
3. Open `spck_scanner.html` and use SPCK's **Run / Preview** (the local web
   server option, not a plain file view).

That third step matters. Run/Preview gives the page a real
`http://localhost:…` address. A bare `file://` page is blocked by phone
browsers from using the camera and from making network requests — so on
`file://` the scanner looks broken for reasons that have nothing to do with
the scanner.

---

## The two ways to set it up

On first open you pick one. Both record scans the same way.

**Import file — fully offline.**
The Head Marshal gives you the student list (`.vtsl`, or `Students.csv`) on a
USB. Choose it, and you're set. No internet, no login. At the end of the shift
**Finish session** writes an encrypted `.vtsl` of everything you scanned —
hand that back to the Head Marshal.

**Connect online — live sync.**
Needs the laptop running XAMPP on the **same Wi-Fi**. Enter its address
(e.g. `192.168.1.5`, or `192.168.1.5/SAD` if the site isn't at the server
root). Scans go to the server as you make them, *and* still export as a
`.vtsl` at the end as a backup.

### If "Connect online" won't connect

The scanner now tells you which of these failed, but in order of likelihood:

1. **Same Wi-Fi.** Phone and laptop must be on one network. Mobile data won't
   reach it.
2. **Current IP.** On the laptop run `ipconfig` and use the IPv4 address. It
   changes whenever the laptop rejoins Wi-Fi — a stale IP is the most common
   cause.
3. **Include the folder.** If the site isn't at the server root, add it:
   `192.168.1.5/SAD`.
4. **Apache running** in the XAMPP control panel.
5. **Windows Firewall** must allow Apache on private networks, or the phone's
   request is dropped with no error at all.

Can't sort it right now? Use **Import file**. The shift isn't blocked by this.

---

## What the connection pill means

Top-right of the screen, and it now reports three states rather than two:

| Pill | Meaning |
|---|---|
| **Connected** (green) | The server answered. Scans are syncing. |
| **No server** (amber) | You have Wi-Fi, but this system isn't reachable. Scans are safe on the phone and will sync by themselves. |
| **Offline** (grey) | No network. Scans are saved here and go out in the end-of-shift export. |

The amber state used to be reported as "Online", which meant a marshal could
finish a shift believing every scan had synced when not one had left the
phone. **Queued scans are never lost** — they're on the phone and they're in
the `.vtsl` either way.

---

## The Back arrow

It's hidden here. It exits the scanner back into the website, which only
exists when the scanner is served by the site — in this standalone folder
there's nowhere to go, so it isn't shown rather than left there to fail.
