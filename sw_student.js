/* VTS Student service worker — makes the student portal installable and fast.
   Only public static files are cached. A dashboard is personalized HTML and
   must always be fetched from PHP for the current authenticated student. */
/* v4 removes the old cache, which could contain a previous student's
   dashboard and display it after logout or to the next user of a device. */
const CACHE = 'vts-student-v4';
const SHELL = [
  'assets/css/vts-theme.css',
  'assets/css/vts-admin.css',
  'assets/css/vts-polish.css',
  'assets/css/vts-minimal.css',
  'assets/css/vts-dashboard.css',
  'assets/css/vts-clean.css',
  'assets/vendor/fonts/css/fonts.css',
  'assets/vendor/fonts/files/Inter-latin.woff2',
  'assets/vendor/fonts/files/PlusJakartaSans-latin.woff2',
  'assets/icons/icon-192.png',
  'assets/icons/icon-512.png'
];

self.addEventListener('install', e => {
  // Best-effort precache — never fail install if one asset 404s.
  e.waitUntil(caches.open(CACHE).then(c => Promise.allSettled(SHELL.map(u => c.add(u)))).then(() => self.skipWaiting()));
});

self.addEventListener('activate', e => {
  e.waitUntil(
    caches.keys().then(keys => Promise.all(keys.filter(k => k !== CACHE).map(k => caches.delete(k))))
      .then(() => self.clients.claim())
  );
});

self.addEventListener('fetch', e => {
  if (e.request.method !== 'GET') return;
  const url = new URL(e.request.url);
  // PHP pages and API responses can contain a user's account data. Let the
  // browser request them normally; only static same-origin assets can use the
  // offline cache.
  if (url.origin !== self.location.origin || url.pathname.endsWith('.php') ||
      url.pathname.includes('/api/') || url.searchParams.has('action')) return;
  e.respondWith(
    fetch(e.request).then(res => {
      const copy = res.clone();
      caches.open(CACHE).then(c => c.put(e.request, copy)).catch(() => {});
      return res;
    }).catch(() => caches.match(e.request))
  );
});

/* Allow the page to trigger a local notification (e.g. a 3-strike warning). */
self.addEventListener('message', e => {
  const d = e.data || {};
  if (d.type === 'notify' && self.registration.showNotification) {
    self.registration.showNotification(d.title || 'QR Shield', {
      body: d.body || '',
      icon: 'assets/icons/icon-192.png',
      badge: 'assets/icons/icon-192.png',
      tag: d.tag || 'vts-warning'
    });
  }
});
