<?php

use CFPropertyList\CFPropertyList;
use Psr\Http\Message\ResponseInterface;
use React\ChildProcess\Process;
use React\EventLoop\LoopInterface;
use React\Http\Browser;
use React\Stream\WritableResourceStream;
use Symfony\Component\Filesystem\Path;

require_once "db_utils.php";
require_once "constants.php";
require_once "job_utils.php";

function pathNeedsDmgExtraction($path) {
  if (!str_contains($path, ".dmg")) return false;
  $parts = explode(".dmg", $path);
  $dmgPath = $parts[0] . ".dmg";
  if (is_dir($dmgPath)) return false;
  return $dmgPath;
}

function identifyImg($path) {
  if (!is_file($path)) return false;  

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

function getIpswIdFromPath($path) {
  $path = Path::canonicalize($path);
  $pathParts = explode("/", $path);
  $cacheIdx = array_search(CACHE_DIR_NAME, $pathParts);
  if ($cacheIdx === false || count($pathParts) <= $cacheIdx + 1) {
    return false;
  }
  return $pathParts[$cacheIdx + 1];
}

function getKeyFromPath($path) {
  $ipswId = getIpswIdFromPath($path);
  if (!$ipswId) return false;
  $filename = basename($path);

  return getKeyForIpswFile($ipswId, $filename);
}

function decryptImg($path) {
  $type = identifyImg($path);

  $keys = getKeyFromPath($path);
  if (!$keys && $type > 2) return;

  rename($path, "$path.original");
  $result_code = null;
  $decPath = escapeshellarg("$path.decrypted");
  $encPath = escapeshellarg("$path.original");

  switch ($type) {
    case 2:
      exec(BIN_DIR . "xpwntool $encPath $decPath", result_code: $result_code);
      break;
    case 3:
      exec(BIN_DIR . "xpwntool $encPath $decPath -k " . $keys->key . " -iv " . $keys->iv, result_code: $result_code);
      break;
    case 4:
      exec(BIN_DIR . "img4 -i $encPath -o $decPath -k " . $keys->iv . $keys->key, result_code: $result_code);
      break;
    default:
      return false;
  }

  if ($result_code !== 0) {
    rename("$path.original", $path);
    return false;
  }

  return true;
}

function decryptRootFsDmg($path, LoopInterface $loop) {
  $ipswId = getIpswIdFromPath($path);
  updateExpireTimestamp($ipswId);

  $jobData = ["filename" => basename($path)];
  $job = getOngoingJob($ipswId, "decryptRootFs", $jobData);
  if ($job) {
    return $job;
  } else {
    $job = addJob($ipswId, "decryptRootFs", $jobData);
  }

  $loop->futureTick(function() use ($path, $job) {
    $keys = getKeyFromPath($path);
    if (!$keys) {
      removeJob($job, "No decryption key for this file was found", ["code" => 404]);
      return;
    }
    rename($path, "$path.original");

    $process = new Process(BIN_DIR . "dmg extract " . escapeshellarg("$path.original") . " " . escapeshellarg("$path.decrypted") . " -k " . $keys["key"]);
    $process->start();
    $prevOffset = 0;
    $fileSize = filesize("$path.original");

    $process->stdout->on("data", function($output) use (&$prevOffset, $job, $fileSize) {
      if (!str_contains($output, "fileOffset=")) return;

      $chunks = explode("fileOffset=", $output);
      $fileOffset = hexdec(explode("\n", $chunks[array_key_last($chunks) - 1])[0]);
      if ($fileOffset < $prevOffset + DOWNLOAD_PROGRESS_INTERVAL_BYTES) return;

      publishJobProgress($job, "decrypting", [
        "bytes_decrypted" => $fileOffset,
        "bytes_total" => $fileSize
      ]);

      $prevOffset = $fileOffset;
    });

    $process->on("exit", function() use ($job) {
      removeJob($job);
    });
  });

  return $job;
}

function extractDmg($path, LoopInterface $loop) {
  $ipswId = getIpswIdFromPath($path);
  updateExpireTimestamp($ipswId);

  $jobData = ["filename" => basename($path)];
  $job = getOngoingJob($ipswId, "extractDmg", $jobData);
  if ($job) {
    return $job;
  } else {
    $job = addJob($ipswId, "extractDmg", $jobData);
  }

  $extract = function() use ($path, $job) {
    if (is_dir($path)) {
      removeJob($job, data: [
        "steps_done" => 2,
        "steps_total" => 2
      ]);
      return;
    }

    $dirname = dirname($path);
    $oldList = scandir($dirname);

    $process = new Process(BIN_DIR . "7zz x -o" . escapeshellarg($dirname) . " -y -bso2 -bse2 -bsp1 " . escapeshellarg("$path.decrypted") . " 2> /dev/null");
    $process->start();
    $prevPercent = null;

    $process->stdout->on("data", function($output) use (&$prevPercent, $job) {
      if (!str_contains($output, "%")) return;
      $percent = intval(explode("%", str_replace([hex2bin("08"), " "], "", $output))[0]);
      if ($percent == $prevPercent) return;

      publishJobProgress($job, "extracting", [
        "percent_completed" => $percent,
        "steps_done" => 1,
        "steps_total" => 2
      ]);

      $prevPercent = $percent;
    });

    $process->on("exit", function() use ($dirname, $oldList, $path, $job) {
      $dir = array_values(array_diff(scandir($dirname), $oldList))[0];
      rename($dirname . "/$dir", $path);

      $process = new Process("chown -R :" . escapeshellarg(SHARED_OWNERSHIP_GROUP) . " " . escapeshellarg($path) . " && chmod -R 775 " . escapeshellarg($path));
      $process->start();
      $process->on("exit", function() use ($job) {
        removeJob($job, data: [
          "steps_done" => 2,
          "steps_total" => 2
        ]);
      });
    });
  };
  
  $loop->futureTick(function() use ($path, $extract, $job, $loop) {
    if (is_file("$path.decrypted")) {
      $extract();
    } elseif (identifyImg($path) !== false) {
      if (decryptImg($path) === false) {
        removeJob($job, "Failed to decrypt DMG");
        return;
      }
      $extract();
    } else {
      $decryptJob = decryptRootFsDmg($path, $loop);
      subscribeToJobAsync($decryptJob, function($status, $data) use ($job, $extract) {
        if ($status == "done") {
          $extract();
        } elseif ($status == "error") {
          removeJob($job, $data["message"]);
        } else {
          publishJobProgress($job, $status, $data + [
            "steps_done" => 0,
            "steps_total" => 2
          ]);
        }
      }, $loop);
    }
  });
  
  return $job;
}

function getDirListing($path, $includeTags = true) {
  if (!$ipswId = getIpswIdFromPath($path)) {
    return false;
  }
  updateExpireTimestamp($ipswId);
  
  $listing = array_values(array_diff(scandir($path), [".", ".."]));
  $files = [];
  $dirs = [];

  for ($i = 0; $i < count($listing); $i++) {
    $name = $listing[$i];

    //if (in_array($name, [".HFS+ Private Directory Data", ".HFS+ Private Directory Data\r", ".Trashes", "[HFS+ Private Data]"])) continue;
    if (in_array(pathinfo($name, PATHINFO_EXTENSION), IGNORE_EXTENSIONS)) continue;

    if (is_dir("$path/$name") && pathinfo($name, PATHINFO_EXTENSION) != "dmg") {
      array_push($dirs, $name);
    } else {
      array_push($files, $name);
    }
  }

  $result = ["files" => $files, "directories" => $dirs];

  if ($includeTags) {
    $tags = [];
    $cachePath = CACHE_DIR . "/$ipswId";
    
    // For some reason, the first IPSWs to have BuildManifests had them named "BuildManifesto" instead.
    $buildManifest = null;
    if (is_file("$cachePath/BuildManifest.plist")) {
      $buildManifest = (new CFPropertyList("$cachePath/BuildManifest.plist"))->toArray();
    } elseif (is_file("$cachePath/BuildManifesto.plist")) {
      $buildManifest = (new CFPropertyList("$cachePath/BuildManifesto.plist"))->toArray();
    }

    if ($buildManifest) {
      $restoreManifest = null;
      $updateManifest = null;
      foreach ($buildManifest["BuildIdentities"] as $id) {
        if ($id["Info"]["RestoreBehavior"] == "Erase")
          $restoreManifest = $id["Manifest"];
        elseif ($id["Info"]["RestoreBehavior"] == "Update")
          $updateManifest = $id["Manifest"];
      }

      foreach ($restoreManifest as $name => $info) {
        $filename = basename($info["Info"]["Path"]);
        $tag = match ($name) {
          "AppleLogo" => "applelogo",
          "BatteryCharging", "BatteryCharging0", "BatteryCharging1", "BatteryFull", "BatteryLow0", "BatteryLow1", "BatteryPlugin", "RecoveryMode" => "ibootim",
          "DeviceTree" => "devicetree",
          "KernelCache" => "kernelcache",
          "LLB" => "llb",
          "OS" => "rootfs",
          "RestoreRamDisk" => "ramdisk_restore",
          "iBEC" => "ibec",
          "iBSS" => "ibss",
          "iBoot" => "iboot",
          default => null
        };

        if (in_array($tag, ["llb", "ibec", "ibss"])) {
          $tag .= "|" . $info["BuildString"];
        }

        if ($tag) $tags[$filename] = $tag;
      }

      $tags[$updateManifest["RestoreRamDisk"]["Info"]["Path"]] = "ramdisk_update";
    } elseif (is_file("$cachePath/Restore.plist")) {
      // Fall back to Restore.plist, which even the oldest IPSWs have
      $restorePlist = (new CFPropertyList("$cachePath/Restore.plist"))->toArray();

      if (isset($restorePlist["KernelCachesByPlatform"])) {
        foreach ($restorePlist["KernelCachesByPlatform"] as $list) {
          foreach ($list as $filename) {
            $tags[$filename] = "kernelcache";
          }
        }
      } elseif (isset($restorePlist["RestoreKernelCaches"])) {
        foreach ($restorePlist["RestoreKernelCaches"] as $filename) {
          $tags[$filename] = "kernelcache";
        }
      }

      if (isset($restorePlist["RamDisksByPlatform"])) {
        foreach ($restorePlist["KernelCachesByPlatform"] as $list) {
          foreach ($list as $type => $filename) {
            $tags[$filename] = "ramdisk_" . ($type == "Update" ? "update" : "restore");
          }
        }
      } elseif (isset($restorePlist["RestoreRamDisks"])) {
        foreach ($restorePlist["RestoreRamDisks"] as $type => $filename) {
          $tags[$filename] = "ramdisk_" . ($type == "Update" ? "update" : "restore");
        }
      }

      if (isset($restorePlist["SystemRestoreImages"])) {
        foreach ($restorePlist["SystemRestoreImages"] as $filename) {
          $tags[$filename] = "rootfs";
        }
      }
    }

    $result += ["tags" => $tags];
  }

  return $result;
}

function ipswIsCached($id) {
  return is_dir(CACHE_DIR . "/$id");
}

function cacheIpswContents($id, LoopInterface $loop) {
  updateExpireTimestamp($id);

  $job = getOngoingJob($id, "cache");
  if ($job) {
    return $job;
  } else {
    $job = addJob($id, "cache");
  }
  $cachePath = CACHE_DIR . "/$id";

  $extract = function() use ($cachePath, $job) {
    $fileList = [];
    exec("unzip -Z -1 " . escapeshellarg("$cachePath/ipsw.zip"), $fileList);
    $totalFiles = count($fileList);

    publishJobProgress($job, "extracting", [
      "files_extracted" => 0,
      "files_total" => $totalFiles,
      "steps_done" => 1,
      "steps_total" => 2
    ]);

    $process = new Process("unzip -o " . escapeshellarg("$cachePath/ipsw.zip") . " -d " . escapeshellarg($cachePath));
    $process->start();

    $count = 0;
    $process->stdout->on("data", function($output) use (&$count, $job, $totalFiles) {
      $amount = substr_count($output, "inflating:") + substr_count($output, "creating:");
      if ($amount < 1) return;

      $count += $amount;
      publishJobProgress($job, "extracting", [
        "files_extracted" => $count,
        "files_total" => $totalFiles,
        "steps_done" => 1,
        "steps_total" => 2
      ]);
    });

    $process->on("exit", function() use ($cachePath, $job) {
      unlink("$cachePath/ipsw.zip");

      $process = new Process("chmod -R 775 " . escapeshellarg($cachePath));
      $process->start();
      $process->on("exit", function() use ($job) {
        removeJob($job, data: [
          "steps_done" => 2,
          "steps_total" => 2
        ]);
      });
    });
  };

  $loop->futureTick(function() use ($id, $cachePath, $extract, $job, $loop) {
    $info = getIpswInfo($id);

    if (!$info) {
      removeJob($job, "Unknown IPSW ($id)");
      return;
    }
    if (!$info->url) {
      removeJob($job, "This IPSW ($id) doesn't have a download URL");
      return;
    }

    if (is_dir($cachePath)) {
      if (is_file("$cachePath/ipsw.zip")) {
        $extract();
      } else {
        removeJob($job, data: [
          "steps_done" => 2,
          "steps_total" => 2
        ]);
      }
      return;
    }

    mkdir($cachePath, recursive: true);

    $ipswUrl = $info->url;
    $browser = new Browser($loop);
    $destination = new WritableResourceStream(fopen("$cachePath/ipsw.zip", "wb"), $loop);
    
    $browser->requestStreaming("GET", $ipswUrl)->then(function(ResponseInterface $response) use ($destination, $job, $extract) {
      $body = $response->getBody();
      assert($body instanceof \React\Stream\ReadableStreamInterface);
      
      $currentBytes = 0;
      $totalBytes = intval($response->getHeaderLine("Content-Length"));
      $prevCurrentBytes = 0;

      publishJobProgress($job, "downloading", [
        "bytes_downloaded" => 0,
        "bytes_total" => $totalBytes,
        "steps_done" => 0,
        "steps_total" => 2
      ]);

      $body->on("data", function($chunk) use (&$currentBytes, $totalBytes, &$prevCurrentBytes, $job, $destination, $body) {
        $currentBytes += strlen($chunk);
        $destination->write($chunk);

        if ($currentBytes >= $prevCurrentBytes + DOWNLOAD_PROGRESS_INTERVAL_BYTES) {
          publishJobProgress($job, "downloading", [
            "bytes_downloaded" => $currentBytes,
            "bytes_total" => $totalBytes,
            "steps_done" => 0,
            "steps_total" => 2
          ]);
          $prevCurrentBytes = $currentBytes;
        }
      });

      $body->on("end", function() use ($destination) {
        $destination->end();
      });

      $destination->on("close", $extract);
    });
  });

  return $job;
}