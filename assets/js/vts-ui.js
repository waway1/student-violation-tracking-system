/* =====================================================================
   vts-ui.js — behaviour shared by every page in the app.

     1. .vts-menu dropdowns (Export menus, each row's Print menu)
     2. the single Help panel behind the "?" button
     3. form validation presentation

   Loaded by includes/footer.php for the app shell, AND directly by the
   standalone auth pages, which include no shell of their own. Before this
   was a file, all three lived inline in footer.php and register.php --
   18 required fields -- got none of them.

   Styles: assets/css/vts-forms.css
   ===================================================================== */

/* =====================================================================
   Shared dropdown behaviour for every .vts-menu on any page (the Export
   menus, each row's Print menu). Lives here rather than in each page so
   all of them behave identically and there is one copy to maintain.
   Matches the navbar profile menu: one open at a time, click-outside and
   Esc to close.
   ===================================================================== */
/* WHERE AN OPEN MENU IS DRAWN.

   A row menu sits inside the table's horizontal scroller (.table-scroll /
   .table-responsive carry overflow-x:auto so a wide table can be swiped).
   An absolutely-positioned menu inside a scroll container is trapped by it:
   it gets clipped at the table edge, and what does show scrolls away with
   the columns. Widening the container instead only moved the problem.

   So an open menu is taken out of the flow entirely — position:fixed, placed
   against the button's viewport rectangle. Nothing can clip it and it does
   not travel with the table. It opens BELOW the button, the way every other
   dropdown in the app does; it flips above only when the room below cannot
   hold it. Left/right is clamped to the viewport so it can never hang off
   an edge.

   Because the menu is fixed, it would visibly detach if the page scrolled
   underneath it, so any scroll closes it.

   THE PLACEMENT MUST COME BACK OFF WHEN THE MENU CLOSES.
   ---------------------------------------------------------------------
   This is what made the Print menu wreck the violations table. The
   co-ordinates written below are VIEWPORT co-ordinates, and they mean
   something only while `.open` holds the panel at position:fixed. The class
   came off on close; the inline `left:1287px; top:604px` did not. The
   closed panel — still in the DOM, still 252px wide, back to
   position:absolute — was then parked ~1300px to the right of its own
   table cell, INSIDE .table-scroll.

   Everything reported followed from that one leftover:
     · the scroller's scrollWidth jumped by ~1500px, so a sideways scrollbar
       appeared under a table that fitted perfectly well, and one nudge of it
       slid every column off to the left;
     · the card-mode measurement further down this file reads that same
       scrollWidth, concluded the table had overflowed, and flipped the
       listing into stacked cards — the sudden change, and the empty space;
     · the resulting layout shift fires a scroll event, and the scroll
       handler below closes menus, so the panel that had just been opened
       vanished before it could be read. Hence a Print menu that was
       "nowhere to be found".

   One menu, opened once, was enough to do all of it, and the leftover
   survived every later close — which is why it looked random. */
function vtsClearPlacement(list){
  if (!list) return;
  list.style.left   = '';
  list.style.top    = '';
  list.style.right  = '';
  list.style.bottom = '';
}

function vtsPlaceMenu(el){
  var list = el.querySelector('.vts-menu-list');
  var btn  = el.querySelector('button');
  if (!list || !btn) return;

  /* Measure from a clean slate: a stale left/top from the last time this
     menu was open is not what the new size should be read against. */
  vtsClearPlacement(list);

  var r   = btn.getBoundingClientRect();
  var mw  = list.offsetWidth;
  var mh  = list.offsetHeight;
  var gap = 7, edge = 8;
  var vw  = window.innerWidth  || document.documentElement.clientWidth;
  var vh  = window.innerHeight || document.documentElement.clientHeight;

  /* Horizontal: row menus are marked .to-left and align their right edge to
     the button; everything else aligns left. Then clamp to the viewport. */
  var left = el.classList.contains('to-left') ? (r.right - mw) : r.left;
  left = Math.max(edge, Math.min(left, vw - mw - edge));

  /* Vertical: below the button, which is where a dropdown is looked for.
     It flips above only when the room below genuinely cannot hold it and
     the room above can; if neither can, it sits against the bottom edge
     rather than hanging off the screen. It used to prefer ABOVE always,
     which covered the rows you had just been reading and, on a row near
     the top, appeared nowhere near the button that opened it. */
  var below = r.bottom + gap;
  var above = r.top - mh - gap;
  var top;
  if (below + mh <= vh - edge)  top = below;
  else if (above >= edge)       top = above;
  else                          top = Math.max(edge, vh - mh - edge);

  list.style.left   = left + 'px';
  list.style.top    = top + 'px';
  list.style.right  = 'auto';
  list.style.bottom = 'auto';

  /* AND THEN CHECK WHERE IT ACTUALLY LANDED.

     `position: fixed` is only positioned against the window while no
     ancestor has claimed the job. A transform, a filter, a backdrop-filter,
     will-change or containment on ANY ancestor makes that ancestor the
     containing block instead, and then the numbers just written are
     offsets into that box rather than into the viewport — the menu is
     drawn shifted by however far the box is from the window's corner, with
     no error and nothing on screen to say so.

     That is not hypothetical: .vts-main-inner used to compute an identity
     transform for the life of the page (a fade-in animation filling
     forwards — see .vts-main-inner in assets/css/vts-minimal.css), which
     pushed every row menu right by the sidebar's width. The stylesheet is
     fixed, but .vts-card carries a backdrop-filter and .panel a
     will-change, so the next one is a stylesheet edit away.

     Rather than forbid all of that, the placement measures itself: the
     panel is asked where it ended up and moved by the difference. If the
     containing block IS the viewport the delta is zero and this costs one
     rect read. */
  var got = list.getBoundingClientRect();
  var dx = left - got.left, dy = top - got.top;
  if (Math.abs(dx) > 0.5 || Math.abs(dy) > 0.5){
    list.style.left = (left + dx) + 'px';
    list.style.top  = (top  + dy) + 'px';
  }
}

