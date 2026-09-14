<?php
/* Settings page: the master scanning on/off switch, the posted duty hours,
   and who is holding each of the 2 marshal slots right now.

   THIS USED TO LIVE IN THE SIDEBAR (includes/sidebar.php's Admin/OSA branch),
   folded into a <details> in the dark rail. It moved here because everything
   it does is a setting — a switch, a schedule and a roster override — and a
   setting belongs on the settings page rather than riding along on every
   single screen in the app. The rail is navigation again.

   Included from admin/setting.php, which has already required functions.php
   and opened $conn; $assetBase comes from includes/admin_header.php.

   BOTH slots are always drawn, filled or empty, so "how many are out there"
   is answerable at a glance instead of by counting rows.

   See the "ON-DUTY MARSHAL SYSTEM" and duty-schedule notes in
   includes/functions.php for where the 2-slot cap and the hours are
   actually enforced — this panel only reports and switches. */
$vtsScanningOn = true;
$vtsOnDuty     = [];
if (isset($conn)) {
    try { $vtsScanningOn = vts_scanning_enabled($conn); } catch (Throwable $e) {}
    try { $vtsOnDuty     = vts_on_duty_roster($conn); } catch (Throwable $e) {}
}
$vtsWindowOpen = function_exists('vts_duty_window_open') ? vts_duty_window_open() : true;
$vtsHours      = function_exists('vts_duty_window_label') ? vts_duty_window_label() : '';
$vtsNextOpen   = function_exists('vts_duty_next_open') ? vts_duty_next_open() : '';
$vtsOnDuty     = array_slice($vtsOnDuty, 0, 2);   // there are only ever 2 slots
?>
<?php /* Its own card, and it has to be one: the three controls below are three
         separate POSTs, and a <form> cannot be nested inside the settings form
         that follows it. */ ?>
