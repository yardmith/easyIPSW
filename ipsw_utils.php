<?php

use React\EventLoop\LoopInterface;

require_once "db.php";

function getDirListing($path) {
  $listing = array_values(array_diff(scandir($path), [".", ".."]));
  $files = [];
  $dirs = [];

  for ($i = 0; $i < count($listing); $i++) {
    $name = $listing[$i];
    if (is_dir("$path/$name") && pathinfo($name, PATHINFO_EXTENSION) != "dmg") {
      array_push($dirs, $name);
    } else {
      array_push($files, $name);
    }
  }

  return ["files" => $files, "directories" => $dirs];
}

function ipswIsCached($id) {
  return is_dir(CACHE_DIR . "/$id");
}

function extractCachedIpsw($cachePath, $extractProgressCallback, $completedCallback, $errorCallback, LoopInterface $loop) {
  $ipsw = new ZipArchive;
  if (!$ipsw->open("$cachePath/ipsw.zip")) {
    $errorCallback("Failed to unzip IPSW.");
    return;
  }

  $totalFiles = $ipsw->numFiles;
  $extractProgressCallback(0, $totalFiles);

  $i = 0;
  $timer = $loop->addPeriodicTimer(0, function() use (&$i, $ipsw, $totalFiles, $cachePath, $extractProgressCallback, $completedCallback, &$timer, &$loop) {
    if ($i >= $totalFiles) {
      unlink("$cachePath/ipsw.zip");
      $ipsw->close();
      /** @disregard */
      $loop->cancelTimer($timer);
      $completedCallback();
      return;
    }
    $ipsw->extractTo($cachePath, [$ipsw->getNameIndex($i)]);
    $extractProgressCallback($i + 1, $totalFiles);
    $i++;
  });
}

function cacheIpswContents($id, LoopInterface $loop, $downloadProgressCallback, $extractProgressCallback, $completedCallback, $errorCallback) {
  global $db;

  if (!array_key_exists($id, $db["ipsw"])) {
    return false;
  }
  if (!array_key_exists("url", $db["ipsw"][$id])) {
    return false;
  }

  $cachePath = CACHE_DIR . "/$id";
  if (is_dir($cachePath)) {
    if (is_file("$cachePath/ipsw.zip")) {
      extractCachedIpsw($cachePath, $extractProgressCallback, $completedCallback, $errorCallback, $loop);
    } else {
      $completedCallback();
    }
    return $cachePath;
  }

  mkdir($cachePath, recursive: true);

  $ipswUrl = $db["ipsw"][$id]["url"];
  $source = fopen($ipswUrl, "r");
  $destination = fopen("$cachePath/ipsw.zip", "a");
  
  if ($source) {
    $currentBytes = 0;
    $totalBytes = get_headers($ipswUrl, true)["Content-Length"];
    $prevCurrentBytes = 0;

    $downloadProgressCallback(0, $totalBytes);

    $timer = $loop->addPeriodicTimer(0, function() use (&$source, &$destination, &$currentBytes, &$prevCurrentBytes, $downloadProgressCallback, &$totalBytes, &$loop, &$timer, $cachePath, $extractProgressCallback, $completedCallback, $errorCallback) {
      if (feof($source)) {
        $downloadProgressCallback($totalBytes, $totalBytes);
        fclose($source);
        fclose($destination);
        /** @disregard */
        $loop->cancelTimer($timer);
        extractCachedIpsw($cachePath, $extractProgressCallback, $completedCallback, $errorCallback, $loop);
        return;
      }
    
      $chunk = fread($source, 8192);
      fwrite($destination, $chunk);
      $currentBytes += strlen($chunk);
      
      if ($currentBytes >= $prevCurrentBytes + DOWNLOAD_PROGRESS_INTERVAL_BYTES) {
        $downloadProgressCallback($currentBytes, $totalBytes);
        $prevCurrentBytes = $currentBytes;
      }
    });
  }

  return $cachePath;
}