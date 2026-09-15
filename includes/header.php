<?php
/* Shared page <head> and top of the HTML shell. */
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}
$assetBase = isset($assetBase) ? $assetBase : '../';
require_once __DIR__ . '/../config/app.php';   // APP_NAME, APP_TAGLINE, APP_NAME_FULL
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?php echo htmlspecialchars(APP_NAME_FULL); ?> | Golden West Colleges</title>
  <!-- Inter + Plus Jakarta Sans, served from this server (assets/vendor/fonts).
       This used to be a fonts.googleapis.com <link> with a comment claiming a
       "graceful fallback ... if the CDN is unreachable". There is no such
       fallback: a stylesheet link is RENDER-BLOCKING, so with no internet the
       browser painted nothing at all until the request timed out. Vendored for
       the same reason the icons below already were. -->
  <link rel="stylesheet" href="<?php echo $assetBase; ?>assets/vendor/fonts/css/fonts.css">
  <link rel="stylesheet" href="<?php echo $assetBase; ?>assets/css/vts-theme.css?v=<?php echo @filemtime(__DIR__."/../assets/css/vts-theme.css"); ?>">
  <link rel="stylesheet" href="<?php echo $assetBase; ?>assets/css/vts-admin.css?v=<?php echo @filemtime(__DIR__."/../assets/css/vts-admin.css"); ?>">
  <link rel="stylesheet" href="<?php echo $assetBase; ?>assets/css/vts-polish.css?v=<?php echo @filemtime(__DIR__."/../assets/css/vts-polish.css"); ?>">
  <link rel="stylesheet" href="<?php echo $assetBase; ?>assets/css/vts-minimal.css?v=<?php echo @filemtime(__DIR__."/../assets/css/vts-minimal.css"); ?>">
  <link rel="stylesheet" href="<?php echo $assetBase; ?>assets/css/vts-dashboard.css?v=<?php echo @filemtime(__DIR__."/../assets/css/vts-dashboard.css"); ?>">
  <link rel="stylesheet" href="<?php echo $assetBase; ?>assets/css/vts-clean.css?v=<?php echo @filemtime(__DIR__."/../assets/css/vts-clean.css"); ?>">
  <!-- Form-validation presentation. Split out of vts-dashboard.css so the
       standalone auth pages can load the same rules. -->
  <link rel="stylesheet" href="<?php echo $assetBase; ?>assets/css/vts-forms.css?v=<?php echo @filemtime(__DIR__."/../assets/css/vts-forms.css"); ?>">
  <!-- Icons are served locally (assets/vendor/fontawesome) — no CDN wait,
       works offline, and the font is preloaded so icons paint instantly. -->
  <!-- The bell's notification panel (includes/navbar.php). -->
  <link rel="stylesheet" href="<?php echo $assetBase; ?>assets/css/vts-notifications.css?v=<?php echo @filemtime(__DIR__."/../assets/css/vts-notifications.css"); ?>">
  <!-- Listing tables become cards when they will not fit, instead of
       scrolling sideways and hiding the Action column. Must load AFTER
       vts-theme/vts-polish: it overrides the forced table width they set. -->
  <link rel="stylesheet" href="<?php echo $assetBase; ?>assets/css/vts-tables.css?v=<?php echo @filemtime(__DIR__."/../assets/css/vts-tables.css"); ?>">
  <link rel="preload" href="<?php echo $assetBase; ?>assets/vendor/fontawesome/webfonts/fa-solid-900.woff2" as="font" type="font/woff2" crossorigin>
  <link rel="stylesheet" href="<?php echo $assetBase; ?>assets/vendor/fontawesome/css/all.min.css">
  <style>
    /* The app shell used the same unidentifiable photograph as the auth
       pages did. It is the brand gradient now — one flat, opaque surface
       behind every dashboard, and one fewer 105KB image on every page. */
    body.has-bg-image {
      background: linear-gradient(160deg, #0f2a52 0%, #14375f 46%, #0b1f3f 100%);
      background-attachment: fixed;
    }
  </style>
  <!-- Design system: tokens, chrome and components. Loaded LAST so it
       settles anything the older stylesheets disagree about. -->
  <link rel="stylesheet" href="<?php echo $assetBase; ?>assets/css/vts-design.css?v=<?php echo @filemtime(__DIR__."/../assets/css/vts-design.css"); ?>">
</head>
<body class="has-bg-image">
<?php /* Back/forward must not thaw a page that belonged to another
         session. Must sit before the page content so it is listening
         the moment the restore happens. */ ?>
<?php include __DIR__ . '/bfcache_guard.php'; ?>