function vtsMenu(e, id){
  e.stopPropagation();
  var el = document.getElementById(id);
  if (!el) return;
  var wasOpen = el.classList.contains('open');
  vtsCloseMenus();
  if (!wasOpen){
    el.classList.add('open');
    var btn = el.querySelector('button');
    if (btn) btn.setAttribute('aria-expanded', 'true');
    vtsPlaceMenu(el);
  }
}
function vtsCloseMenus(){
  document.querySelectorAll('.vts-menu.open').forEach(function(m){
    m.classList.remove('open');
    var b = m.querySelector('button');
    if (b) b.setAttribute('aria-expanded', 'false');
    /* The class and the co-ordinates that depended on it come off together
       — see the note above vtsPlaceMenu for what leaving them behind did. */
    vtsClearPlacement(m.querySelector('.vts-menu-list'));
  });
}
document.addEventListener('click', function(e){
  if (!e.target.closest('.vts-menu')) vtsCloseMenus();
});
document.addEventListener('keydown', function(e){
  if (e.key === 'Escape') vtsCloseMenus();
});
/* Capture phase, so a scroll inside the table counts too, not just the page. */
document.addEventListener('scroll', function(){ vtsCloseMenus(); }, true);
window.addEventListener('resize', function(){ vtsCloseMenus(); });

/* One help panel per page, opened from its "?" button. The open/closed
   choice is remembered, so someone who wants it up keeps it up and
   everyone else never sees it. */
function vtsHelp(){
  var panel = document.getElementById('howtoPanel');
  var btn   = document.getElementById('helpBtn');
  if (!panel) return;
  var show = panel.hidden;
  panel.hidden = !show;
  panel.open   = show;
  if (btn) btn.setAttribute('aria-expanded', show ? 'true' : 'false');
  try { localStorage.setItem('vtsHelpOpen', show ? '1' : '0'); } catch(err){}
}
document.addEventListener('DOMContentLoaded', function(){
  var panel = document.getElementById('howtoPanel');
  if (!panel) return;
  var open = false;
  try { open = localStorage.getItem('vtsHelpOpen') === '1'; } catch(err){}
  if (open){
    panel.hidden = false; panel.open = true;
    var b = document.getElementById('helpBtn');
    if (b) b.setAttribute('aria-expanded', 'true');
  }
});

/* =====================================================================
   FORM VALIDATION — one behaviour for every form in the app.

   The browser's built-in bubble reports ONE problem at a time, in wording
   we don't control ("Please select an item in the list"), and it vanishes
   as soon as you click away. On a form with three blanks that is three
   rounds of submit-guess-retry, with no record on screen of what is still
   wrong. This keeps the browser's CHECKING (the Constraint Validation API
   already knows what is invalid and why) and replaces only its
   PRESENTATION: every problem marked at once, named after the field's own
   label, left on screen until it is fixed, and cleared the moment it is.

   Opt out of a form with data-no-validate.
   Override a single field's wording with data-msg="…".
   ===================================================================== */
