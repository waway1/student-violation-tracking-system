<?php
/* DEPRECATED (2026-08 role restructure): the online-only kiosk scanner has
   been replaced by spck_scanner.html — a single hybrid PWA that works
   fully offline AND auto-syncs when online, so guards no longer need two
   separate scanner tools. This stub just forwards old bookmarks/links. */
header("Location: spck_scanner.html");
exit();
