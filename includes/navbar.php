<?php
require_once __DIR__ . '/../config/app.php';   // APP_NAME / APP_TAGLINE
/* Shared top navbar: search, notifications, and a user profile dropdown. */
$assetBase = isset($assetBase) ? $assetBase : '../';
$role      = $_SESSION['role'] ?? 'User';
$fullname  = $_SESSION['fullname'] ?? 'User';
$email     = $_SESSION['email'] ?? '';
$pfp       = $_SESSION['profile_picture'] ?? '';

// Where the top-bar search box sends staff (students/violations lookup).
$searchAction = null;
switch ($role) {
    case 'Admin':     $searchAction = $assetBase . 'admin/violations.php';     break;
    case 'OSA':       $searchAction = $assetBase . 'admin/violations.php';     break;
    case 'OSA Staff': $searchAction = $assetBase . 'osa_staff/violations.php'; break;
}
$searchValue = htmlspecialchars($_GET['search'] ?? '');

// Account/profile + settings targets by role
$accountHref = $assetBase . ($role === 'Student' ? 'student/profile.php' : 'account.php');
?>
<nav class="vts-navbar">
  <div class="nav-start">
    <?php /* ---- THE MENU BUTTON A PHONE CAN ACTUALLY REACH ----
             There is a Menu button in the sidebar already, but it is
             position:static and lives INSIDE .vts-sidebar — and on a phone
             that whole panel is translated off the left edge
             (translateX(-100%)). The button went with it, sitting at about
             x=-284, so once a phone was inside a dashboard there was no way
             left to open the navigation at all: no Students, no Violations,
             no Reports. This copy lives in the navbar, which is fixed and
             never transformed, so it stays put. Hidden above 900px, where
             the sidebar's own button is visible and does the job. */ ?>
    <button type="button" class="menu-tab nav-menu-tab" id="navMenuBtn"
            onclick="if (typeof toggleSidebar === 'function') toggleSidebar();"
            title="Show / hide menu" aria-label="Menu"
            aria-controls="vtsSidebar" aria-expanded="false">
      <i class="fas fa-bars" aria-hidden="true"></i>
    </button>
    <div class="brand">
      <img alt="GWC Logo" src="<?php echo $assetBase; ?>assets/images/logo-sm.jpg">
      <span class="brand-name"><?php echo htmlspecialchars(APP_NAME); ?><span class="brand-sub"><?php echo htmlspecialchars(APP_TAGLINE); ?></span></span>
    </div>
  </div>

  <?php if ($searchAction): ?>
  <form class="nav-search" action="<?php echo $searchAction; ?>" method="GET" role="search">
    <!-- The magnifier is a real submit button rather than a decorative icon:
         it looks the same, but the search can now be run by clicking it,
         which is the only affordance on a touch keyboard without Enter. -->
    <button type="submit" class="nav-search-go" title="Search">
      <i class="fas fa-magnifying-glass" aria-hidden="true"></i>
      <span class="sr-only">Search</span>
    </button>
    <label for="navSearch" class="sr-only">Search students or violations</label>
    <input id="navSearch" type="text" name="search" placeholder="Search students or violations…"
           value="<?php echo $searchValue; ?>" autocomplete="off">
  </form>
  <?php endif; ?>

  <div class="nav-right">
    <?php
    // Notification bell — clickable, with a live unread count
    $bellHref = null; $bellCount = 0;
    if (isset($conn)) {
        if ($role === 'Student') {
            $bellHref = $assetBase . 'student/notifications.php';
            $bc = $conn->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = :id AND is_read = 0");
            $bc->execute([':id' => $_SESSION['user_id'] ?? 0]);
            $bellCount = (int)$bc->fetchColumn();
        } elseif (in_array($role, ['Admin', 'OSA'], true)) {
            $bellHref = $assetBase . 'admin/notifications.php';
            $bellCount = (int)$conn->query("SELECT COUNT(*) FROM notifications WHERE is_read = 0")->fetchColumn();
        }
    }
    ?>
        <!-- Connectivity indicator is client-side and reflects the browser's
          current network state, including cached pages while offline. -->
    <span class="vts-netpill vts-net-on" id="vtsNetPill" data-rendered="<?php echo date('c'); ?>" title="Connection status">
      <span class="vts-net-dot"></span><span id="vtsNetTxt">Online</span>
    </span>
    <?php if ($bellHref): ?>
    <?php /* Still a real link to the full page: if the script never loads, the
             bell keeps working the way it always did. The panel below takes
             over the click only once its JS is running. */ ?>
    <a href="<?php echo $bellHref; ?>" class="nav-bell" title="Notifications"
       id="vtsBell" data-api="<?php echo $assetBase; ?>api/notifications.php"
       aria-haspopup="dialog" aria-expanded="false">
      <i class="fas fa-bell" aria-hidden="true"></i>
      <span class="sr-only">Notifications</span>
      <span class="nav-bell-badge" id="vtsBellBadge"
            <?php echo $bellCount > 0 ? '' : 'hidden'; ?>><?php echo $bellCount > 99 ? '99+' : $bellCount; ?></span>
    </a>
    <?php endif; ?>

    <!-- Profile dropdown -->
    <div class="nav-profile" id="navProfile">
      <button type="button" class="nav-profile-btn" onclick="vtsToggleProfile(event)" aria-haspopup="true" aria-expanded="false">
        <span class="user-avatar">
          <?php if (!empty($pfp)): ?>
            <img alt="" src="<?php echo $assetBase; ?>uploads/profile/<?php echo htmlspecialchars($pfp); ?>">
          <?php else: ?>
            <i class="fas <?php echo $role === 'Student' ? 'fa-user-graduate' : 'fa-user'; ?>"></i>
          <?php endif; ?>
        </span>
        <span class="user-info">
          <span class="user-role"><?php echo htmlspecialchars($fullname); ?></span>
          <span class="user-desc"><?php echo htmlspecialchars(vts_role_label($role)); ?></span>
        </span>
        <i class="fas fa-chevron-down np-caret"></i>
      </button>

      <div class="nav-profile-menu" id="navProfileMenu" role="menu">
        <div class="npm-head">
          <div class="npm-name"><?php echo htmlspecialchars($fullname); ?></div>
          <div class="npm-sub"><?php echo htmlspecialchars($email !== '' ? $email : $role); ?></div>
        </div>
        <a href="<?php echo $accountHref; ?>" role="menuitem"><i class="fas fa-user-gear"></i> My Profile</a>
        <?php /* No Notifications entry here on purpose — the bell to the left of
                 this dropdown and the sidebar link already cover it. */ ?>
        <div class="npm-divider"></div>
        <a href="<?php echo $assetBase; ?>logout.php" class="npm-logout" role="menuitem"><i class="fas fa-right-from-bracket"></i> Logout</a>
      </div>
    </div>
  </div>