(function(){
  'use strict';

  /* The words a person would use for this field: its <label>, minus the
     required asterisk and any trailing colon. */
  function labelFor(el){
    var txt = el.dataset.label;
    if (!txt){
      var wrap = el.closest('.field, .filter-field, .form-group');
      var lab  = wrap && wrap.querySelector('label');
      if (!lab && el.id) lab = document.querySelector('label[for="' + CSS.escape(el.id) + '"]');
      txt = lab ? lab.textContent : (el.getAttribute('aria-label') || el.name || 'this field');
    }
    // A label carries decoration the sentence must not: the required
    // asterisk, an "(optional)" note, a trailing colon.
    return txt.replace(/\*/g, '')
              .replace(/\s*\((optional|required)\)\s*/ig, ' ')
              .replace(/[:\s]+$/, '')
              .trim() || 'this field';
  }

  /* The label as it reads mid-sentence: "Choose a violation type." Only
     whole-word acronyms keep their capitals, so "School ID" stays "school
     ID" rather than becoming "school id". */
  function labelInSentence(el){
    return labelFor(el).split(/\s+/).map(function(w){
      return /^[A-Z0-9]{2,}$/.test(w) ? w : w.toLowerCase();
    }).join(' ');
  }

  /* Plain wording for what is actually wrong. */
  function messageFor(el){
    if (el.dataset.msg) return el.dataset.msg;
    var v = el.validity, name = labelFor(el), lower = labelInSentence(el);

    /* A rule the browser has no concept of — "these two passwords differ",
       "that is missing a number" — is set by the page with
       setCustomValidity(). It already carries the exact wording, and it is
       checked first because it is more specific than anything below. */
    if (v.customError) return el.validationMessage;

    if (v.valueMissing){
      if (el.tagName === 'SELECT')       return 'Choose a ' + lower + '.';
      if (el.type === 'file')            return 'Choose a file first.';
      if (el.type === 'checkbox')        return 'Tick ' + lower + ' to continue.';
      if (el.type === 'radio')           return 'Pick one option for ' + lower + '.';
      return 'Enter the ' + lower + '.';
    }
    if (v.typeMismatch){
      if (el.type === 'email')           return 'That isn’t a complete email address — it needs an @ and a domain.';
      if (el.type === 'url')             return 'That isn’t a complete web address.';
      return 'That isn’t a valid ' + lower + '.';
    }
    if (v.tooShort)     return name + ' needs at least ' + el.minLength + ' characters (you have ' + el.value.length + ').';
    if (v.tooLong)      return name + ' can be at most ' + el.maxLength + ' characters.';
    if (v.rangeUnderflow) return name + ' cannot be lower than ' + el.min + '.';
    if (v.rangeOverflow)  return name + ' cannot be higher than ' + el.max + '.';
    if (v.stepMismatch)   return 'Choose a valid ' + lower + '.';
    if (v.patternMismatch) return el.title ? el.title : ('That is not the expected format for ' + lower + '.');
    return 'Check the ' + lower + '.';
  }

  function fieldWrap(el){ return el.closest('.field, .filter-field, .form-group') || el.parentElement; }

  function clearError(el){
    var w = fieldWrap(el);
    if (!w) return;
    w.classList.remove('has-error');
    var m = w.querySelector('.field-error');
    if (m) m.remove();
    el.removeAttribute('aria-invalid');
  }

  function showError(el, msg){
    var w = fieldWrap(el);
    if (!w) return;
    w.classList.add('has-error');
    el.setAttribute('aria-invalid', 'true');
    var m = w.querySelector('.field-error');
    if (!m){
      m = document.createElement('div');
      m.className = 'field-error';
      m.setAttribute('role', 'alert');
      // After the input, but before a hint, so the problem reads first.
      var hint = w.querySelector('.field-hint');
      if (hint) hint.parentNode.insertBefore(m, hint);
      else w.appendChild(m);
    }
    m.innerHTML = '<i class="fas fa-circle-exclamation"></i><span></span>';
    m.querySelector('span').textContent = msg;
  }

  /* Is this element (or an ancestor) hidden? */
  function isHidden(el){
    if (el.hidden || el.closest('[hidden]')) return true;
    var win = (el.ownerDocument && el.ownerDocument.defaultView) || window;
    for (var n = el; n && n.nodeType === 1; n = n.parentElement){
      var st;
      try { st = win.getComputedStyle(n); } catch (e) { return false; }
      if (!st) return false;
      if (st.display === 'none' || st.visibility === 'hidden') return true;
    }
    return false;
  }

  function candidates(form){
    return Array.prototype.filter.call(
      form.querySelectorAll('input, select, textarea'),
      function(el){
        if (el.disabled || !el.willValidate) return false;
        if (el.type === 'hidden' || el.type === 'submit' || el.type === 'button') return false;
        // A field inside a hidden container can never be filled in, so
        // validating it would deadlock the form behind a message pointing at
        // something invisible. Checked by walking the ancestors rather than
        // by offsetParent: offsetParent is also null for position:fixed, and
        // it needs layout, which makes the rule untestable.
        if (isHidden(el)) return false;
        return true;
      });
  }

  function summarise(form, bad){
    var box = form.querySelector('.form-problems');
    if (!bad.length){ if (box) box.remove(); return; }
    if (!box){
      box = document.createElement('div');
      box.className = 'form-problems';
      box.setAttribute('role', 'alert');
      form.insertBefore(box, form.firstChild);
    }
    var n = bad.length;
    box.innerHTML = '<i class="fas fa-circle-exclamation"></i><div>' +
      '<b>' + (n === 1 ? 'One thing needs attention' : n + ' things need attention') +
      '</b> before this can be saved.</div>';
  }

  /* Forms this module owns. A form is opted in by being seen at load time
     and not opting out; the check is by attribute so the capturing listener
     below can decide per event without keeping a list. */
  function owns(form){
    if (!form || form.tagName !== 'FORM') return false;
    if (form.hasAttribute('data-no-validate')) return false;
    // Search/filter bars submit harmlessly with empty fields.
    if (form.classList.contains('filter-bar')) return false;
    return true;
  }

  /* ONE listener, on the document, in the CAPTURE phase.

     Per-form listeners fire in registration order at the target phase, so a
     page that wires its own submit handler in an inline script (which runs
     during parsing) would beat this module (which runs at DOMContentLoaded)
     -- its AJAX request would already be in flight before anything was
     validated, while novalidate had removed the browser's own blocking.
     Capturing on the document always runs first, so an invalid form is
     stopped before any page handler sees the event. */
  document.addEventListener('submit', function(e){
    var form = e.target;
    if (!owns(form)) return;

    var bad = [];
    candidates(form).forEach(function(el){
      if (el.checkValidity()) { clearError(el); }
      else { bad.push(el); showError(el, messageFor(el)); }
    });
    summarise(form, bad);
    if (!bad.length) return;

    e.preventDefault();
    e.stopPropagation();               // page handlers never see an invalid submit
    if (e.stopImmediatePropagation) e.stopImmediatePropagation();

    var first = bad[0];
    // Both of these are missing or throw in some embedded webviews. A
    // failure here must not abort the handler -- the form is already
    // blocked and the messages are already on screen by this point.
    try { first.focus({ preventScroll: true }); } catch(err){ try { first.focus(); } catch(e2){} }
    try {
      if (typeof first.scrollIntoView === 'function') {
        first.scrollIntoView({ behavior: 'smooth', block: 'center' });
      }
    } catch(err){}
  }, true);

  /* Fixing a field clears its message immediately — the form should not keep
     shouting about something already corrected. */
  function onFix(e){
    var el = e.target;
    if (!el || !el.willValidate) return;
    var form = el.form;
    if (!owns(form)) return;
    var w = fieldWrap(el);
    if (!w || !w.classList.contains('has-error')) return;
    if (el.checkValidity()){
      clearError(el);
      w.classList.add('is-fixed');
      setTimeout(function(){ w.classList.remove('is-fixed'); }, 1400);
      summarise(form, candidates(form).filter(function(x){ return !x.checkValidity(); }));
    }
  }
  document.addEventListener('input', onFix, true);
  document.addEventListener('change', onFix, true);

  /* A "* Required" legend used to be injected here. It was removed: it
     rendered small and low-contrast, and it restated what the red asterisk
     on each field already says. The asterisk is styled to be legible
     instead (see .req in vts-forms.css). */

  /* We render the messages, so stop the browser drawing its own bubble.
     Marking is all that happens here — the listeners above are already live,
     which is what makes the ordering reliable. */
  function markForms(root){
    (root || document).querySelectorAll('form').forEach(function(form){
      if (!owns(form)) return;
      form.setAttribute('novalidate', 'novalidate');
      form.dataset.vtsValidate = '1';
    });
  }
  document.addEventListener('DOMContentLoaded', function(){ markForms(document); });
  if (document.readyState !== 'loading') markForms(document);
})();

/* =====================================================================
   BUSY STATE — say that something is happening.

   Saving a violation, running an export or restoring a backup all take a
   noticeable moment on a school connection, and until now the page gave
   no sign: the button stayed clickable, so the natural response was to
   click it again and post the form twice.

   This marks the button that was used, for every form in the app, the
   moment a submit actually gets through (the validator in the block above
   runs in the capture phase, so an invalid form never reaches here).

   The button is NOT disabled — a disabled control is left out of the POST,
   which would drop the name/value of buttons the server reads. It is made
   unclickable in CSS (.is-busy) and re-entry is blocked here instead.

   Opt out with data-no-busy on the form.
   ===================================================================== */
