<?php

require_once "constants.php";
require_once "db.php";

function updateExpireTimestamp($ipswId) {
  global $db;

  if (CACHE_DEBUG_MODE) return;
  if (!$ipswId) return;

  $db["cache"][$ipswId] = time() + CACHE_MAX_AGE;
  file_put_contents(DB_DIR . "/cache.json", json_encode($db["cache"]));
}

function removeCacheEntry($ipswId) {
  global $db;

  unset($db["cache"][$ipswId]);
  file_put_contents(DB_DIR . "/cache.json", json_encode($db["cache"]));
}