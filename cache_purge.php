<?php

chdir(__DIR__);

require_once "constants.php";
require_once "db.php";
require_once "cache_utils.php";

if (PHP_SAPI !== "cli") {
  http_response_code(403);
  exit;
}

foreach ($db["cache"] as $dir => $expires) {
  if (time() < $expires) continue;

  exec("rm -rf " . escapeshellarg(__DIR__ . "/" . CACHE_DIR . "/$dir"));
  removeCacheEntry($dir);
}