/* Guard/Marshal Scanner service worker — caches the scanner app shell so it
   opens instantly and works with zero connection (true offline mode).
   API calls (student data, sync, provisioning) are ALWAYS network — never
   served from cache — so a scan is either live-saved or safely queued,
   never silently stale.

   Everything the app needs is bundled next to it and precached below. An
   earlier version cached only the HTML and the manifest, on the basis that
   the QR library came from a CDN; it hasn't for a long time — the camera,
   Excel and bcrypt libraries are all local files. The result was that the
   first launch with no signal could come up with no camera and no export,
   which is the one thing the offline design exists to prevent.

   The install deliberately tolerates a missing file (addAll fails the whole
   install if any single request fails), so a trimmed copy of this folder
   still installs and simply caches less. */
const CACHE = 'vts-guard-scanner-v3';
const SHELL = [
  './',
  'spck_scanner.html',
  'scanner.webmanifest',
  'html5-qrcode.min.js',      // QR camera
  'xlsx.full.min.js',         // .xlsx read/write
  'bcrypt.min.js',            // offline hash checks
  'assets/icons/icon-192.png',
  'assets/icons/icon-512.png',
  'assets/icons/apple-touch-icon.png'
];

self.addEventListener('install', e => {
  e.waitUntil(
    caches.open(CACHE).then(c =>
      // Cache each item on its own so one missing file cannot abort the
      // install and leave the app with no offline cache at all.
      Promise.all(SHELL.map(url =>
        c.add(new Request(url, { cache: 'reload' })).catch(() => {})
      ))
    ).then(() => self.skipWaiting())
  );
});

self.addEventListener('activate', e => {
  e.waitUntil(
    caches.keys().then(keys =>
      Promise.all(keys.filter(k => k !== CACHE).map(k => caches.delete(k)))
    ).then(() => self.clients.claim())
  );
});

self.addEventListener('fetch', e => {
  if (e.request.method !== 'GET') return;
  // Never cache API calls — students/violations/sync must always be live
  // when reachable, and fall through to a real network error when not
  // (the page's own offline queue handles that, not the service worker).
  if (e.request.url.includes('/api/')) return;

  e.respondWith(
    fetch(e.request)
      .then(res => {
        const copy = res.clone();
        caches.open(CACHE).then(c => c.put(e.request, copy));
        return res;
      })
      .catch(() => caches.match(e.request).then(hit => hit || caches.match('spck_scanner.html')))
  );
});
