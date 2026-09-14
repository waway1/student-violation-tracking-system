/* =====================================================================
   LIVE VIOLATIONS — keep the list current without being asked.

   Scans sync in from the gate while this page sits open, so what is on
   screen quietly stops being true and nothing says so. This polls a
   numbers-only endpoint (count + newest id, for the SAME filters the page
   is showing) and reacts when they move.

   WHY IT DOES NOT JUST RELOAD ON A TIMER.
   Reloading a page somebody is reading loses their scroll position, closes
   the menu they just opened and wipes a half-typed filter. So a reload only
   happens when it can be done without taking anything away:

       tab visible, nothing focused, no menu open, and no interaction
       for a short while

   Any other time the page says so quietly and waits to be clicked. Coming
   back to a backgrounded tab always refreshes, because that is the moment
   the stale list is about to be read.

   Opt in per page with:
       <body data-live-violations="violations_pulse.php?course=BSIT" ...>
   or by setting window.VTS_LIVE = { pulse: '…', baseId: 114, baseCount: 7 }.
   ===================================================================== */
(function () {
  'use strict';

  var cfg = window.VTS_LIVE;
  if (!cfg || !cfg.pulse) return;

  var POLL_MS      = 15000;   // how often to ask
  var IDLE_MS      = 20000;   // "not touched for a while" before auto-reloading
  var baseId       = Number(cfg.baseId) || 0;
  var baseCount    = Number(cfg.baseCount) || 0;
  var lastTouch    = Date.now();
  var banner       = null;
  var pending      = 0;       // how many new rows we know about
  var timer        = null;
  var stopped      = false;

  ['mousedown', 'keydown', 'touchstart', 'scroll', 'input'].forEach(function (ev) {
    document.addEventListener(ev, function () { lastTouch = Date.now(); }, { passive: true });
  });

  /* Would a reload right now take something away from the person reading? */
  function safeToReload() {
    if (document.hidden) return false;
    if (Date.now() - lastTouch < IDLE_MS) return false;
    var a = document.activeElement;
    if (a && /^(INPUT|SELECT|TEXTAREA)$/.test(a.tagName)) return false;
    if (a && a.isContentEditable) return false;
    // An open dropdown or dialog means they are mid-task.
    if (document.querySelector('.vts-menu.open, .vts-menu-list.open, dialog[open], .modal.show')) return false;
    return true;
  }

  function reloadNow() {
    stopped = true;
    if (timer) clearInterval(timer);
    window.location.reload();
  }

  function showBanner(n) {
    if (!banner) {
      banner = document.createElement('button');
      banner.type = 'button';
      banner.className = 'vts-live-banner';
      banner.addEventListener('click', reloadNow);
      document.body.appendChild(banner);
    }
    banner.textContent = n + (n === 1 ? ' new record' : ' new records') + ' — click to show';
    banner.classList.add('show');
  }

  function onPulse(d) {
    if (!d || d.ok !== true) return;
    var newer = (Number(d.max_id) > baseId) || (Number(d.n) !== baseCount);
    if (!newer) return;

    /* Count is the honest number when rows were also deleted or edited; the
       id difference alone would under-report those. */
    pending = Math.max(1, Number(d.n) - baseCount);
    if (safeToReload()) { reloadNow(); return; }
    showBanner(pending);
  }

  function poll() {
    if (stopped) return;
    document.documentElement.setAttribute('aria-busy', 'true');
    fetch(cfg.pulse, { credentials: 'same-origin', cache: 'no-store' })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(onPulse)
      .catch(function () { /* a missed poll is not worth reporting */ })
      .then(function () { document.documentElement.setAttribute('aria-busy', 'false'); });
  }

  timer = setInterval(poll, POLL_MS);

  /* Coming back to the tab is exactly when the stale list is about to be
     read, so check immediately — and reload straight away if it moved. */
  document.addEventListener('visibilitychange', function () {
    if (document.hidden || stopped) return;
    lastTouch = 0;                       // returning counts as "not mid-task"
    poll();
  });

  /* Tidy up so a bfcache restore does not leave two pollers running. */
  window.addEventListener('pagehide', function () {
    stopped = true;
    if (timer) clearInterval(timer);
  });
})();