</nav>

<?php if ($bellHref): ?>
<?php /* The notification panel. Markup lives here (rendered empty) rather than
         being built in JS, so the dialog's structure, labels and landmarks are
         in the HTML where they can be read and styled, and only the rows are
         filled in at open time. Hidden until the script opens it. */ ?>
<div class="modal-overlay vts-notif-overlay" id="vtsNotifModal" role="dialog"
     aria-modal="true" aria-labelledby="vtsNotifTitle" hidden
     data-csrf="<?php echo function_exists('csrf_token') ? htmlspecialchars(csrf_token()) : ''; ?>">
  <div class="vts-notif-panel" role="document">

    <div class="vts-notif-head">
      <h2 id="vtsNotifTitle"><i class="fas fa-bell" aria-hidden="true"></i> Notifications</h2>
      <button type="button" class="vts-notif-x" id="vtsNotifClose" aria-label="Close notifications">
        <i class="fas fa-xmark" aria-hidden="true"></i>
      </button>
    </div>

    <!-- aria-live so a screen reader hears the list arrive, the count change,
         and a row being dismissed, none of which move focus. -->
    <div class="vts-notif-body" id="vtsNotifBody" aria-live="polite" aria-busy="false">
      <div class="vts-notif-state">
        <span class="vts-spinner" aria-hidden="true"></span> Loading…
      </div>
    </div>

    <?php /* Mark all read, the bulk clear, and per-row dismiss all live here
             now. They used to be only on the full page, so the panel could
             show you a notification but not let you act on it. */ ?>
    <div class="vts-notif-foot">
      <div class="vts-notif-acts">
        <button type="button" class="btn-outline btn-sm" id="vtsNotifReadAll">
          <i class="fas fa-check-double" aria-hidden="true"></i> Mark all read
        </button>
        <button type="button" class="btn-outline btn-sm vts-notif-clear" id="vtsNotifClear">
          <i class="fas fa-trash" aria-hidden="true"></i> <span id="vtsNotifClearLabel">Delete all</span>
        </button>
      </div>
      <a href="<?php echo $bellHref; ?>" class="vts-notif-all">View all</a>
    </div>

  </div>
