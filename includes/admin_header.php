<?php
/* Admin layout header — now uses the SAME shell as every other role.
   Set $adminActive (e.g. 'dashboard') before including. */
$assetBase   = '../';
$adminActive = $adminActive ?? '';

$adminNotifCount = 0;
if (isset($conn)) {
    try {
        $adminNotifCount = (int)$conn->query("SELECT COUNT(*) FROM notifications WHERE is_read=0")->fetchColumn();
    } catch (Throwable $e) { $adminNotifCount = 0; }
}
function adminNav($key, $current) { return $key === $current ? 'active' : ''; }

include __DIR__ . "/header.php";
include __DIR__ . "/navbar.php";
?>
<?php include __DIR__ . "/sidebar.php"; ?>
<main class="vts-main">
<div class="vts-main-inner">
