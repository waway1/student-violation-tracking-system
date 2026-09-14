<!-- Shared page footer: closes the app shell and loads shared JS (sidebar toggle, QR card download). -->
<?php
/* Same default header.php uses. The footer is also reached without the header
   (admin/endpage.php closes the shell on its own), and an undefined
   $assetBase there does not just warn -- it emits a RELATIVE script src, so
   vts-ui.js 404s and every menu on the page silently stops working. */
if (!isset($assetBase)) $assetBase = '../';
?>
</div><!-- .vts-main-inner -->
</main><!-- .vts-main -->

<script>
/* Desktop: Menu toggles FULL sidebar <-> ICONS-ONLY rail (never hides it, so
   nothing is ever covered). Mobile: slides the sidebar in/out as an overlay.
   The choice is remembered between pages. */
function toggleSidebar() {
  const sidebar = document.getElementById('vtsSidebar');
  const main = document.querySelector('.vts-main');
  if (!sidebar) return;

  if (window.innerWidth > 900) {
    const mini = sidebar.classList.toggle('mini');
    if (main) main.classList.toggle('mini', mini);
    try { localStorage.setItem('vtsSidebarMini', mini ? '1' : '0'); } catch (e) {}
  } else {
    sidebar.classList.toggle('open');
  }
  syncMenuTab();
}

/* Restore the icons-only choice on every page load. */
(function restoreSidebarMode(){
  try {
    if (localStorage.getItem('vtsSidebarMini') !== '1') return;
    if (window.innerWidth <= 900) return;
    const sidebar = document.getElementById('vtsSidebar');
    const main = document.querySelector('.vts-main');
    if (sidebar) sidebar.classList.add('mini');
    if (main) main.classList.add('mini');
  } catch (e) {}
})();

// The Menu pill centers itself over the sidebar whenever the menu is
// visible (body.sb-shown drives the CSS), and returns to the corner
// when the menu is hidden.
function syncMenuTab() {
  const sidebar = document.getElementById('vtsSidebar');
  if (!sidebar) return;
  const shown = window.innerWidth > 900
    ? !sidebar.classList.contains('mini')
    : sidebar.classList.contains('open');
  document.body.classList.toggle('sb-shown', shown);
  // The navbar's Menu button is the only one a phone can reach, so its
  // state has to follow the drawer however the drawer was closed --
  // including by a tap on the page behind it.
  const navBtn = document.getElementById('navMenuBtn');
  if (navBtn) navBtn.setAttribute('aria-expanded', shown ? 'true' : 'false');
}
syncMenuTab();
window.addEventListener('resize', syncMenuTab);

// On mobile, tapping outside the sidebar closes it
document.addEventListener('click', function(e) {
  if (window.innerWidth > 900) return;
  const sidebar = document.getElementById('vtsSidebar');
  const btn = document.getElementById('hamburgerBtn');
  // The navbar's Menu button sits OUTSIDE the sidebar, so without this it
  // counted as an outside click: the drawer opened on the button's own
  // handler and this listener shut it again on the same tap.
  const navBtn = document.getElementById('navMenuBtn');
  if (sidebar && sidebar.classList.contains('open') &&
      !sidebar.contains(e.target) &&
      !(btn && btn.contains(e.target)) &&
      !(navBtn && navBtn.contains(e.target))) {
    sidebar.classList.remove('open');
    syncMenuTab();
  }
});

// Auto-UPPERCASE while typing for data-entry fields marked .js-upper
// (School IDs, names on registration/add forms). Keeps the caret in place.
document.addEventListener('input', function(e) {
  const el = e.target;
  if (!el.classList || !el.classList.contains('js-upper')) return;
  const s = el.selectionStart, en = el.selectionEnd;
  const up = el.value.toUpperCase();
  if (up !== el.value) {
    el.value = up;
    try { el.setSelectionRange(s, en); } catch (err) {}
  }
});

// Digits-only fields (.js-digits) — School IDs are numbers, so anything else
// is stripped as it is typed/pasted. maxlength on the input caps the length.
document.addEventListener('input', function(e) {
  const el = e.target;
  if (!el.classList || !el.classList.contains('js-digits')) return;
  const s = el.selectionStart;
  const clean = el.value.replace(/\D+/g, '');
  if (clean !== el.value) {
    el.value = clean;
    try { el.setSelectionRange(Math.max(0, s - 1), Math.max(0, s - 1)); } catch (err) {}
  }
});

