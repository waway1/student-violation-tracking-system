<?php
/* Reports was merged into Violations — the "Official Sheet" view there IS the
   report (same filters, same layout, same Excel export). This file stays so old
   links/bookmarks keep working. */
require_once "../auth/auth.php";

$qs = $_GET;
unset($qs['view']);
$qs['view'] = 'sheet';
header("Location: violations.php?" . http_build_query($qs));
exit();
