<?php

chdir(__DIR__);

require_once "constants.php";
require_once "db_utils.php";
require_once "job_utils.php";

if (PHP_SAPI !== "cli") {
  http_response_code(403);
  exit;
}

if (in_array("-h", $argv) || in_array("--help", $argv)) {
  echo "Usage: " . basename(__FILE__) . " [-h] [-a] [-j]\n\n";
  echo "Purges expired IPSWs from the cache directory and the cache.json DB file. Will also purge any ongoing jobs associated with these IPSWs.\n\n";
  echo "Options:\n";
  echo "  -h, --help        Display this help and exit\n";
  echo "  -a, --all         Purge all cached IPSWs, not just expired ones\n";
  echo "  -j, --jobs-only   Purge jobs only, leave cache intact\n";
  exit;
}

foreach (getCacheEntries() as $dir => $expires) {
  if (time() < $expires && !in_array("-a", $argv) && !in_array("--all", $argv)) continue;

  purgeJobs($dir);
  if (in_array("-j", $argv) || in_array("--jobs-only", $argv)) continue;
  exec("rm -rf " . escapeshellarg(CACHE_DIR . "/$dir"));
  removeCacheEntry($dir);
}