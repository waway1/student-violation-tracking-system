<?php
/* Reports was merged into Violations — the "Official Sheet" view there IS the
   report (same filters, same layout, same Excel export), so keeping a second
   page showing the same thing only split the workflow in two.
   This file stays so old links/bookmarks keep working. */
require_once "../auth/auth.php";

$qs = $_GET;
unset($qs['view']);
$qs['view'] = 'sheet';
header("Location: violations.php?" . http_build_query($qs));
exit();
