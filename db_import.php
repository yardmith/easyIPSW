<?php

require_once "db_utils.php";

if (PHP_SAPI !== "cli") {
  http_response_code(403);
  exit;
}

function getPaginatedResults($url) {
  $offset = 0;
  $results = [];

  while (true) {
    $response = unserialize(file_get_contents(str_replace("offset=0", "offset=" . $offset, $url)));
    $results += $response["query"]["results"];
    if (!isset($response["query-continue-offset"])) break;
    $offset = $response["query-continue-offset"];
  }

  return $results;
}

function grabKeysForResults($results) {
  global $db;

  // Get list of key pages (IPSWs)
  $keyPageToIpswId = [];
  $params = [];
  foreach ($results as $keyPage => $info) {
    $printouts = $info["printouts"];
    if (!isset($printouts["url"][0])) continue;
    $device = $printouts["device"][0];

    $osName = $printouts["os"][0]["displaytitle"];
    $version = str_replace(["[[Release Candidate|RC]]", "[[Golden Master|GM]]"], ["RC", "GM"], $printouts["version"][0]);
    if ($osName == "iOS" && floatval($version) < 4) $osName = "iPhone OS";
    $versionString = $osName . " " . $version;

    $url = $printouts["url"][0];

    $ipswId = basename($url);
    if (pathinfo($ipswId, PATHINFO_EXTENSION) != "ipsw") continue;
    $keyPageToIpswId[$keyPage] = $ipswId;
    array_push($params, $ipswId, $device, $versionString, $url, str_contains(strtolower($version), "beta") ? 2 : 1);
  }
  if (empty($params)) {
    error_log("No matching IPSWs were found");
    return;
  }
  $db->runQuery("INSERT INTO ipsw (id, device_id, version_string, url, type) VALUES " . substr(str_repeat(", (?, ?, ?, ?, ?)", count($keyPageToIpswId)), 2) . " ON CONFLICT(id) DO NOTHING", $params);

  // Get keys for each IPSW from these pages
  foreach (array_chunk(array_keys($keyPageToIpswId), 10) as $chunk) {
    $apiRequestUrl = "https://theapplewiki.com/api.php?action=ask&format=php&query=[[-Has%20subobject::" . urlencode(implode(" || ", $chunk)) . "]]|?Has%20key=key|?Has%20key%20IV=iv|?Has%20filename=filename|limit=10000|offset=0";
    $results = getPaginatedResults($apiRequestUrl);
    $params = [];
    foreach ($results as $name => $info) {
      $printouts = $info["printouts"];
      $ipswId = $keyPageToIpswId[explode("#", $name)[0]];

      $key = $printouts["key"][0];
      if (isset($printouts["iv"][0]))
        $iv = $printouts["iv"][0];
      else
        $iv = null;
      if ($key == "Unknown" || $iv == "Unknown") continue;
      $filename = $printouts["filename"][0];
      if (pathinfo($filename, PATHINFO_EXTENSION) == "aea") continue;

      array_push($params, $ipswId, $filename, $key, $iv);
    }
    if (empty($params)) continue;
    $db->runQuery("INSERT INTO keys (ipsw_id, filename, key, iv) VALUES " . substr(str_repeat(", (?, ?, ?, ?)", count($params) / 4), 2) . " ON CONFLICT(ipsw_id, filename) DO NOTHING", $params);
    echo "Inserted " . count($params) / 4 . " keys\n";
  }
}

$options = getopt("v:d:h");

// Get devices
$devices = json_decode(file_get_contents("https://api.ipsw.me/v4/devices"), true);
$params = [];
foreach ($devices as $device) {
  array_push($params, $device["identifier"], $device["name"]);
}
$db->runQuery("INSERT INTO devices (id, name) VALUES " . substr(str_repeat(", (?, ?)", count($devices)), 2) . " ON CONFLICT(id) DO NOTHING", $params);

if (isset($options["v"]) || isset($options["d"])) {
  // If Apple releases a new firmware version, you can pass in the version as it appears on The Apple Wiki (i.e. "27.0 beta" or "18.0 RC and 18.0") to update the DB with only the new stuff
  // You can also do this with device identifiers (i.e. "iPhone2,1" or "iPhone18,4")
  if (isset($options["v"])) {
    $version = $options["v"];
    $results = getPaginatedResults("https://theapplewiki.com/api.php?action=ask&format=php&query=[[:Keys:%2B]][[Has%20firmware%20version::" . urlencode($version) . "]]|?Has%20firmware%20device=device|?Has%20firmware%20version=version|?Has%20operating%20system=os|?Has%20download%20URL=url|limit=10000|offset=0");
  } elseif (isset($options["d"])) {
    $device = $options["d"];
    $results = getPaginatedResults("https://theapplewiki.com/api.php?action=ask&format=php&query=[[:Keys:%2B]][[Has%20firmware%20device::" . urlencode($device) . "]]|?Has%20firmware%20device=device|?Has%20firmware%20version=version|?Has%20operating%20system=os|?Has%20download%20URL=url|limit=10000|offset=0");
  }

  grabKeysForResults($results);

  exit;
}

// If version or device isn't specified, continue with grabbing keys for every available IPSW in the database
echo "About to fetch info & keys for EVERY IPSW EVER!\n";
echo "Please do NOT continue unless you actually have a need for doing so.\n";
echo "Enter to continue, ^C to cancel\n";
readline();

// Loop through every device and grab all of their keys.
// This is not how I wanted to do this, but I was forced to due to pagination limits.
// Oh well, at least it's easier to implement
foreach ($devices as $key => $device) {
  $deviceId = $device["identifier"];
  echo "Grabbing keys for: $deviceId\n";
  $results = getPaginatedResults("https://theapplewiki.com/api.php?action=ask&format=php&query=[[:Keys:%2B]][[Has%20firmware%20device::" . urlencode($deviceId) . "]]|?Has%20firmware%20device=device|?Has%20firmware%20version=version|?Has%20operating%20system=os|?Has%20download%20URL=url|limit=10000|offset=0");
  grabKeysForResults($results);
}

echo "Done";