(function(){
  'use strict';

  var BUSY = 'is-busy';

  function findSubmitter(form, ev){
    // submitter is the accurate answer where it exists (all current browsers).
    if (ev && ev.submitter) return ev.submitter;
    var active = document.activeElement;
    if (active && active.form === form &&
        (active.type === 'submit' || active.type === 'image')) return active;
    return form.querySelector('button[type="submit"], input[type="submit"], button:not([type])');
  }

  function clear(btn){
    if (!btn) return;
    btn.classList.remove(BUSY, 'is-busy-center');
    btn.removeAttribute('aria-busy');
    if (btn.form) delete btn.form.dataset.vtsBusy;
  }

  document.addEventListener('submit', function(ev){
    var form = ev.target;
    if (!form || form.tagName !== 'FORM') return;
    if (ev.defaultPrevented) return;                 // validation stopped it
    if (form.hasAttribute('data-no-busy')) return;

    // Already in flight: swallow the second submit rather than posting twice.
    if (form.dataset.vtsBusy === '1'){ ev.preventDefault(); return; }

    var btn = findSubmitter(form, ev);
    if (!btn) return;

    btn.classList.add(BUSY);
    // A button with no icon has no slot for the spinner to sit in, so centre
    // it over the label instead of tucking it against the left edge.
    if (!btn.querySelector('i')) btn.classList.add('is-busy-center');
    btn.setAttribute('aria-busy', 'true');
    form.dataset.vtsBusy = '1';

    // A form whose response is a file download (every export here) or that
    // opens in a new tab never navigates this page away, so nothing would
    // ever take the state back off. Release it after a moment in that case.
    var target = btn.getAttribute('formtarget') || form.getAttribute('target');
    setTimeout(function(){ clear(btn); }, target === '_blank' ? 1200 : 12000);
  });

  /* Coming BACK to a page (browser Back) can restore it from the bfcache with
     the button still frozen mid-spin. Reset on restore. */
  window.addEventListener('pageshow', function(e){
    if (!e.persisted) return;
    document.querySelectorAll('.' + BUSY).forEach(clear);
  });

  /* Links that kick off a slow server job — exports, report generation —
     get the same treatment via data-busy on the link itself. */
  document.addEventListener('click', function(ev){
    var a = ev.target.closest && ev.target.closest('a[data-busy]');
    if (!a || a.classList.contains(BUSY)) return;
    a.classList.add(BUSY);
    if (!a.querySelector('i')) a.classList.add('is-busy-center');
    a.setAttribute('aria-busy', 'true');
    setTimeout(function(){ clear(a); }, a.target === '_blank' ? 1200 : 12000);
  });
})();

/* =====================================================================
   NOTIFICATION PANEL  —  the bell opens a dialog instead of a page.

   The bell was a link. Reading one notification meant leaving whatever you
   were in the middle of — a half-filled violation form, page 4 of a list —
   and then finding your way back to it. The panel opens over the page and
   closes again, and the link is still there underneath: if this script
   never loads, clicking the bell navigates to the full page exactly as it
   used to, which is why the markup is an <a href> and not a <button>.

   The full pages keep the jobs a panel should not try to do — search,
   status filters, paging, bulk deletes. "View all" goes there.
   ===================================================================== */
