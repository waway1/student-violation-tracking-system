/* ============================================================
   STAFF ACCESS KEY — mobile entry to the hidden staff login
   ============================================================

   THE PROBLEM THIS SOLVES
   Staff get to their login without a visible "Staff" link anywhere: on a
   desktop that is Ctrl+Shift+S, or typing the code straight into the
   student search box. Neither survives a phone.

     - There is no Ctrl key, so the shortcut simply does not exist.
     - The search box is an ordinary text input, and a mobile keyboard
       will not leave an eight-letter non-word alone in one: iOS
       capitalises the first letter and Android's suggestion strip swaps
       the whole thing for a real word. The page is waiting for an exact
       match that the keyboard never lets through — which is exactly the
       "I can't type the secret key on mobile" that was reported.

   THE WAY IN
   A quiet gesture on the seal — five taps in a row, or a long press —
   opens a small pad with ONE field, and that field is type="password".
   That is the point: a password field is the one input every mobile
   keyboard leaves completely alone. No capitalisation, no autocorrect,
   no suggestion strip remembering the code afterwards. Type the key,
   and the staff login opens exactly as Ctrl+Shift+S opens it.

   WHAT THIS IS AND IS NOT
   It is a doorway, not a lock. The key lives in the page, the same way
   the desktop shortcut always has, and it only decides whether the
   login CARD is shown. Nobody gets in without a real role password
   checked on the server afterwards. The attempt limit below is there to
   stop a bored student poking at the pad, nothing more.

   USAGE
     <link rel="stylesheet" href="assets/css/staff-key.css">
     <img class="seal" data-staff-key-trigger ...>
     <script src="assets/js/staff-key.js"
             data-key="gwcstaff"                      (optional; default)
             data-target="student_search.php?staff=1" (optional)
             defer></script>

   With no data-target, a correct key calls window.openRoleModal() on the
   page it is already on — that is the search page, which owns the modal.
   The landing page has no modal, so it passes a target and travels.
   ============================================================ */