// QR download — composites the QR image onto a branded ID-card canvas
// (navy/gold theme, logo, name/ID/course) so the downloaded PNG matches
// the site's look instead of being a bare QR square.
function downloadQrCard(imgSrc, filename, info) {
  if (!imgSrc || imgSrc === '#') return;
  var NAVY = '#1a3a6b', NAVY2 = '#2a5298', GOLD = '#c9a227', TEXT = '#1a2340',
      MUTED = '#5a6a8a', BORDER = '#dde4f0';

  var qrImg = new Image();
  var logoImg = new Image();
  var logoOk = false, pending = 2;
  function ready() { if (--pending === 0) draw(); }
  qrImg.onload = ready;
  qrImg.onerror = ready;                         // never hang if the QR image 404s
  logoImg.onload = function () { logoOk = true; ready(); };
  logoImg.onerror = ready;

  function roundRect(ctx, x, y, w, h, r) {
    ctx.beginPath();
    ctx.moveTo(x + r, y);
    ctx.arcTo(x + w, y, x + w, y + h, r);
    ctx.arcTo(x + w, y + h, x, y + h, r);
    ctx.arcTo(x, y + h, x, y, r);
    ctx.arcTo(x, y, x + w, y, r);
    ctx.closePath();
  }

  function draw() {
    if (!qrImg.naturalWidth) {
      var direct = document.createElement('a');
      direct.href = imgSrc;
      direct.download = filename;
      document.body.appendChild(direct);
      direct.click();
      direct.remove();
      return;
    }
    var qrSize = qrImg.naturalWidth || qrImg.width;
    var boxPad = 14, pad = 22, headerH = 76, footerH = 12;
    var qrBoxSize = qrSize + boxPad * 2;
    var textTop = headerH + pad + qrBoxSize + 28;
    var cardH = textTop + 40 + footerH;

    // Measure the three text lines BEFORE fixing the card width, so a long
    // name or course never gets clipped off — the card widens to fit
    // whichever is bigger, the QR box or the longest line of text.
    var measure = document.createElement('canvas').getContext('2d');
    measure.font = '800 16px Arial, sans-serif';
    var nameW = measure.measureText(info.name).width;
    measure.font = '600 12px Arial, sans-serif';
    var idW = measure.measureText('ID: ' + info.id).width;
    measure.font = '700 12px Arial, sans-serif';
    var courseW = measure.measureText(info.course).width;
    var maxTextW = Math.max(nameW, idW, courseW);

    var cardW = Math.max(qrBoxSize + pad * 2, maxTextW + pad * 2);

    var canvas = document.createElement('canvas');
    canvas.width = cardW;
    canvas.height = cardH;
    var ctx = canvas.getContext('2d');

    // Card background + drop shadow, clipped to rounded corners
    ctx.save();
    roundRect(ctx, 0, 0, cardW, cardH, 16);
    ctx.shadowColor = 'rgba(26,58,107,0.25)';
    ctx.shadowBlur = 16;
    ctx.shadowOffsetY = 5;
    ctx.fillStyle = '#ffffff';
    ctx.fill();
    ctx.restore();
    roundRect(ctx, 0, 0, cardW, cardH, 16);
    ctx.clip();

    // Header — institutional navy-to-gold gradient, same as the site header
    var grad = ctx.createLinearGradient(0, 0, cardW, 0);
    grad.addColorStop(0, NAVY);
    grad.addColorStop(0.55, NAVY2);
    grad.addColorStop(1, GOLD);
    ctx.fillStyle = grad;
    ctx.fillRect(0, 0, cardW, headerH);

    var textStartX = pad;
    if (logoOk) {
      var logoSize = 34;
      ctx.save();
      ctx.beginPath();
      ctx.arc(pad + logoSize / 2, headerH / 2, logoSize / 2, 0, Math.PI * 2);
      ctx.closePath();
      ctx.clip();
      ctx.drawImage(logoImg, pad, headerH / 2 - logoSize / 2, logoSize, logoSize);
      ctx.restore();
      textStartX = pad + logoSize + 10;
    }

    ctx.fillStyle = '#ffffff';
    ctx.textAlign = 'left';
    ctx.font = '700 9px Arial, sans-serif';
    ctx.fillText('GOLDEN WEST COLLEGES', textStartX, headerH / 2 - 14);
    ctx.font = '800 15px Arial, sans-serif';
    ctx.fillText('Student QR ID', textStartX, headerH / 2 + 3);
    ctx.font = '600 9px Arial, sans-serif';
    ctx.fillText('QR Shield', textStartX, headerH / 2 + 18);

    // QR code, sat in a white rounded box with its own soft shadow —
    // centered horizontally in case the card widened to fit the text below.
    var qrBoxX = (cardW - qrBoxSize) / 2, qrBoxY = headerH + pad;
    ctx.save();
    ctx.shadowColor = 'rgba(26,58,107,0.12)';
    ctx.shadowBlur = 8;
    roundRect(ctx, qrBoxX, qrBoxY, qrBoxSize, qrBoxSize, 12);
    ctx.fillStyle = '#ffffff';
    ctx.fill();
    ctx.restore();
    ctx.strokeStyle = BORDER;
    ctx.lineWidth = 1;
    roundRect(ctx, qrBoxX, qrBoxY, qrBoxSize, qrBoxSize, 12);
    ctx.stroke();
    ctx.drawImage(qrImg, qrBoxX + boxPad, qrBoxY + boxPad, qrSize, qrSize);

    // Name / ID / Course
    ctx.textAlign = 'center';
    ctx.fillStyle = TEXT;
    ctx.font = '800 16px Arial, sans-serif';
    ctx.fillText(info.name, cardW / 2, textTop);
    ctx.fillStyle = MUTED;
    ctx.font = '600 12px Arial, sans-serif';
    ctx.fillText('ID: ' + info.id, cardW / 2, textTop + 20);
    ctx.fillStyle = NAVY;
    ctx.font = '700 12px Arial, sans-serif';
    ctx.fillText(info.course, cardW / 2, textTop + 40);

    // Footer accent bar
    ctx.fillStyle = GOLD;
    ctx.fillRect(0, cardH - footerH, cardW, footerH);

    canvas.toBlob(function (blob) {
      var a = document.createElement('a');
      a.href = URL.createObjectURL(blob);
      a.download = filename;
      document.body.appendChild(a);
      a.click();
      a.remove();
      URL.revokeObjectURL(a.href);
    }, 'image/png');
  }

  qrImg.src = imgSrc;
  logoImg.src = info.logo;
}