(function () {
  var bell  = document.getElementById('vtsBell');
  var modal = document.getElementById('vtsNotifModal');
  if (!bell || !modal) return;               // role without a bell, or a page without the shell

  var body     = document.getElementById('vtsNotifBody');
  var badge    = document.getElementById('vtsBellBadge');
  var closeBtn = document.getElementById('vtsNotifClose');
  var readAll  = document.getElementById('vtsNotifReadAll');
  var clearBtn = document.getElementById('vtsNotifClear');
  var clearLbl = document.getElementById('vtsNotifClearLabel');
  var api      = bell.getAttribute('data-api');
  var csrf     = modal.getAttribute('data-csrf') || '';
  var lastFocus = null;

  /* ---- rendering ------------------------------------------------- */

  function state(cls, iconHtml, text) {
    var d = document.createElement('div');
    d.className = 'vts-notif-state' + (cls ? ' ' + cls : '');
    d.innerHTML = iconHtml;
    d.appendChild(document.createTextNode(text));
    body.innerHTML = '';
    body.appendChild(d);
  }

  function setBadge(n) {
    if (!badge) return;
    badge.textContent = n > 99 ? '99+' : String(n);
    badge.hidden = n <= 0;
  }

  function row(item) {
    var el = document.createElement('div');
    el.className = 'vts-notif-item' + (item.unread ? ' is-unread' : '');

    var main = document.createElement('div');
    main.className = 'vts-notif-main';

    var t = document.createElement('div');
    t.className = 'vts-notif-title';
    t.textContent = item.title || 'Notification';

    var m = document.createElement('div');
    m.className = 'vts-notif-msg';
    m.textContent = item.message || '';

    var meta = document.createElement('div');
    meta.className = 'vts-notif-meta';
    var when = document.createElement('span');
    when.textContent = item.when || '';
    when.title = item.exact || '';           // the exact stamp on hover
    meta.appendChild(when);
    if (item.who) {
      var who = document.createElement('span');
      who.className = 'vts-notif-who';
      who.textContent = item.who;
      meta.appendChild(who);
    }

    main.appendChild(t);
    main.appendChild(m);
    main.appendChild(meta);

    var del = document.createElement('button');
    del.type = 'button';
    del.className = 'vts-notif-del';
    del.innerHTML = '<i class="fas fa-xmark" aria-hidden="true"></i>';
    // The name is on the button, not just an icon, so it is announced as
    // "Dismiss <title>" rather than "button".
    del.setAttribute('aria-label', 'Dismiss: ' + (item.title || 'notification'));
    del.addEventListener('click', function () {
      del.disabled = true;
      send({ action: 'delete', id: item.id });
    });

    // Text content is written with textContent throughout — a notification
    // carries a student's own name and a violation description, i.e. text
    // typed by someone else, and innerHTML would execute whatever is in it.
    el.appendChild(main);
    el.appendChild(del);
    return el;
  }

  function render(data) {
    setBadge(data.unread || 0);

    if (!data.items || !data.items.length) {
      state('is-empty', '<i class="fas fa-bell-slash" aria-hidden="true"></i>', 'No notifications yet.');
      if (readAll)  readAll.disabled  = true;
      if (clearBtn) clearBtn.disabled = true;
      return;
    }
    if (readAll) readAll.disabled = (data.unread || 0) === 0;
    /* The bulk clear means different things by role, and the server decides
       which — an overseer clears only the READ ones, a student clears their
       own lot. The label has to say which, or the button is a guess. */
    if (clearLbl) clearLbl.textContent = (data.scope === 'all') ? 'Clear read' : 'Delete all';
    if (clearBtn) clearBtn.disabled = (data.total || 0) === 0;

    var frag = document.createDocumentFragment();
    data.items.forEach(function (item) { frag.appendChild(row(item)); });
    body.innerHTML = '';
    body.appendChild(frag);
  }

  /* ---- talking to the API ---------------------------------------- */

  function request(opts) {
    body.setAttribute('aria-busy', 'true');
    return fetch(api, opts)
      .then(function (r) {
        if (r.status === 401) throw new Error('Your session ended. Please sign in again.');
        return r.json();
      })
      .then(function (d) {
        if (!d || !d.ok) throw new Error((d && d.error) || 'Notifications are unavailable right now.');
        render(d);
      })
      .catch(function (err) {
        state('is-error', '<i class="fas fa-circle-exclamation" aria-hidden="true"></i> ',
              err.message || 'Could not load notifications.');
        if (readAll) readAll.disabled = false;
      })
      .then(function () { body.setAttribute('aria-busy', 'false'); });
  }

  function load() {
    state('', '<span class="vts-spinner" aria-hidden="true"></span> ', 'Loading…');
    return request({ credentials: 'same-origin', cache: 'no-store' });
  }

  function send(fields) {
    var fd = new FormData();
    fd.append('csrf_token', csrf);
    Object.keys(fields).forEach(function (k) { fd.append(k, fields[k]); });
    return request({ method: 'POST', body: fd, credentials: 'same-origin', cache: 'no-store' });
  }

  /* ---- opening and closing --------------------------------------- */

  function open() {
    lastFocus = document.activeElement;
    modal.hidden = false;
    modal.classList.add('open');
    bell.setAttribute('aria-expanded', 'true');
    if (closeBtn) closeBtn.focus();
    load();
  }

  function close() {
    modal.classList.remove('open');
    modal.hidden = true;
    bell.setAttribute('aria-expanded', 'false');
    // Focus goes back where it came from, or the dialog would dump the
    // keyboard user at the top of the document.
    if (lastFocus && lastFocus.focus) lastFocus.focus();
  }

  bell.addEventListener('click', function (e) {
    e.preventDefault();                      // the href stays as the no-JS path
    if (modal.hidden) open(); else close();
  });

  if (closeBtn) closeBtn.addEventListener('click', close);
  if (readAll)  readAll.addEventListener('click', function () {
    readAll.disabled = true;
    send({ action: 'mark_all_read' });
  });

  /* Deleting is not undoable, so it asks first — the full page asks too. */
  if (clearBtn) clearBtn.addEventListener('click', function () {
    var what = clearLbl && clearLbl.textContent === 'Clear read'
      ? 'Delete every notification that has been read?'
      : 'Delete all of your notifications? This cannot be undone.';
    if (!window.confirm(what)) return;
    clearBtn.disabled = true;
    send({ action: 'clear' });
  });

  // Click the backdrop (but not the panel) to dismiss.
  modal.addEventListener('click', function (e) { if (e.target === modal) close(); });

  document.addEventListener('keydown', function (e) {
    if (modal.hidden) return;
    if (e.key === 'Escape') { close(); return; }
    if (e.key !== 'Tab') return;

    // Keep Tab inside the dialog while it is open — that is what aria-modal
    // promises, and without it Tab walks off into the page behind.
    var f = modal.querySelectorAll('button:not([disabled]), a[href], [tabindex]:not([tabindex="-1"])');
    if (!f.length) return;
    var first = f[0], last = f[f.length - 1];
    if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
    else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
  });
})();

/* =====================================================================
   PASSWORD FIELDS — show/hide, and the live requirements checklist.

   Shared because there are now three places that set a password
   (registration, the student profile, password reset) and they must agree on
   what the rules are. A second copy is how the register form ends up
   accepting what the profile form rejects.

   Binding is DELEGATED for the eye toggle rather than attached per button.
   Two handlers on the same button toggle the field twice and it looks like
   the control is dead — which is exactly what happens when a page keeps its
   own inline copy as well as this one. There is one handler, here.
   ===================================================================== */
