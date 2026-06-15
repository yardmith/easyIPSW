<?php

use React\ChildProcess\Process;
use React\EventLoop\Loop;
use React\EventLoop\LoopInterface;

require_once "vendor/autoload.php";

$redis = new Redis();
$redis->connect("127.0.0.1", 6379);
$redis->setOption(Redis::OPT_SERIALIZER, Redis::SERIALIZER_PHP);

function _forEachJob($do, $ipswId = null, $action = null, $data = [], $jobId = null) {
  global $redis;

  $jobs = $redis->lrange("jobs", 0, -1);
  foreach ($jobs as $index => $job) {
    if (($job["id"] == $jobId || !$jobId) && ($job["ipswId"] == $ipswId || !$ipswId) && ($job["action"] == $action || !$action) && empty(array_diff($data, $job["data"]))) {
      $result = $do($job, $index);
      if ($result !== null) return $result;
    }
  }
}

function addJob($ipswId, $action, $data = []) {
  global $redis;
  if (!$ipswId) return;

  $job = [
    "id" => uniqid(more_entropy: true),
    "started_at" => time(),
    "ipswId" => $ipswId,
    "action" => $action,
    "data" => $data
  ];

  $redis->rPush("jobs", $job);
  return $job;
}

function editJobData($job, $newData, $recursive_merge = false) {
  global $redis;

  $index = $redis->lPos("jobs", $job);
  if ($recursive_merge)
    $job["data"] = array_merge_recursive($job["data"], $newData);
  else
    $job["data"] = $newData;
  
  $redis->lSet("jobs", $index, $job);
  return $job;
}

function getOngoingJob($ipswId, $action, $data = []) {
  if (!$ipswId) return false;

  return _forEachJob(function($job) {
    return $job;
  }, $ipswId, $action, $data);
}

function getJobFromId($id) {
  return _forEachJob(function($job) {
    return $job;
  }, jobId: $id);
}

function removeJob($job, $error = null, $data = []) {
  global $redis;

  $status = $error ? "error" : "done";
  $data = $error ? ["message" => $error] + $data : $data;

  $redis->setex("job_" . $job["id"] . "_result", JOB_CLEAR_RESULT_AFTER_SECONDS, [
    "status" => $status,
    "data" => $data
  ]);

  publishJobProgress($job, $status, $data);
  $redis->lrem("jobs", $job);
}

function purgeJobs($ipswId = null) {
  _forEachJob(function($job) {
    removeJob($job);
  }, $ipswId);
}

function publishJobProgress($job, $status, $data = []) {
  global $redis;

  $redis->publish("job_" . $job["id"] . "_progress", serialize([
    "job" => $job,
    "status" => $status,
    "data" => $data
  ]));
}

function _subscribeToJob($job, $callback, LoopInterface|null $loop = null) {
  global $redis;
  if ($loop === null) $loop = Loop::get();

  $redis->subscribe(["job_" . $job["id"] . "_progress"], function($redis, $channel, $message) use ($callback, $job) {
    $callback($message["status"], $message["data"]);

    if ($message["status"] == "done" || $message["status"] == "error") {
      $redis->unsubscribe(["job_" . $job["id"] . "_progress"]);
    }
  });
}

function subscribeToJobAsync($job, $callback, LoopInterface|null $loop = null) {
  if ($loop === null) $loop = Loop::get();

  $process = new Process("php " . escapeshellarg(JOB_SUBSCRIBE_SCRIPT) . " " . escapeshellarg($job["id"]));
  $process->start();

  $process->stdout->on("data", function($output) use ($callback) {
    if ($output == "nojob") return;

    $output = explode("|", $output);

    $status = $output[0];
    $data = unserialize($output[1]);
    $callback($status, $data);
  });
}