(function () {
  'use strict';

  var script    = document.currentScript ||
                  document.querySelector('script[src*="staff-key.js"]');
  var SECRET    = ((script && script.getAttribute('data-key')) || 'gwcstaff').toLowerCase();
  var TARGET    = (script && script.getAttribute('data-target')) || '';

  var TAPS_NEEDED = 5;      // taps on the seal...
  var TAP_WINDOW  = 2500;   // ...that must land inside this many ms of each other
  var HOLD_MS     = 700;    // or one press held this long
  var MAX_TRIES   = 5;      // wrong keys before the pad goes quiet
  var LOCK_MS     = 30000;

  var overlay, card, input, errEl, goBtn, eyeBtn;
  var tries = 0, lockedUntil = 0, lockTimer = null;

  /* What was typed vs. what we want. Spaces are stripped and case is
     dropped, so a keyboard that slipped in a capital or a trailing space
     still counts — the whole reason this pad exists is keyboards
     interfering. */
  function normalise(v){ return (v || '').toLowerCase().replace(/\s+/g, ''); }

  function build(){
    overlay = document.createElement('div');
    overlay.className = 'skg-overlay';
    overlay.id = 'staffKeyGate';
    overlay.setAttribute('role', 'dialog');
    overlay.setAttribute('aria-modal', 'true');
    overlay.setAttribute('aria-labelledby', 'skgTitle');
    overlay.innerHTML =
        '<div class="skg-card" role="document">'
      +   '<div class="skg-ic" aria-hidden="true"><i class="fas fa-key"></i></div>'
      +   '<h3 id="skgTitle">Staff access</h3>'
      +   '<p class="skg-sub">Enter the staff access key to open the sign-in.</p>'
      +   '<div class="skg-field">'
      +     '<input type="password" id="skgInput" placeholder="Access key"'
      +          ' autocomplete="off" autocorrect="off" autocapitalize="off"'
      +          ' spellcheck="false" maxlength="40" aria-describedby="skgErr">'
      +     '<button type="button" class="skg-eye" id="skgEye"'
      +          ' aria-label="Show key" title="Show key" tabindex="-1">'
      +       '<i class="fas fa-eye" aria-hidden="true"></i></button>'
      +   '</div>'
      +   '<div class="skg-err" id="skgErr" role="alert"></div>'
      +   '<div class="skg-actions">'
      +     '<button type="button" class="skg-cancel" id="skgCancel">Cancel</button>'
      +     '<button type="button" class="skg-go" id="skgGo">Continue</button>'
      +   '</div>'
      + '</div>';
    document.body.appendChild(overlay);

    card   = overlay.querySelector('.skg-card');
    input  = overlay.querySelector('#skgInput');
    errEl  = overlay.querySelector('#skgErr');
    goBtn  = overlay.querySelector('#skgGo');
    eyeBtn = overlay.querySelector('#skgEye');

    goBtn.addEventListener('click', submit);
    overlay.querySelector('#skgCancel').addEventListener('click', close);
    overlay.addEventListener('click', function(e){ if (e.target === overlay) close(); });
    input.addEventListener('keydown', function(e){ if (e.key === 'Enter'){ e.preventDefault(); submit(); } });
    input.addEventListener('input', function(){ errEl.classList.remove('show'); });
    document.addEventListener('keydown', function(e){
      if (e.key === 'Escape' && overlay.classList.contains('open')) close();
    });
    eyeBtn.addEventListener('click', function(){
      var showing = input.type === 'text';
      input.type = showing ? 'password' : 'text';
      eyeBtn.querySelector('i').className = showing ? 'fas fa-eye' : 'fas fa-eye-slash';
      eyeBtn.setAttribute('aria-label', showing ? 'Show key' : 'Hide key');
      input.focus();
    });
  }

  function open(){
    if (!overlay) build();
    overlay.classList.add('open');
    errEl.classList.remove('show');
    input.value = '';
    /* The keyboard is the whole point on a phone, but focusing during the
       same tap that opened the pad gets swallowed on iOS — let the frame
       paint first. */
    setTimeout(function(){ try { input.focus(); } catch (e) {} }, 60);
  }

  function close(){
    if (!overlay) return;
    overlay.classList.remove('open');
    input.value = '';
    errEl.classList.remove('show');
  }

  function fail(msg){
    errEl.textContent = msg;
    errEl.classList.add('show');
    card.classList.remove('shake');
    void card.offsetWidth;               // restart the animation
    card.classList.add('shake');
    input.select();
  }

  function lock(){
    lockedUntil = Date.now() + LOCK_MS;
    goBtn.disabled = true;
    input.disabled = true;
    clearTimeout(lockTimer);
    lockTimer = setTimeout(function(){
      tries = 0; lockedUntil = 0;
      goBtn.disabled = false; input.disabled = false;
      errEl.classList.remove('show');
      try { input.focus(); } catch (e) {}
    }, LOCK_MS);
  }

  function submit(){
    if (Date.now() < lockedUntil) return;
    var typed = normalise(input.value);
    if (typed === ''){ fail('Enter the access key.'); return; }

    if (typed !== SECRET){
      tries++;
      if (tries >= MAX_TRIES){
        fail('Too many attempts. Try again in 30 seconds.');
        lock();
      } else {
        fail('That key is not right.');
      }
      return;
    }

    tries = 0;
    close();
    /* On the search page the staff modal is right here; on the landing
       page it is not, so travel to the page that owns it with the flag
       the desktop shortcut already uses. */
    if (!TARGET && typeof window.openRoleModal === 'function') window.openRoleModal();
    else window.location.href = TARGET || 'student_search.php?staff=1';
  }

  /* ---- The gesture ------------------------------------------------
     Five taps or a long press, on anything marked
     data-staff-key-trigger. Both are things a finger can do and neither
     is something a student does by accident on a seal. */
  function wire(el){
    var count = 0, first = 0, holdTimer = null, held = false;

    function tapped(){
      var now = Date.now();
      if (now - first > TAP_WINDOW){ count = 0; first = now; }
      count++;
      if (count >= TAPS_NEEDED){ count = 0; first = 0; open(); }
    }

    el.addEventListener('pointerdown', function(){
      held = false;
      clearTimeout(holdTimer);
      holdTimer = setTimeout(function(){ held = true; count = 0; open(); }, HOLD_MS);
    });
    ['pointerup', 'pointercancel', 'pointerleave'].forEach(function(ev){
      el.addEventListener(ev, function(){ clearTimeout(holdTimer); });
    });
    el.addEventListener('click', function(e){
      e.preventDefault();
      if (held){ held = false; return; }   // the long press already opened it
      tapped();
    });
    /* A long press on an image otherwise raises the browser's own
       "save image" sheet right on top of the pad. */
    el.addEventListener('contextmenu', function(e){ e.preventDefault(); });
  }

  function init(){
    var triggers = document.querySelectorAll('[data-staff-key-trigger]');
    for (var i = 0; i < triggers.length; i++) wire(triggers[i]);
    build();
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
  else init();

  /* So a page can offer its own way in as well. */
  window.openStaffKeyGate = open;
})();