document.querySelectorAll('.qr-download-btn').forEach(function (btn) {
  btn.addEventListener('click', function (e) {
    e.preventDefault();
    var d = btn.dataset;
    downloadQrCard(d.src, d.filename, { name: d.name, id: d.id, course: d.course, logo: d.logo });
  });
});

// --- Offline-safe form queue for add / edit / import actions ---
(function(){
  const OFFLINE_QUEUE_KEY = 'vts_offline_form_queue';

  function readQueue(){
    try {
      return JSON.parse(localStorage.getItem(OFFLINE_QUEUE_KEY) || '[]');
    } catch (e) {
      return [];
    }
  }

  function writeQueue(queue){
    try { localStorage.setItem(OFFLINE_QUEUE_KEY, JSON.stringify(queue)); }
    catch (e) {}
  }

  function fileToDataUrl(file){
    return new Promise(function(resolve){
      if (!file) return resolve('');
      const reader = new FileReader();
      reader.onloadend = function(){ resolve(reader.result || ''); };
      reader.onerror = function(){ resolve(''); };
      reader.readAsDataURL(file);
    });
  }

  function dataUrlToBlob(dataUrl, type){
    if (!dataUrl) return new Blob();
    const parts = dataUrl.split(',');
    const mime = (type || parts[0].match(/:(.*?);/)?.[1] || 'application/octet-stream');
    const b64 = parts.length > 1 ? parts[1] : '';
    const binary = atob(b64);
    const u8 = new Uint8Array(binary.length);
    for (let i = 0; i < binary.length; i++) u8[i] = binary.charCodeAt(i);
    return new Blob([u8], { type: mime });
  }

  async function formToQueuedPayload(form){
    const payload = {
      url: form.action || window.location.href,
      method: (form.method || 'POST').toUpperCase(),
      fields: [],
      files: []
    };

    const fd = new FormData(form);
    for (const [name, value] of fd.entries()) {
      if (value instanceof File) {
        const dataUrl = await fileToDataUrl(value);
        payload.files.push({
          name: name,
          filename: value.name || 'upload',
          type: value.type || 'application/octet-stream',
          size: value.size || 0,
          dataUrl: dataUrl
        });
      } else {
        payload.fields.push({ name: name, value: String(value) });
      }
    }

    return payload;
  }

  async function queueOfflineForm(form){
    if (!form || form.dataset.vtsQueued === '1') return;
    const payload = await formToQueuedPayload(form);
    const queue = readQueue();
    queue.push({
      id: Date.now() + '-' + Math.random().toString(16).slice(2),
      createdAt: new Date().toISOString(),
      payload: payload
    });
    writeQueue(queue);
    form.dataset.vtsQueued = '1';
    alert('You are offline. Changes were saved locally and will sync automatically when the connection returns.');
  }

  async function syncQueuedForms(){
    if (!navigator.onLine) return;
    const queue = readQueue();
    if (!queue.length) return;

    const remaining = [];
    for (const item of queue) {
      try {
        const payload = item.payload || {};
        const method = (payload.method || 'POST').toUpperCase();
        const url = payload.url || window.location.href;
        const formData = new FormData();

        for (const field of payload.fields || []) {
          formData.append(field.name, field.value);
        }
        for (const file of payload.files || []) {
          const blob = dataUrlToBlob(file.dataUrl || '', file.type || 'application/octet-stream');
          const upload = new File([blob], file.filename || 'upload', { type: file.type || 'application/octet-stream' });
          formData.append(file.name, upload);
        }

        const res = await fetch(url, {
          method: method,
          body: formData,
          credentials: 'same-origin',
          cache: 'no-store'
        });

        if (res.ok || res.redirected || res.type === 'opaqueredirect') {
          continue;
        }

        remaining.push(item);
      } catch (e) {
        remaining.push(item);
      }
    }

    writeQueue(remaining);
  }

  document.addEventListener('submit', function(e){
    const form = e.target;
    if (!form || form.tagName !== 'FORM') return;
    if (form.dataset.vtsQueueDisabled === '1') return;
    if (form.method && form.method.toLowerCase() !== 'post') return;
    if (!form.action || form.action.indexOf('mailto:') === 0) return;
    if (navigator.onLine) return;
    e.preventDefault();
    queueOfflineForm(form);
  }, true);

  window.addEventListener('online', function(){
    setTimeout(syncQueuedForms, 500);
  });

  if (document.readyState === 'complete' || document.readyState === 'interactive') {
    setTimeout(syncQueuedForms, 800);
  } else {
    document.addEventListener('DOMContentLoaded', function(){
      setTimeout(syncQueuedForms, 800);
    });
  }
})();

