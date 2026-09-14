<?php
/* Shared role-aware sidebar navigation. */
$role = $_SESSION['role'] ?? '';
$assetBase = isset($assetBase) ? $assetBase : '../';

// Active page helper
function isActive($page) {
    $current = basename($_SERVER['PHP_SELF']);
    return ($current === $page) ? 'active' : '';
}

// Department shortcuts (Admin/OSA): jump straight to a department's Official
// Sheet, where "Export to Excel" produces the official-sheet-formatted report.
$vtsDepartments = [];
if (in_array($role, ['Admin', 'OSA'], true) && isset($conn)) {
    try { $vtsDepartments = $conn->query("SELECT id, college_name FROM colleges ORDER BY college_name")->fetchAll(PDO::FETCH_ASSOC); }
    catch (Throwable $e) { $vtsDepartments = []; }
}
$vtsCurrentCollege = isset($_GET['college_id']) ? (string)$_GET['college_id'] : '';
?>
<aside class="vts-sidebar" id="vtsSidebar">
  <div class="sidebar-brand">
    <button type="button" class="menu-tab" id="hamburgerBtn" onclick="toggleSidebar()" title="Show / hide menu" aria-label="Menu">
      <i class="fas fa-bars"></i>
    </button>
    <span class="sidebar-menu-label">Menu</span>
  </div>
  <nav class="sidebar-nav">
    <?php if ($role === 'Student'): ?>
      <a href="<?php echo $assetBase; ?>student/dashboard.php" class="<?php echo isActive('dashboard.php'); ?>">
        <i class="fas fa-th-large"></i> Dashboard
      </a>
      <a href="<?php echo $assetBase; ?>student/profile.php" class="<?php echo isActive('profile.php'); ?>">
        <i class="fas fa-user"></i> Profile
      </a>
      <a href="<?php echo $assetBase; ?>student/violations.php" class="<?php echo isActive('violations.php'); ?>">
        <i class="fas fa-exclamation-triangle"></i> Violations
      </a>
      <a href="<?php echo $assetBase; ?>student/qr.php" class="<?php echo isActive('qr.php'); ?>">
        <i class="fas fa-qrcode"></i> QR Code
      </a>

    <?php elseif ($role === 'Guard'): ?>
      <?php /* Same dead path as index.php had: guard/ does not exist, and the
               .htaccess catch-all turns a link to it into a redirect loop. The
               scanner is the marshal's home screen. */ ?>
      <a href="<?php echo $assetBase; ?>spck_scanner.html">
        <i class="fas fa-th-large"></i> Scanner
      </a>
      <a href="<?php echo $assetBase; ?>spck_scanner.html">
        <i class="fas fa-exclamation-triangle"></i> Issue Violation
      </a>
      <a href="<?php echo $assetBase; ?>account.php" class="<?php echo isActive('account.php'); ?>">
        <i class="fas fa-user-gear"></i> My Profile
      </a>


    <?php elseif ($role === 'OSA Staff'): ?>
      <a href="<?php echo $assetBase; ?>osa_staff/dashboard.php" class="<?php echo isActive('dashboard.php'); ?>">
        <i class="fas fa-th-large"></i> Dashboard
      </a>
      <a href="<?php echo $assetBase; ?>osa_staff/students.php" class="<?php echo isActive('students.php'); ?>">
        <i class="fas fa-user-graduate"></i> Students
      </a>
      <!-- Violations and Reports are one page (Official Sheet view = the report) -->
      <a href="<?php echo $assetBase; ?>osa_staff/violations.php" class="<?php echo isActive('violations.php') ?: isActive('reports.php'); ?>">
        <i class="fas fa-list"></i> Violations &amp; Reports
      </a>
      <a href="<?php echo $assetBase; ?>account.php" class="<?php echo isActive('account.php'); ?>">
        <i class="fas fa-user-gear"></i> My Profile
      </a>

    <?php elseif (in_array($role, ['Admin', 'OSA'], true)): ?>
      <?php
        $adminBadge = 0;
        if (isset($conn)) {
            try { $adminBadge = (int)$conn->query("SELECT COUNT(*) FROM notifications WHERE is_read=0")->fetchColumn(); }
            catch (Throwable $e) { $adminBadge = 0; }
        }
      ?>
      <?php /* The On-duty panel used to sit here, above the first nav link.
               It is on the Settings page now (includes/on_duty_panel.php,
               included from admin/setting.php): the scanning switch, the duty
               hours and the slot roster are all settings, and settings do not
               need to ride along on every screen in the app. The rail is
               navigation again. */ ?>
      <a href="<?php echo $assetBase; ?>admin/dashboard.php" class="<?php echo isActive('dashboard.php'); ?>">
        <i class="fas fa-house"></i> Dashboard
      </a>
      <a href="<?php echo $assetBase; ?>admin/students.php" class="<?php echo isActive('students.php'); ?>">
        <i class="fas fa-user-graduate"></i> Students
      </a>
      <!-- Violations and Reports are one page: the Official Sheet view IS the
           report, and the Records view is the same data with row actions.

           The departments used to hang below this as a flat "By Department"
           list, so every page in the app carried five permanent extra rows of
           navigation for a filter most visits never use. They fold into this
           item now.

           A <details> and not a script: the sidebar is on every page, it must
           work before JS runs, and the open/closed state has to survive a full
           page load — which it does here because `open` is decided on the
           server from where you already are, not remembered in the browser. -->
      <?php
        $violActive = (isActive('violations.php') ?: isActive('reports.php')) !== '';
        /* Shut by default — it is a drawer, and a drawer that is open every
           time you arrive is just a list again. The one exception is a
           department actually being filtered to: then the open list is what
           tells you WHICH, and the highlighted row is worth the space.

           This is only the starting position. Once someone opens or closes it
           by hand, vts-ui.js remembers that and overrides this on every later
           page — see "SIDEBAR DROPDOWN" there. */
        $violOpen   = $vtsCurrentCollege !== '';
      ?>
      <?php if ($vtsDepartments): ?>
      <details class="sidebar-drop" data-remember="violations"<?php echo $violOpen ? ' open' : ''; ?>>
        <summary class="sidebar-drop-head<?php echo $violActive ? ' active' : ''; ?>">
          <i class="fas fa-triangle-exclamation"></i>
          <span class="sidebar-drop-label">Violations &amp; Reports</span>
          <i class="fas fa-chevron-down sidebar-drop-caret" aria-hidden="true"></i>
        </summary>
        <?php /* The summary toggles rather than navigates, so the page itself
                 needs a row of its own or it becomes unreachable from here. */ ?>
        <a class="sidebar-sub <?php echo ($violActive && $vtsCurrentCollege === '') ? 'active' : ''; ?>"
           href="<?php echo $assetBase; ?>admin/violations.php">
          <i class="fas fa-layer-group"></i> All departments
        </a>
        <?php foreach ($vtsDepartments as $d):
          $short = preg_replace('/^Department of\s+/i', '', $d['college_name']);
          $on = ($vtsCurrentCollege === (string)$d['id']) ? 'active' : '';
          // Each department gets its OWN icon and colour rather than four
          // identical building icons, so the right one is found by shape
          // instead of by reading down the list every time.
          $dId = vts_dept_identity($d['college_name']); ?>
          <a class="sidebar-sub <?php echo $on; ?>"
             href="<?php echo $assetBase; ?>admin/violations.php?college_id=<?php echo (int)$d['id']; ?>&view=sheet"
             title="Open the <?php echo htmlspecialchars($d['college_name']); ?> Official Sheet, then Export to Excel">
            <i class="fas <?php echo $dId['icon']; ?>" style="color:<?php echo $dId['color']; ?>;"></i> <?php echo htmlspecialchars($short); ?>
          </a>
        <?php endforeach; ?>
      </details>
      <?php else: /* No departments on file — a dropdown holding one item is
                     just a link with an extra click in front of it. */ ?>
      <a href="<?php echo $assetBase; ?>admin/violations.php" class="<?php echo $violActive ? 'active' : ''; ?>">
        <i class="fas fa-triangle-exclamation"></i> Violations &amp; Reports
      </a>
      <?php endif; ?>
      <?php /* Sits under Violations & Reports because it answers the question
               a disputed record raises: does the photo actually show what the
               record claims? It carries no count — it is a place to look, not
               a queue that fills up. */ ?>
      <a href="<?php echo $assetBase; ?>admin/proof.php" class="<?php echo isActive('proof.php'); ?>">
        <i class="fas fa-camera"></i> Violation Proof
      </a>
      <a href="<?php echo $assetBase; ?>admin/users.php" class="<?php echo isActive('users.php'); ?>">
        <i class="fas fa-users"></i> Users
      </a>
      <a href="<?php echo $assetBase; ?>admin/statistic.php" class="<?php echo isActive('statistic.php'); ?>">
        <i class="fas fa-chart-pie"></i> Statistics
      </a>
      <a href="<?php echo $assetBase; ?>admin/backup.php" class="<?php echo isActive('backup.php'); ?>">
        <i class="fas fa-database"></i> Backup
      </a>

      <?php /* ---- ADMIN ONLY ----

               Violation Types was already here on its own. Audit Log and
               Settings joined it: the three of them are the system being
               CONFIGURED or AUDITED rather than operated.

                 Violation Types  what may be recorded at all, and how serious
                                  it is — a policy decision, so OSA record
                                  against the list but do not set it
                 Audit Log        the record of what everyone with an account
                                  did, OSA included — so the people it reports
                                  on are not the people who read it
                 Settings         the school's details, the export folders,
                                  and the Scanner & duty card that switches the
                                  gate on and off and posts its hours — OSA
                                  work the gate, they do not set it up

               The pages themselves refuse anyone but Admin (each one's own
               guard). This only stops the menu offering a door that is locked;
               hiding a link is presentation, never the permission. */ ?>
      <?php if ($role === 'Admin'): ?>
      <a href="<?php echo $assetBase; ?>admin/violation_rules.php" class="<?php echo isActive('violation_rules.php'); ?>">
        <i class="fas fa-list-check"></i> Violation Types
      </a>
      <a href="<?php echo $assetBase; ?>admin/audit_log.php" class="<?php echo isActive('audit_log.php'); ?>">
        <i class="fas fa-clipboard-list"></i> Audit Log
      </a>
      <a href="<?php echo $assetBase; ?>admin/setting.php" class="<?php echo isActive('setting.php'); ?>">
        <i class="fas fa-gear"></i> Settings
      </a>
      <?php endif; ?>
    <?php endif; ?>
  </nav>
</aside>
