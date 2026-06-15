<?php

require_once "job_utils.php";

if (PHP_SAPI !== "cli") {
  http_response_code(403);
  exit;
}

$job = getJobFromId($argv[1]);
if (!$job) {
  $result = $redis->get("job_" . $argv[1] . "_result");

  if ($result) {
    echo $result["status"] . "|" . serialize($result["data"]);
  } else {
    echo "nojob";
  }

  exit;
}

_subscribeToJob($job, function($status, $data) {
  echo "$status|" . serialize($data);
});