// Back-to-top
(function(){
  var btn = document.createElement('button');
  btn.id = 'toTopBtn'; btn.title = 'Back to top'; btn.setAttribute('aria-label','Back to top');
  btn.innerHTML = '<i class="fas fa-arrow-up"></i>';
  document.body.appendChild(btn);
  var scroller = document.querySelector('.vts-main') || window;
  function onScroll(){
    var y = (scroller===window) ? window.scrollY : scroller.scrollTop;
    if (y > 300) btn.classList.add('show'); else btn.classList.remove('show');
  }
  (scroller===window?window:scroller).addEventListener('scroll', onScroll);
  btn.addEventListener('click', function(){ (scroller===window?window:scroller).scrollTo({top:0,behavior:'smooth'}); });
})();

// Toast notifications (pop-ups) — global window.vtsToast(message, type, ms).
// type: 'success' | 'error' | 'info' | 'warn'. Also auto-pops flash messages
// carried in the URL (?success= / ?error=) so every redirect-with-message
// flow across the app surfaces as a polished pop-up, not just an inline strip.
(function(){
  var wrap;
  function ensureWrap(){
    if (!wrap){ wrap = document.createElement('div'); wrap.className = 'vts-toast-wrap';
      wrap.setAttribute('aria-live','polite'); wrap.setAttribute('role','status');
      document.body.appendChild(wrap); }
    return wrap;
  }
  var ICON  = {success:'fa-circle-check', error:'fa-circle-exclamation', info:'fa-circle-info', warn:'fa-triangle-exclamation'};
  var TITLE = {success:'Success', error:'Something went wrong', info:'Notice', warn:'Heads up'};

  window.vtsToast = function(message, type, ms){
    type = ICON[type] ? type : 'info';
    ms   = (ms == null) ? 1500 : ms;
    var w = ensureWrap();
    var t = document.createElement('div');
    t.className = 'vts-toast t-' + type;
    t.innerHTML = '<span class="vt-ic"><i class="fas ' + ICON[type] + '"></i></span>'
      + '<div class="vt-body"><div class="vt-title"></div><div class="vt-msg"></div></div>'
      + '<span class="vt-bar"></span>';
    t.querySelector('.vt-title').textContent = TITLE[type];
    t.querySelector('.vt-msg').textContent   = message;
    w.appendChild(t);
    requestAnimationFrame(function(){ t.classList.add('show'); });

    var bar = t.querySelector('.vt-bar'), timer = null;
    function dismiss(){ if (timer){ clearTimeout(timer); timer = null; }
      t.classList.add('hide'); setTimeout(function(){ t.remove(); }, 360); }
    // No close button — the toast pops in, counts down the progress bar, and
    // slides out on its own. It always auto-dismisses (no pause-on-hover).
    if (ms > 0){
      bar.style.transition = 'width ' + ms + 'ms linear';
      requestAnimationFrame(function(){ bar.style.width = '0%'; });
      timer = setTimeout(dismiss, ms);
    } else { bar.style.display = 'none'; }
    return t;
  };

  document.addEventListener('DOMContentLoaded', function(){
    try {
      var p = new URLSearchParams(window.location.search);
      var flashes = [['success', p.get('success')], ['error', p.get('error')]];
      var popped = false;
      flashes.forEach(function(pair){
        var kind = pair[0], val = pair[1];
        if (!val) return;
        popped = true;
        vtsToast(val, kind, 1500);
        // Hide the duplicate inline flash strip that prints the same text.
        document.querySelectorAll('.alert-success, .alert-error, .alert-danger').forEach(function(a){
          if (a.textContent && a.textContent.indexOf(val) !== -1) a.style.display = 'none';
        });
      });
      // Clean the message out of the address bar so a refresh won't re-toast.
      if (popped){ p.delete('success'); p.delete('error');
        var qs = p.toString();
        window.history.replaceState({}, '', window.location.pathname + (qs ? '?' + qs : '') + window.location.hash);
      }

      // Also surface INLINE flash alerts — ones rendered without a redirect,
      // e.g. an "Imported 24 scans" summary or a form validation error — as
      // pop-ups, so every action gives feedback. Persistent status banners are
      // left alone: .alert-info, and anything marked .alert-static.
      document.querySelectorAll('.alert-success, .alert-error, .alert-danger').forEach(function(a){
        if (a.classList.contains('alert-static')) return;   // persistent status banner
        if (a.style.display === 'none') return;             // already shown as a toast above
        var msg = (a.textContent || '').replace(/\s+/g, ' ').trim();
        if (!msg) return;
        var isErr = a.classList.contains('alert-error') || a.classList.contains('alert-danger');
        vtsToast(msg, isErr ? 'error' : 'success', 1500);
        // Success is transient — let the toast replace the strip. Errors stay
        // on the page too, so a form's validation message doesn't vanish.
        if (!isErr) a.style.display = 'none';
      });
    } catch (err){}
  });
})();
</script>
<!-- Menus, the help panel and form validation live in one real asset file
     so the standalone auth pages (register, login, verify, forgot password)
     can load exactly the same behaviour -- they do not include this shell. -->
<script src="<?php echo $assetBase; ?>assets/js/vts-ui.js?v=<?php echo @filemtime(__DIR__."/../assets/js/vts-ui.js"); ?>" defer></script>

</body>
</html>
