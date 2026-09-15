/* =====================================================================
   LANDING PAGE MOTION
   ---------------------------------------------------------------------
   The same four effects the portfolio this was modelled on uses, in the
   same order and with the same restraint:

     1. the nav turns solid once the page leaves the photograph
     2. the hero and the mid-page band drift slower than the scroll
     3. buttons lean toward the pointer
     4. sections arrive rather than appear

   ALL OF IT IS OPTIONAL. Two gates decide, and they are checked once:

     reduce  - the person has asked their system for less movement. Then
               nothing moves: no parallax, no lean, and the reveals are
               already visible because the stylesheet says so under the
               same media query. The page is not "broken with animation
               off"; it is simply the page.
     fine    - the device has a real pointer. A phone has no hover to
               lean into, and running mousemove maths on a touchscreen
               costs battery for an effect nobody can see.

   Parallax is also skipped on narrow screens: a 390px viewport has no
   room for a photograph to drift within, and the transform only shows as
   a jitter on scroll.
   ===================================================================== */
(function () {
  'use strict';

  var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  var fine   = window.matchMedia && window.matchMedia('(hover: hover) and (pointer: fine)').matches;

  var nav   = document.getElementById('lpNav');
  var hero  = document.getElementById('lpHeroImg');
  var band  = document.getElementById('lpBandImg');
  var toTop = document.getElementById('lpTop');

  /* ---- 1 + 2: one scroll handler for everything that reads scrollY ----
     Separate listeners would each force their own layout read on every
     frame. One handler, passive, reading once. */
  function onScroll() {
    var y = window.pageYOffset || document.documentElement.scrollTop || 0;

    if (nav) nav.classList.toggle('solid', y > 40);
    if (toTop) toTop.classList.toggle('show', y > window.innerHeight);

    if (reduce || window.innerWidth < 900) return;

    /* The hero sits in a box 24% taller than it needs, so it has room to
       move without ever showing an edge. */
    if (hero && y < window.innerHeight * 1.2) {
      hero.style.transform = 'translate3d(0,' + (y * 0.16) + 'px,0)';
    }
    if (band) {
      var r = band.parentElement.getBoundingClientRect();
      if (r.top < window.innerHeight && r.bottom > 0) {
        band.style.transform = 'translate3d(0,' + ((r.top - window.innerHeight) * 0.08) + 'px,0)';
      }
    }
  }
  window.addEventListener('scroll', onScroll, { passive: true });
  window.addEventListener('resize', onScroll, { passive: true });
  onScroll();

  if (toTop) {
    toTop.addEventListener('click', function () {
      window.scrollTo({ top: 0, behavior: reduce ? 'auto' : 'smooth' });
    });
  }

  /* ---- 3: magnetic buttons ---- */
  if (fine && !reduce) {
    Array.prototype.forEach.call(document.querySelectorAll('.magnetic'), function (el) {
      el.addEventListener('mousemove', function (e) {
        var r = el.getBoundingClientRect();
        el.style.transform =
          'translate(' + (e.clientX - r.left - r.width / 2) * 0.2 + 'px,' +
                         (e.clientY - r.top - r.height / 2) * 0.28 + 'px)';
      });
      el.addEventListener('mouseleave', function () { el.style.transform = ''; });
    });
  }

  /* ---- 4: arrive on scroll ----
     Unobserved once shown: a section that has been read does not need
     watching for the rest of the visit. */
  if ('IntersectionObserver' in window && !reduce) {
    var io = new IntersectionObserver(function (entries) {
      entries.forEach(function (en) {
        if (!en.isIntersecting) return;
        en.target.classList.add('in');
        io.unobserve(en.target);
      });
    }, { threshold: 0.12, rootMargin: '0px 0px -40px 0px' });
    Array.prototype.forEach.call(document.querySelectorAll('.reveal'), function (el) { io.observe(el); });
  } else {
    /* No observer, or movement is unwanted: show everything at once. */
    Array.prototype.forEach.call(document.querySelectorAll('.reveal'), function (el) { el.classList.add('in'); });
  }

  /* ---- the narrow-screen drawer ---- */
  var burger = document.getElementById('lpBurger');
  var drawer = document.getElementById('lpDrawer');
  if (burger && drawer) {
    burger.addEventListener('click', function (e) {
      e.stopPropagation();
      var open = drawer.classList.toggle('open');
      burger.setAttribute('aria-expanded', open ? 'true' : 'false');
    });
    drawer.addEventListener('click', function (e) {
      if (e.target.tagName === 'A') { drawer.classList.remove('open'); burger.setAttribute('aria-expanded', 'false'); }
    });
    document.addEventListener('click', function (e) {
      if (!drawer.contains(e.target) && e.target !== burger && drawer.classList.contains('open')) {
        drawer.classList.remove('open'); burger.setAttribute('aria-expanded', 'false');
      }
    });
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') { drawer.classList.remove('open'); burger.setAttribute('aria-expanded', 'false'); }
    });
  }

  /* ---- the search is the student lookup, and it answers here ----

     It used to hand everything to student_search.php and resolve nothing, so
     the one thing a student wants from "Find your record" - am I in there? -
     was the one thing it could not say.

     It asks auth/student_lookup_search.php, the same endpoint the sign-in
     page already calls. That is deliberate and it is not a new hole: that
     file is public by necessity, returns NAMES ONLY and never a School ID,
     needs three characters for a name, and answers an ID prefix only for
     accounts that already have a password. The reasoning is written out in
     full at the top of it. Picking a name does not sign anyone in - it
     carries the name to student_search.php, which still asks for a password.

     Submitting still works exactly as before, so Enter, the hidden submit
     button and anything driving the form without a pointer are unaffected. */
  var form = document.getElementById('lpSearchForm');
  var box  = document.getElementById('lpSearchInput');
  var pane = document.getElementById('lpResults');

  if (form) {
    form.addEventListener('submit', function (e) {
      if (!box || box.value.trim() === '') { e.preventDefault(); box && box.focus(); }
    });
  }

  if (form && box && pane) (function () {
    var timer = null, seq = 0, hits = [], active = -1;

    function esc(t) {
      return String(t == null ? '' : t).replace(/[&<>"']/g, function (c) {
        return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
      });
    }

    /* Show WHERE the typed text matched. The needle is escaped first and the
       search runs on the escaped haystack, so nothing here can inject markup
       and an entity never gets split down the middle. */
    function mark(name, needle) {
      var h = esc(name), n = esc(needle);
      if (!n) return h;
      var at = h.toLowerCase().indexOf(n.toLowerCase());
      if (at < 0) return h;
      return h.slice(0, at) + '<mark>' + h.slice(at, at + n.length) + '</mark>' + h.slice(at + n.length);
    }

    function close() {
      pane.classList.remove('open');
      box.setAttribute('aria-expanded', 'false');
      active = -1;
    }
    function open() {
      pane.classList.add('open');
      box.setAttribute('aria-expanded', 'true');
    }
    function note(html) { pane.innerHTML = '<div class="lp-results-note">' + html + '</div>'; open(); }

    /* A hit is a link to the sign-in page with the name already filled in -
       a real href, so it opens in a new tab and reads as a link, and the
       click handler is only there to save a round trip on the common path. */
    function render(list, q) {
      hits = list; active = -1;
      if (!list.length) {
        note('No student found matching <b>' + esc(q) + '</b>.<br>'
           + 'Check the spelling, or <a href="register.php">register for an account</a>.');
        return;
      }
      var rows = list.map(function (s, i) {
        var meta = [s.course, s.year].filter(Boolean).join(' \u00b7 ') || 'Enrolled student';
        return '<a class="lp-hit" role="option" id="lpHit' + i + '" aria-selected="false" data-i="' + i + '"'
             + ' href="student_search.php?q=' + encodeURIComponent(s.name) + '">'
             + '<span class="lp-hit-av"><i class="fas fa-user-graduate" aria-hidden="true"></i></span>'
             + '<span class="lp-hit-tx"><span class="lp-hit-nm">' + mark(s.name, q) + '</span>'
             + '<span class="lp-hit-meta">' + esc(meta) + '</span></span></a>';
      }).join('');
      pane.innerHTML = '<div class="lp-results-list">' + rows + '</div>'
        + '<div class="lp-results-foot">' + list.length + (list.length === 1 ? ' match' : ' matches')
        + ' \u2014 pick your name to sign in</div>';
      open();
    }

    function setActive(i) {
      var els = pane.querySelectorAll('.lp-hit');
      if (!els.length) return;
      if (active >= 0 && els[active]) {
        els[active].classList.remove('is-active');
        els[active].setAttribute('aria-selected', 'false');
      }
      active = (i + els.length) % els.length;
      els[active].classList.add('is-active');
      els[active].setAttribute('aria-selected', 'true');
      els[active].scrollIntoView({ block: 'nearest' });
      box.setAttribute('aria-activedescendant', els[active].id);
    }

    function search(q) {
      var mine = ++seq;
      note('<span class="lp-spin" aria-hidden="true"></span>Searching\u2026');
      fetch('auth/student_lookup_search.php?q=' + encodeURIComponent(q), { cache: 'no-store' })
        .then(function (r) { return r.ok ? r.json() : []; })
        .then(function (list) {
          if (mine !== seq) return;                   // a later keystroke won
          render(Array.isArray(list) ? list : [], q);
        })
        .catch(function () {
          if (mine !== seq) return;
          note('Could not search just now. Check your connection, or press Enter to carry on.');
        });
    }

    box.addEventListener('input', function () {
      var q = box.value.trim();
      clearTimeout(timer);
      box.removeAttribute('aria-activedescendant');
      /* The same two floors the server applies, so a query it will refuse is
         never sent: one character for digits, three for a name. */
      var min = /^[0-9][0-9-]*$/.test(q) ? 1 : 3;
      if (q.length < min) { seq++; close(); return; }
      timer = setTimeout(function () { search(q); }, 200);
    });

    box.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') { close(); return; }
      if (!pane.classList.contains('open')) return;
      if (e.key === 'ArrowDown') { e.preventDefault(); setActive(active + 1); }
      else if (e.key === 'ArrowUp') { e.preventDefault(); setActive(active - 1); }
      else if (e.key === 'Enter' && active >= 0 && hits[active]) {
        e.preventDefault();                      // take the highlighted name
        window.location.href = 'student_search.php?q=' + encodeURIComponent(hits[active].name);
      }
    });

    box.addEventListener('focus', function () {
      if (pane.innerHTML.trim() !== '' && box.value.trim() !== '') open();
    });

    document.addEventListener('click', function (e) {
      if (!pane.contains(e.target) && e.target !== box) close();
    });
  })();
})();