</div>
<?php endif; ?>

<script>
/* Profile dropdown open/close (click-outside + Esc to close). */
function vtsToggleProfile(e){
  e.stopPropagation();
  var p = document.getElementById('navProfile');
  var open = p.classList.toggle('open');
  var btn = p.querySelector('.nav-profile-btn');
  if (btn) btn.setAttribute('aria-expanded', open ? 'true' : 'false');
}
document.addEventListener('click', function(e){
  var p = document.getElementById('navProfile');
  if (p && p.classList.contains('open') && !p.contains(e.target)) p.classList.remove('open');
});
document.addEventListener('keydown', function(e){
  if (e.key === 'Escape'){ var p = document.getElementById('navProfile'); if (p) p.classList.remove('open'); }
});

/* ---- Student connectivity indicator + "you're viewing cached data" banner ---- */
(function(){
  var pill = document.getElementById('vtsNetPill');
  if (!pill) return; // not a student page

  var txt = document.getElementById('vtsNetTxt');
  var renderedAt = pill.getAttribute('data-rendered');

  function fmtWhen(iso){
    try{
      var d = new Date(iso);
      var p = function(n){ return String(n).padStart(2,'0'); };
      var h = d.getHours(); var ap = h >= 12 ? 'PM' : 'AM'; h = h % 12 || 12;
      return p(d.getMonth()+1)+'/'+p(d.getDate())+'/'+d.getFullYear()+' '+h+':'+p(d.getMinutes())+' '+ap;
    }catch(e){ return ''; }
  }

  function showOfflineBanner(){
    if (document.getElementById('vtsOfflineBanner')) return;
    var main = document.querySelector('.vts-main-inner') || document.querySelector('.vts-main');
    if (!main) return;
    var b = document.createElement('div');
    b.id = 'vtsOfflineBanner';
    b.style.cssText = 'display:flex;align-items:center;gap:10px;background:#fff7e6;border:1px solid #ffe2a6;'
      + 'border-left:4px solid var(--warning);border-radius:10px;padding:10px 14px;margin-bottom:16px;'
      + 'color:#8a6100;font-size:.86rem;';
    b.innerHTML = '<i class="fas fa-wifi" style="opacity:.6;"></i><span>You\'re offline — showing the last saved version of this page'
      + (renderedAt ? (' (as of ' + fmtWhen(renderedAt) + ')') : '') + '. Numbers may be out of date until you\'re back online.</span>';
    main.insertBefore(b, main.firstChild);
  }
  function hideOfflineBanner(){
    var b = document.getElementById('vtsOfflineBanner');
    if (b) b.remove();
  }

  function setNet(on){
    pill.className = 'vts-netpill ' + (on ? 'vts-net-on' : 'vts-net-off');
    if (txt) txt.textContent = on ? 'Online' : 'Offline';
    if (on) hideOfflineBanner(); else showOfflineBanner();
  }
  window.addEventListener('online',  function(){ setNet(true); });
  window.addEventListener('offline', function(){ setNet(false); });
  setNet(navigator.onLine);

  // A page load itself served entirely from the service-worker cache while
  // offline still fires 'load' with navigator.onLine possibly stale on some
  // browsers — recheck once shortly after paint.
  setTimeout(function(){ setNet(navigator.onLine); }, 300);

  // Forms (delete notification, save profile, etc.) would otherwise hit a
  // confusing generic browser network error if submitted while offline —
  // catch that here with a plain message instead.
  document.addEventListener('submit', function(e){
    if (!navigator.onLine && e.target && e.target.tagName === 'FORM' && e.target.method.toLowerCase() === 'post'){
      e.preventDefault();
      alert('You\'re offline right now, so this can\'t be saved. Reconnect and try again.');
    }
  }, true);
})();
</script>
