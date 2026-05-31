<?php

use React\EventLoop\LoopInterface;

require_once "db.php";
require_once "constants.php";

function identifyImg($path) {
  $start = file_get_contents($path, length: 10);

  if (str_starts_with($start, "8900")) {
    return 2;
  } elseif (str_starts_with($start, "3gmI")) {
    return 3;
  } elseif (str_starts_with($start, "0") && str_contains($start, "IM4P")) {
    return 4;
  } else {
    return false;
  }
}

function img2IsEncrypted($path) {
  if (ord(file_get_contents($path, offset: 7, length: 1)) == 3) {
    return true;
  } else {
    return false;
  }
}

function getKeysFromPath($path) {
  global $db;

  $pathParts = explode("/", $path);
  $cacheIdx = array_search(CACHE_DIR, $pathParts);
  if ($cacheIdx === false || count($pathParts) <= $cacheIdx + 1) {
    return false;
  }

  $ipswId = $pathParts[$cacheIdx + 1];
  $filename = pathinfo($path, PATHINFO_BASENAME);
  if (!array_key_exists("keys", $db["ipsw"][$ipswId]) || !array_key_exists($filename, $db["ipsw"][$ipswId]["keys"])) {
    return false;
  }

  return $db["ipsw"][$ipswId]["keys"][$filename];
}

function decryptImg($path) {
  $type = identifyImg($path);

  if ($type > 2) {
    $keys = getKeysFromPath($path);
    if (!$keys) return false;
  }

  rename($path, "$path.enc");

  switch ($type) {
    case 2:
      exec("bin/xpwntool $path.enc $path");
      break;
    case 3:
      exec("bin/xpwntool $path.enc $path -k " . $keys["key"] . " -iv " . $keys["iv"]);
      break;
    case 4:
      exec("bin/img4 -i $path.enc -o $path -k " . $keys["iv"] . $keys["key"]);
      break;
    default:
      return false;
  }
}

function extractDmg($path) {
  if (identifyImg($path) !== false) {
    if (decryptImg($path) === false) return false;
  } else {
    $keys = getKeysFromPath($path);
    if (!$keys) return false;
    exec("bin/dmg extract $path $path -k " . $keys["key"]);
  }

  $dirname = pathinfo($path, PATHINFO_DIRNAME);
  $oldList = scandir($dirname);
  exec("bin/7zz x -o" . $dirname . " -y $path");
  $dir = array_values(array_diff(scandir($dirname), $oldList))[0];
  unlink($path);
  rename($dirname . "/$dir", $path);
}

function getDirListing($path) {
  $listing = array_values(array_diff(scandir($path), [".", ".."]));
  $files = [];
  $dirs = [];

  for ($i = 0; $i < count($listing); $i++) {
    $name = $listing[$i];

    //if (in_array($name, [".HFS+ Private Directory Data", ".HFS+ Private Directory Data\r", ".Trashes", "[HFS+ Private Data]"])) continue;

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
    $errorCallback("Failed to unzip IPSW");
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
        //$downloadProgressCallback($totalBytes, $totalBytes);
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