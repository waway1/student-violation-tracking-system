/* VTS Student service worker — makes the student portal installable and fast.
   App shell is cached (network-first, cache fallback) so it opens offline; the
   violation data itself always comes from the network when available. */
/* v3: the typefaces moved from fonts.googleapis.com into this server, so they
   are part of the offline shell now and are precached with the stylesheets.
   Bumping the name is what evicts the v2 cache, which still holds pages whose
   HTML points at the CDN. */
const CACHE = 'vts-student-v3';
const SHELL = [
  'student/dashboard.php',
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
  // Never cache API/notification polling — always fresh.
  if (e.request.url.includes('/api/') || e.request.url.includes('action=')) return;
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