<div class="recent-table-wrap is-panel u-mb-14">
  <div class="duty-head">
    <h2 class="duty-title"><i class="fas fa-satellite-dish" aria-hidden="true"></i> Scanner &amp; duty</h2>
    <span class="duty-count" id="dutyCount"><?php echo count($vtsOnDuty); ?>/2 on duty</span>
  </div>
  <p class="duty-hint">
    The master switch for every marshal's phone, the hours it will let anyone sign on,
    and who is holding a slot right now.
  </p>

  <?php /* Two columns on a wide card, one when it narrows — so it stays usable
           at every width the sidebar leaves it. */ ?>
  <div class="duty-cols">

    <section class="duty-col">
      <div class="duty-switch-row">
        <div class="duty-switch-text">
          <span class="duty-label">Scanning</span>
          <span class="duty-sub">Turns the scanner on or off for every marshal at once.</span>
        </div>
        <form method="POST" action="<?php echo $assetBase; ?>admin/toggle_scanning.php" class="duty-inline-form">
          <?php echo csrf_field(); ?>
          <?php echo vts_return_field(); ?>
          <input type="hidden" name="enabled" value="<?php echo $vtsScanningOn ? '0' : '1'; ?>">
          <label class="scanner-switch" title="Turn the scanner feature on or off for every marshal">
            <input type="checkbox" <?php echo $vtsScanningOn ? 'checked' : ''; ?> onchange="this.form.submit()">
            <span class="scanner-switch-track" aria-hidden="true"></span>
            <span class="scanner-switch-text" id="dutyScanningLabel"><?php echo $vtsScanningOn ? 'On' : 'Off'; ?></span>
          </label>
          <?php /* The switch submits through onchange, which is nothing at all
                   without scripts — and this is the control that stops the
                   gate. It gets a real submit button, hidden because the switch
                   beside it already reads as the control. */ ?>
          <button type="submit" class="sr-only">
            Turn scanning <?php echo $vtsScanningOn ? 'off' : 'on'; ?>
          </button>
        </form>
      </div>

      <?php /* The schedule, stated rather than implied — otherwise "nobody can
               go on duty" at 6:05pm looks like a fault instead of closing time. */ ?>
      <p class="duty-hours<?php echo $vtsWindowOpen ? '' : ' is-closed'; ?>" id="dutyHours">
        <i class="fas fa-<?php echo $vtsWindowOpen ? 'clock' : 'moon'; ?>" aria-hidden="true"></i>
        <?php if ($vtsWindowOpen): ?>
          Open now · <?php echo htmlspecialchars($vtsHours); ?>
        <?php else: ?>
          Closed · opens <?php echo htmlspecialchars($vtsNextOpen); ?>
        <?php endif; ?>
      </p>

      <form method="POST" action="<?php echo $assetBase; ?>admin/save_duty_hours.php" class="duty-form">
        <?php echo csrf_field(); ?>
        <?php echo vts_return_field(); ?>

        <span class="duty-legend">Days</span>
        <div class="duty-days">
          <?php
            /* Mon-first, because the week the school runs on starts there and
               date('N') numbers it that way too — no re-mapping. */
            $dayLabels = [1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun'];
            $dayNames  = [1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday',
                          5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday'];
            $onDays = function_exists('vts_duty_days') ? vts_duty_days() : [1,2,3,4,5,6];
          ?>
          <?php foreach ($dayLabels as $n => $letter): ?>
            <label class="duty-day" title="<?php echo $dayNames[$n]; ?>">
              <input type="checkbox" name="days[]" value="<?php echo $n; ?>"
                     <?php echo in_array($n, $onDays, true) ? 'checked' : ''; ?>>
              <span><?php echo $letter; ?></span>
            </label>
          <?php endforeach; ?>
        </div>

        <div class="duty-times">
          <label>
            <span class="duty-legend">Opens</span>
            <input type="time" name="start" class="vts-input" required
                   value="<?php echo htmlspecialchars(vts_duty_config()['start']); ?>">
          </label>
          <label>
            <span class="duty-legend">Closes</span>
            <input type="time" name="end" class="vts-input" required
                   value="<?php echo htmlspecialchars(vts_duty_config()['end']); ?>">
          </label>
        </div>

        <button type="submit" class="btn-outline duty-save"><i class="fas fa-check"></i> Save duty hours</button>
      </form>
    </section>

    <section class="duty-col">
      <span class="duty-legend">Marshal slots</span>
      <div class="duty-list" id="dutyList">
        <?php if (!$vtsScanningOn): ?>
          <p class="duty-empty">Scanning is off — nobody can go on duty.</p>
        <?php else: for ($i = 0; $i < 2; $i++): $m = $vtsOnDuty[$i] ?? null; ?>
          <?php if ($m): ?>
            <div class="duty-row">
              <i class="fas fa-user-shield" aria-hidden="true"></i>
              <span class="duty-name"><?php echo htmlspecialchars($m['fullname']); ?></span>
              <span class="duty-id"><?php echo htmlspecialchars($m['student_id'] ?: $m['username']); ?></span>
              <?php /* Only 2 slots exist, so "end this shift" has to be reachable —
                       it is what the third marshal's refusal message tells them to
                       come and ask for. */ ?>
              <form method="POST" action="<?php echo $assetBase; ?>admin/sign_off_duty.php" class="duty-inline-form"
                    onsubmit="return confirm('Sign <?php echo htmlspecialchars(addslashes($m['fullname']), ENT_QUOTES); ?> off the scanner? Their slot frees up immediately; scans already on the phone are not affected.');">
                <?php echo csrf_field(); ?>
                <?php echo vts_return_field(); ?>
                <input type="hidden" name="user_id" value="<?php echo (int)$m['id']; ?>">
                <button type="submit" class="duty-off" title="End this shift and free the slot">
                  <i class="fas fa-right-from-bracket" aria-hidden="true"></i><span class="sr-only">Sign off</span>
                </button>
              </form>
            </div>
          <?php else: ?>
            <div class="duty-row is-empty">
              <i class="fas fa-user-slash" aria-hidden="true"></i>
              <span class="duty-name">Slot <?php echo $i + 1; ?> — free</span>
            </div>
          <?php endif; ?>
        <?php endfor; endif; ?>
      </div>
      <p class="duty-note">
        <i class="fas fa-circle-info" aria-hidden="true"></i>
        Two marshals can scan at a time. Signing one off frees the slot straight away —
        scans already on their phone are safe.
      </p>
    </section>

  </div>
</div>
<script>
/* Poll every 20s — the same cadence the scanner itself re-checks at — so a
   marshal signing on or off shows up here without a page reload. Best-effort:
   a failed fetch just leaves the last-known state on screen. */
(function(){
  var box   = document.getElementById('dutyList');
  var lbl   = document.getElementById('dutyScanningLabel');
  var count = document.getElementById('dutyCount');
  var hours = document.getElementById('dutyHours');
  if (!box) return;
  var OFF_ACTION  = <?php echo json_encode($assetBase . 'admin/sign_off_duty.php'); ?>;
  var CSRF_HTML   = <?php echo json_encode(csrf_field()); ?>;
  var RETURN_HTML = <?php echo json_encode(vts_return_field()); ?>;

  function esc(s){
    return (s||'').toString().replace(/[<>&"']/g, function(c){
      return {'<':'&lt;','>':'&gt;','&':'&amp;','"':'&quot;',"'":'&#39;'}[c];
    });
  }

  function render(d){
    var roster = (d.roster || []).slice(0, 2);
    if (lbl)   lbl.textContent = d.enabled ? 'On' : 'Off';
    if (count) count.textContent = roster.length + '/2 on duty';
    if (hours) {
      hours.className = 'duty-hours' + (d.window_open ? '' : ' is-closed');
      hours.innerHTML = '<i class="fas fa-' + (d.window_open ? 'clock' : 'moon') + '" aria-hidden="true"></i> '
                      + (d.window_open ? 'Open now · ' + esc(d.hours)
                                       : 'Closed · opens ' + esc(d.next_open));
    }
    if (!d.enabled){
      box.innerHTML = '<p class="duty-empty">Scanning is off — nobody can go on duty.</p>';
      return;
    }
    var html = '';
    for (var i = 0; i < 2; i++){
      var m = roster[i];
      if (m){
        /* The sign-off form is rebuilt here too, or it would vanish on the
           first poll — same CSRF token the server-rendered rows carry. */
        html += '<div class="duty-row"><i class="fas fa-user-shield" aria-hidden="true"></i>'
              + '<span class="duty-name">' + esc(m.name) + '</span>'
              + '<span class="duty-id">' + esc(m.school_id) + '</span>'
              + '<form method="POST" action="' + OFF_ACTION + '" class="duty-inline-form" '
              + 'onsubmit="return confirm(\'Sign ' + esc(m.name).replace(/'/g, '&#39;') + ' off the scanner?\')">'
              + CSRF_HTML + RETURN_HTML
              + '<input type="hidden" name="user_id" value="' + (parseInt(m.id, 10) || 0) + '">'
              + '<button type="submit" class="duty-off" title="End this shift and free the slot">'
              + '<i class="fas fa-right-from-bracket" aria-hidden="true"></i></button></form>'
              + '</div>';
      } else {
        html += '<div class="duty-row is-empty"><i class="fas fa-user-slash" aria-hidden="true"></i>'
              + '<span class="duty-name">Slot ' + (i + 1) + ' — free</span></div>';
      }
    }
    box.innerHTML = html;
  }

  function poll(){
    fetch(<?php echo json_encode($assetBase . 'admin/on_duty_status.php'); ?>, {cache:'no-store'})
      .then(function(r){ return r.ok ? r.json() : null; })
      .then(function(d){ if (d && d.ok) render(d); })
      .catch(function(){});
  }
  setInterval(poll, 20000);
})();
</script>