(function(){
  'use strict';

  /* ---- Show / hide ---- */
  document.addEventListener('click', function(e){
    var btn = e.target.closest && e.target.closest('.password-wrap .eye, .pwd-wrap .eye-toggle, .slp-search-box.password-wrap .eye');
    if (!btn) return;
    var field = btn.parentElement && btn.parentElement.querySelector('input');
    if (!field) return;
    var icon = btn.querySelector('i');
    var show = field.type === 'password';
    field.type = show ? 'text' : 'password';
    if (icon) icon.className = show ? 'fas fa-eye-slash' : 'fas fa-eye';
    btn.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
    btn.title = show ? 'Hide password' : 'Show password';
  });

  /* ---- The checklist ----
     The same five tests the server applies in password_policy_error()
     (includes/functions.php). Kept in step with it deliberately. */
  var TESTS = {
    len:     function(v){ return v.length >= 8; },
    upper:   function(v){ return /[A-Z]/.test(v); },
    lower:   function(v){ return /[a-z]/.test(v); },
    digit:   function(v){ return /[0-9]/.test(v); },
    special: function(v){ return /[^A-Za-z0-9]/.test(v); }
  };

  function wire(list){
    // The field this list describes points at it with aria-describedby.
    var input = document.querySelector('[aria-describedby~="' + list.id + '"]');
    if (!input) return;

    function paint(){
      var v = input.value || '';
      Array.prototype.forEach.call(list.querySelectorAll('li'), function(li){
        var t = TESTS[li.dataset.rule];
        var met = t ? t(v) : false;
        li.classList.toggle('met', met);
        var ic = li.querySelector('i');
        if (ic) ic.className = met ? 'fas fa-circle-check' : 'fas fa-circle';
        // Spoken as well as shown — the tick must not be the only signal.
        li.setAttribute('aria-label', (li.textContent || '').trim() + (met ? ' — done' : ' — still needed'));
      });
    }
    input.addEventListener('input', paint);
    paint();
  }

  function init(){
    document.querySelectorAll('.pw-rules[id]').forEach(wire);
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
  else init();
})();

/* =====================================================================
   TABLE FILTER — type to narrow a listing that is already on the page.

   For tables whose rows are all rendered at once (dashboard top-N panels,
   ranking tables). Those have no server-side search because there is
   nothing to page through — but "find this one student" is still the first
   thing anyone wants, and scrolling was the only way to do it.

   Markup:  <input class="vts-table-filter" data-filter-target="#someTable">
   Every row whose text does not contain what you typed is hidden, and a
   count is announced for anyone not watching the rows disappear.
   ===================================================================== */
(function(){
  'use strict';

  function wire(input){
    var table = document.querySelector(input.getAttribute('data-filter-target'));
    if (!table) return;
    var tbody = table.tBodies && table.tBodies[0];
    if (!tbody) return;

    var status = document.getElementById(input.getAttribute('aria-describedby') || '');

    function apply(){
      var q = (input.value || '').trim().toLowerCase();
      var shown = 0, total = 0;
      Array.prototype.forEach.call(tbody.rows, function(tr){
        if (tr.dataset.filterExempt === '1') return;      // "no results" row
        total++;
        var hit = q === '' || (tr.textContent || '').toLowerCase().indexOf(q) !== -1;
        tr.hidden = !hit;
        if (hit) shown++;
      });
      if (status){
        status.textContent = q === ''
          ? ''
          : (shown === 0 ? 'No rows match “' + input.value + '”.'
                         : shown + ' of ' + total + ' rows match.');
      }
    }

    input.addEventListener('input', apply);
    // Esc clears, the same as a search field anywhere else.
    input.addEventListener('keydown', function(e){
      if (e.key === 'Escape' && input.value !== ''){ input.value = ''; apply(); e.preventDefault(); }
    });
    apply();
  }

  function init(){ document.querySelectorAll('.vts-table-filter[data-filter-target]').forEach(wire); }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
  else init();
})();

/* =====================================================================
   LISTING TABLES — card mode when the table will not fit
   ---------------------------------------------------------------------
   A wide listing used to scroll left-to-right on a narrow screen, which
   put the Action column — Edit, Print, Served, Delete — off the right
   edge with the header that named it. Below, each table is measured
   against the box it sits in; when it genuinely overflows, `.is-stacked`
   turns its rows into cards (assets/css/vts-tables.css) and every cell
   states its own column name.

   WHY MEASURED RATHER THAN A BREAKPOINT
   The sidebar is 260px and collapses on a button, so one viewport width
   yields two content widths — no `@media` value is correct for both. A
   ResizeObserver watches the actual wrapper, so collapsing the sidebar
   re-evaluates the table the same as resizing the window does. (An
   `@container` query would read the same width, but `container-type`
   carries layout containment, which would capture the row Print menus —
   they are position:fixed specifically to escape this wrapper, see
   vtsPlaceMenu above.)

   Every evaluation measures the table as it actually is. An earlier
   version cached the width at which a table first overflowed and compared
   against that, which could latch a listing into card mode for good —
   see the note on evaluate() below.
   ===================================================================== */
(function () {
  'use strict';

  /* .official-sheet is excluded on purpose: it is a printable facsimile
     with grouped, multi-row headers and a fixed 1180px layout, so there
     is no single <th> per column to label a card with, and its whole
     point is to match the paper form. */
  /* .vts-table is the student's own listing. Every stylesheet in the app
     already styles it in the same breath as .data-table, so it gets the
     same treatment here rather than being the one table left swiping. */
  var TABLES  = 'table.data-table:not(.official-sheet), table.vts-table';
  var WRAPPER = '.table-scroll, .table-responsive, .recent-table-wrap, .bk-table-wrap';

  function wrapperOf(tbl) {
    return (tbl.closest && tbl.closest(WRAPPER)) || tbl.parentElement;
  }

  /* Copy each column's heading onto the cells beneath it, once. The CSS
     renders it via content: attr(data-label). */
  function label(tbl) {
    if (tbl.getAttribute('data-vts-labelled') === '1') return;

    var head = tbl.tHead;
    if (!head || !head.rows.length) return;
    /* The last head row is the one with a heading per column; a table
       with a grouped header carries the spanning row above it. */
    var ths = head.rows[head.rows.length - 1].cells;
    var names = [];
    for (var i = 0; i < ths.length; i++) {
      names.push((ths[i].textContent || '').replace(/\s+/g, ' ').trim());
    }

    for (var b = 0; b < tbl.tBodies.length; b++) {
      var rows = tbl.tBodies[b].rows;
      for (var r = 0; r < rows.length; r++) {
        var cells = rows[r].cells;

        /* A single cell spanning the table is a message ("No violation
           records found."), not a field — labelling it would read as
           data. */
        if (cells.length === 1 && cells[0].hasAttribute('colspan')) {
          cells[0].setAttribute('data-vts-full', '');
          continue;
        }

        for (var c = 0; c < cells.length && c < names.length; c++) {
          if (/^actions?$/i.test(names[c])) {
            /* The controls speak for themselves and an "ACTION" label
               above them only costs a line. */
            cells[c].setAttribute('data-vts-actions', '');
            cells[c].setAttribute('data-label', '');
          } else {
            cells[c].setAttribute('data-label', names[c]);
          }
        }
      }
    }
    tbl.setAttribute('data-vts-labelled', '1');
  }

  /* Does the table overflow its wrapper right now?

     MEASURE THE TABLE, NOT THE SCROLLER. This read wrap.scrollWidth, which
     is the width of EVERYTHING inside the wrapper — absolutely-positioned
     descendants included. Each row carries a Print menu, and a closed menu
     panel is still a 252px box in the DOM; one that had been left with a
     stale viewport co-ordinate on it (see vtsClearPlacement above) added
     over a thousand pixels to that figure. The listing was then stacked
     into cards because of a dropdown, on a screen where every column fitted.

     The table's own border box cannot be moved by anything hanging off a
     cell, so that is what decides it. A table is width:100% but takes its
     min-content width when that is larger, which is exactly the overflow
     worth stacking for. */
  function overflowing(wrap, tbl) {
    /* clientWidth INCLUDES the wrapper's padding, and some of these wrappers
       are padded cards, so comparing straight against it would tolerate a
       card's worth of real overflow before stacking. The table only ever has
       the content box to sit in, so that is what it is measured against. */
    var cs   = window.getComputedStyle(wrap);
    var room = wrap.clientWidth
             - (parseFloat(cs.paddingLeft)  || 0)
             - (parseFloat(cs.paddingRight) || 0);
    if (room <= 0) return false;
    return tbl.getBoundingClientRect().width > room + 2;
  }

  /* What this evaluation depends on: how wide the box is, and how many
     rows are in it. Nothing else can change the answer, so anything else
     the ResizeObserver reports is not worth a re-measure -- and the
     observer reports plenty, because stacking changes the table's HEIGHT
     and that fires it straight back. */
  /* ---- WHY THIS MEASURES INSTEAD OF REMEMBERING ----

     This used to record the table's minimum width the first time it
     overflowed (`data-vts-natw`) and then refuse to un-stack until the
     wrapper grew past it. That is a one-way latch, and it is what made a
     listing "suddenly" turn into cards and stay that way after the Menu
     button was pressed twice:

       - the sidebar collapses over 0.28s, so the observer fires partway
         through the animation, at a width that is neither the old one nor
         the new one;
       - the table is measured mid-transition, before the browser has
         finished re-wrapping the cell text, so the number recorded is
         WIDER than the width the table would actually settle at;
       - from then on, un-stacking needs a wrapper wider than that
         inflated figure. The layout never reaches it, so the table stays
         in card mode at a width where the real table fits perfectly
         well -- and the only way back is a reload.

     A remembered measurement can be wrong and can never correct itself,
     because the one place it was rewritten sat behind the very threshold
     it had got wrong. So nothing is remembered now. To decide, the class
     comes off, the table is measured as it really is, and it goes back on
     only if it genuinely does not fit.

     Taking the class off to measure costs no flicker: it is removed,
     measured and re-applied inside one task, and the browser paints only
     at the end of the frame -- it never renders the intermediate state.
     The signature guard above is what keeps that from running on every
     observer tick. */
  function evaluate(tbl) {
    var wrap = wrapperOf(tbl);
    if (!wrap || !wrap.clientWidth) return;      // hidden tab / display:none

    var wasStacked = tbl.classList.contains('is-stacked');
    if (wasStacked) tbl.classList.remove('is-stacked');

    /* A FLOOR, UNDER THE MEASUREMENT.

       Measuring alone was not enough. A table's own minimum width shifts
       as it stacks and unstacks -- card mode writes a data-label onto
       every cell, and the widest cell is not the same one in the two
       layouts -- so around 1100-1350px the reading depended on which
       layout it happened to be in when the observer woke, and a listing
       could settle unstacked while genuinely too wide. That is the
       left-to-right scroll.

       Below this width no listing in this app has ever fitted, so there
       is nothing to work out: it is cards. Above it the measurement still
       decides, which keeps a narrow table from being stacked needlessly
       on a wide screen. A fixed number is the honest tool here -- it is
       the one thing the layout cannot argue with. */
    var FLOOR = 1350;
    var mustStack = (window.innerWidth || document.documentElement.clientWidth) <= FLOOR;

    // Reading the table's box forces the layout the line above just
    // invalidated, so this is the real table being measured, not the
    // stacked one.
    if (mustStack || overflowing(wrap, tbl)) {
      label(tbl);
      tbl.classList.add('is-stacked');
    }
  }

  /* WHY THIS IS DEBOUNCED, AND WHY THAT IS THE WHOLE TRICK.

     Two things fire the observer that are not a person resizing anything:
     stacking a table changes its height, and it can change the wrapper's
     width too when a vertical scrollbar comes or goes. So the observer was
     being woken by changes it had just caused itself.

     Deciding on those is how the listing ended up scrolling sideways
     between roughly 1000px and 1350px: a table stacked, its own mutation
     woke the observer, the second pass measured a layout that was mid-
     change and un-stacked it again, and it settled on the wrong half of
     the flip-flop.

     Waiting for quiet fixes both halves at once. Our own callbacks land
     inside the window and simply restart it, and the sidebar's 0.28s
     collapse and the browser's resize storm collapse into ONE pass on a
     layout that has stopped moving -- which is also the only kind of
     layout worth measuring. */
  var settleTimer = null;
  function evaluateAll() {
    clearTimeout(settleTimer);
    settleTimer = setTimeout(function () {
      document.querySelectorAll(TABLES).forEach(evaluate);
    }, 120);
  }
  /* The first pass has nothing to wait for. */
  function evaluateNow() {
    document.querySelectorAll(TABLES).forEach(evaluate);
  }

  function init() {
    var tables = document.querySelectorAll(TABLES);
    if (!tables.length) return;

    evaluateNow();

    if (typeof ResizeObserver === 'function') {
      /* One observer for every wrapper. Its callback fires on the layout
         pass that follows the resize, so the sidebar's 0.3s collapse is
         picked up when it settles without polling for it.

         KEPT ON `window` ON PURPOSE. It used to be a plain `var ro` inside
         this function, and the moment init() returned nothing referenced
         the observer any more. It was collected, it stopped firing, and
         the tables were left frozen at whatever they had been measured at
         on first paint. The symptom was a listing that scrolled
         left-to-right at any width narrower than the one the page loaded
         at -- exactly the sideways scroll card mode exists to prevent --
         because the measurement that would have stacked it never ran a
         second time. */
      window.__vtsTableRO = new ResizeObserver(function () { evaluateAll(); });
      var seen = [];
      tables.forEach(function (t) {
        var w = wrapperOf(t);
        if (w && seen.indexOf(w) === -1) { seen.push(w); window.__vtsTableRO.observe(w); }
      });
    }

    /* Belt and braces, and cheap: a window resize covers the case the
       observer misses (an older engine, or a wrapper that is display:none
       at init and so has no box to observe). evaluate() short-circuits on
       an unchanged signature, so the extra calls cost a property read. */
    window.addEventListener('resize', evaluateAll);
    window.addEventListener('orientationchange', evaluateAll);

    /* Web fonts land after first paint and change how wide the text is,
       which can decide this either way. */
    if (document.fonts && document.fonts.ready) {
      document.fonts.ready.then(evaluateAll).catch(function () {});
    }
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
  else init();
})();

/* =====================================================================
   SIDEBAR DROPDOWN — remember whether it is open
   ---------------------------------------------------------------------
   The Violations & Reports group is a <details>, so opening and closing it
   needs no script and works before this file loads. What needs a script is
   making the choice STICK: the sidebar is re-rendered by PHP on every page,
   so without this, closing the drawer and then navigating anywhere reopens
   it, and the close reads as having been ignored.

   The server still decides the starting position (open when you are
   filtered to a department — see includes/sidebar.php). A deliberate click
   overrides that from then on, because someone who has just shut a drawer
   has said something more specific than a default can.

   localStorage and not a cookie: this is a per-browser display preference,
   nothing else needs to read it, and it has no business on every request.
   Every access is wrapped — a private window or blocked site data throws on
   access, and a sidebar must never be what breaks a page.
   ===================================================================== */
(function () {
  'use strict';

  /* Was hard-wired to the one Violations drawer (a single querySelector and a
     single hard-coded key). It keys off a data-remember attribute instead, so
     any <details> that wants its state remembered just has to carry one —
     the Violations drawer is the only one left in the sidebar since the
     on-duty panel moved to the Settings page, but the next one costs nothing
     but the attribute. */
  function init() {
    var panels = document.querySelectorAll('details[data-remember]');

    Array.prototype.forEach.call(panels, function (drop) {
      var key = 'vts.sidebar.' + drop.getAttribute('data-remember') + '.open';

      var saved = null;
      try { saved = localStorage.getItem(key); } catch (e) { /* unavailable */ }

      /* Only a stored answer overrides the server's. A first visit has none,
         and then the PHP default stands. */
      if (saved === 'open')   drop.open = true;
      if (saved === 'closed') drop.open = false;

      drop.addEventListener('toggle', function () {
        try { localStorage.setItem(key, drop.open ? 'open' : 'closed'); }
        catch (e) { /* the toggle itself still worked */ }
      });
    });
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
  else init();
})();

/* =====================================================================
   WHAT A FIELD WILL ACCEPT  ·  data-allow="digits|letters|alnum|phone"

   A School ID box that quietly takes letters, and a surname box that takes
   digits, are the same bug: the form looks willing, the server refuses, and
   the person is told afterwards that something they were allowed to type was
   never allowed. So the rule is applied where the typing happens.

   THREE LAYERS, EACH DOING WHAT ONLY IT CAN:

     data-allow   strips a character the field cannot hold, as it is typed
                  or pasted. The keyboard stops being able to produce an
                  invalid value, so the error never has to be shown.
     pattern=     the browser's own check on submit, and the accessible
                  description of the rule via title=.
     the SERVER   the only one that decides. Everything here is a courtesy -
                  it is all editable from the console, and every rule below
                  is repeated server-side.

   Paste is handled because the input event covers it: the value is filtered
   after the fact rather than the keystroke being blocked, which is also what
   keeps this working with autofill, dictation and a phone's suggestion bar.
   The caret is put back where it was, minus whatever was removed in front of
   it, so filtering mid-string does not throw the cursor to the end.
   ===================================================================== */
(function () {
  'use strict';

  var RULES = {
    /* A School ID, an OTP, a year level: digits and nothing else. */
    digits:  /[^0-9]/g,
    /* A person's name. Letters of any alphabet (accented Filipino and
       Spanish names are ordinary here), plus the three punctuation marks a
       name really does contain: Dela Cruz, O'Brien, Ma. Teresa, Smith-Jones. */
    letters: /[^\p{L} .'\-]/gu,
    /* A username or a code: letters, digits, and the separators that are
       safe in one. */
    alnum:   /[^A-Za-z0-9._\-]/g,
    /* A local mobile number - digits only; length is the pattern's job. */
    phone:   /[^0-9]/g
  };

  function filter(el) {
    var rule = RULES[el.getAttribute('data-allow')];
    if (!rule) return;
    var before = el.value;
    var clean;
    try { clean = before.replace(rule, ''); }
    catch (e) { return; }                    // no \p{L} support: leave it alone
    if (clean === before) return;

    var pos = el.selectionStart;
    var removedBeforeCaret = 0;
    try {
      removedBeforeCaret = before.slice(0, pos).length
                         - before.slice(0, pos).replace(rule, '').length;
    } catch (e) {}
    el.value = clean;
    try { el.setSelectionRange(pos - removedBeforeCaret, pos - removedBeforeCaret); }
    catch (e) {}
  }

  document.addEventListener('input', function (e) {
    var el = e.target;
    if (el && el.hasAttribute && el.hasAttribute('data-allow')) filter(el);
  }, true);

  /* Anything already on the page (a value echoed back after a failed submit)
     is cleaned once on load, so the box never opens holding something it
     would refuse to accept if typed. */
  function sweep() {
    var all = document.querySelectorAll('[data-allow]');
    Array.prototype.forEach.call(all, filter);
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', sweep);
  else sweep();
})();
