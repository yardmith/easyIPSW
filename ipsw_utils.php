<?php

use CFPropertyList\CFPropertyList;
use React\ChildProcess\Process;
use React\EventLoop\LoopInterface;
use Symfony\Component\Filesystem\Path;

require_once "vendor/autoload.php";
require_once "db_utils.php";
require_once "constants.php";
require_once "job_utils.php";

function isDmgExtracted($path) {
  if (!$ipswId = getIpswIdFromPath($path)) return;
  return is_dir($path) && !getOngoingJob($ipswId, "extractDmg", ["filename" => basename($path)]);
}

function pathNeedsDmgExtraction($path, $allowDmgAsLastPathLevel = false, $allowExtracted = false) {
  $path = rtrim($path, "/");
  if (!str_contains($path, ".dmg")) return false;

  $pathParts = explode("/", $path);
  $pathPartsTemp = $pathParts;
  for ($i = count($pathPartsTemp) - 1; $i >= 0; $i--) {
    $part = $pathPartsTemp[$i];
    if (!str_contains($part, ".dmg")) {
      array_pop($pathParts);
    } else {
      break;
    }
  }

  $dmgPath = implode("/", $pathParts);
  if (isDmgExtracted($path) && !$allowExtracted) return false;
  if ($dmgPath == $path && !$allowDmgAsLastPathLevel) return false;
  return $dmgPath;
}

function convertDataToStrings(&$array) {
  foreach ($array as $key => $value) {
    if (is_array($value))
      convertDataToStrings($array[$key]);
    elseif (is_string($value) && !mb_check_encoding($value, "UTF-8"))
      $array[$key] = base64_encode($value);
  }
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
    if (is_file("$path.decrypted")) {
      removeJob($job);
      return;
    }

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

function decryptAea($path, LoopInterface $loop) {
  $ipswId = getIpswIdFromPath($path);
  updateExpireTimestamp($ipswId);

  $jobData = ["filename" => basename($path)];
  $job = getOngoingJob($ipswId, "decryptAea", $jobData);
  if ($job) {
    return $job;
  } else {
    $job = addJob($ipswId, "decryptAea", $jobData);
  }

  $loop->futureTick(function() use ($path, $job) {
    if (is_file("$path.decrypted")) {
      removeJob($job);
      return;
    }

    rename($path, "$path.original");

    $process = new Process(AEA_UTILS_DIR . ".venv/bin/python3 " . AEA_UTILS_DIR . "extract_aea.py " . escapeshellarg("$path.original") . " " . escapeshellarg("$path.decrypted"));
    $process->start();
    $prevCurrentBytes = 0;
    $totalBytes = filesize("$path.original");

    $process->stdout->on("data", function($output) use ($totalBytes, &$prevCurrentBytes, $job) {
      $lines = explode("\n", $output);
      $currentBytes = intval($lines[array_key_last($lines) - 1]);
      if ($currentBytes < $prevCurrentBytes + DOWNLOAD_PROGRESS_INTERVAL_BYTES) return;
      $prevCurrentBytes = $currentBytes;

      publishJobProgress($job, "decrypting", [
        "bytes_decrypted" => $currentBytes,
        "bytes_total" => $totalBytes
      ]);
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

  $extract = function($totalSteps) use ($path, $job) {
    if (is_dir($path)) {
      removeJob($job, data: [
        "steps_done" => $totalSteps,
        "steps_total" => $totalSteps
      ]);
      return;
    }

    if (!is_file("$path.decrypted")) {
      $actualPath = "$path.original";
    } else {
      $actualPath = "$path.decrypted";
    }

    $process = new Process(BIN_DIR . "7zz x -o" . escapeshellarg($path) . " -y -bso2 -bse2 -bsp1 " . escapeshellarg($actualPath) . " 2> /dev/null");
    $process->start();
    $prevPercent = null;

    $process->stdout->on("data", function($output) use (&$prevPercent, $job, $totalSteps) {
      if (!str_contains($output, "%")) return;
      $percent = intval(explode("%", str_replace([hex2bin("08"), " "], "", $output))[0]);
      if ($percent == $prevPercent) return;

      publishJobProgress($job, "extracting", [
        "percent_completed" => $percent,
        "steps_done" => $totalSteps - 1,
        "steps_total" => $totalSteps
      ]);

      $prevPercent = $percent;
    });

    $process->on("exit", function() use ($path, $job, $totalSteps) {
      publishJobProgress($job, "finalizing");
      $process = new Process("find " . escapeshellarg($path) . " -print0 | xargs -0 -P 0 -n 100 chown :" . escapeshellarg(SHARED_OWNERSHIP_GROUP));
      $process->start();
      $process->on("exit", function() use ($job, $path, $totalSteps) {
        $process = new Process("find " . escapeshellarg($path) . " -print0 | xargs -0 -P 0 -n 100 chmod 775");
        $process->start();
        $process->on("exit", function() use ($job, $totalSteps, $path) {
          $remove = function() use ($job, $totalSteps) {
            removeJob($job, data: [
              "steps_done" => $totalSteps,
              "steps_total" => $totalSteps
            ]);
          };

          $children = array_values(array_diff(scandir($path), [".", ".."]));
          if (count($children) == 1 && is_dir("$path/" . $children[0])) {
            $childPath = "$path/" . $children[0];
            $process = new Process("mv " . escapeshellarg("$childPath/") . "* " . escapeshellarg("$childPath/../") . " && rm -r " . escapeshellarg($childPath));
            $process->start();
            $process->stderr->on("data", function($output) {var_dump($output);});
            $process->on("exit", $remove);
          } else {
            $remove();
          }
        });
      });
    });
  };
  
  $loop->futureTick(function() use ($path, $extract, $job, $loop) {
    if (is_file($path))
      $isRootFs = file_get_contents($path, length: 8) == "encrcdsa";
    else
      $isRootFs = file_get_contents("$path.original", length: 8) == "encrcdsa";
    $isAea = pathinfo($path, PATHINFO_EXTENSION) == "aea";

    if (is_file("$path.decrypted") && !$isRootFs && !$isAea) {
      $extract(1);
    } elseif (identifyImg($path) !== false) {
      if (decryptImg($path) === false) {
        removeJob($job, "Failed to decrypt DMG");
        return;
      }
      $extract(2);
    } elseif ($isRootFs || $isAea) {
      if ($isRootFs)
        $decryptJob = decryptRootFsDmg($path, $loop);
      else
        $decryptJob = decryptAea($path, $loop);

      subscribeToJobAsync($decryptJob, function($status, $data) use ($job, $extract) {
        if ($status == "done") {
          $extract(2);
        } elseif ($status == "error") {
          removeJob($job, $data["message"]);
        } else {
          publishJobProgress($job, $status, $data + [
            "steps_done" => 0,
            "steps_total" => 2
          ]);
        }
      }, $loop);
    } else {
      rename($path, "$path.original");
      $extract(1);
    }
  });
  
  return $job;
}

function identifyPlist($path) {
  if (!is_file($path)) return false;

  $contents = file_get_contents($path, length: 6);

  if (str_contains($contents, "?xml")) {
    return "xml";
  } elseif (str_starts_with($contents, "bplist")) {
    return "binary";
  } else {
    return false;
  }
}

function getFileTags($ipswId) {
  $tags = [];
  $cachePath = CACHE_DIR . "/$ipswId";
  if (!is_dir($cachePath)) return false;
  
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
      if (!isset($info["Info"]["Path"])) continue;
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

    $setTag = function($filename, $tag) use ($tags) {
      $tags[$filename] = $tag;
    };

    if (isset($restorePlist["KernelCachesByPlatform"])) {
      foreach ($restorePlist["KernelCachesByPlatform"] as $list) {
        foreach ($list as $filename) {
          $setTag($filename, "kernelcache");
        }
      }
    } elseif (isset($restorePlist["RestoreKernelCaches"])) {
      foreach ($restorePlist["RestoreKernelCaches"] as $filename) {
        $setTag($filename, "kernelcache");
      }
    }

    if (isset($restorePlist["RamDisksByPlatform"])) {
      foreach ($restorePlist["KernelCachesByPlatform"] as $list) {
        foreach ($list as $type => $filename) {
          $setTag($filename, "ramdisk_" . ($type == "Update" ? "update" : "restore"));
        }
      }
    } elseif (isset($restorePlist["RestoreRamDisks"])) {
      foreach ($restorePlist["RestoreRamDisks"] as $type => $filename) {
        $setTag($filename, "ramdisk_" . ($type == "Update" ? "update" : "restore"));
      }
    }

    if (isset($restorePlist["SystemRestoreImages"])) {
      foreach ($restorePlist["SystemRestoreImages"] as $filename) {
        $setTag($filename, "rootfs");
      }
    }
  }

  return $tags;
}

function getRelativePath($path) {
  if (!$ipswId = getIpswIdFromPath($path)) return;  
  $cachePath = CACHE_DIR . "/$ipswId";

  return str_replace($cachePath, "", $path);
}

function getDirListing($path, $ipswId = null) {
  if (!$ipswId && is_array($path))
    return false;

  if (!$ipswId && !$ipswId = getIpswIdFromPath($path)) {
    return false;
  }
  updateExpireTimestamp($ipswId);

  $pathList = is_array($path) ? $path : false;

  if ($pathList !== false)
    $listing = $pathList;
  else
    $listing = array_values(array_diff(scandir($path), [".", ".."]));
  $files = [];
  $tags = getFileTags($ipswId);

  for ($i = 0; $i < count($listing); $i++) {
    $name = $listing[$i];
    if ($pathList) {
      $path = dirname($name);
      $name = basename($name);
    }

    //if (in_array($name, [".HFS+ Private Directory Data", ".HFS+ Private Directory Data\r", ".Trashes", "[HFS+ Private Data]"])) continue;
    $originalName = str_replace(".original", "", $name);
    if (pathinfo($name, PATHINFO_EXTENSION) == "original" && !file_exists("$path/$originalName")) $name = $originalName;
    
    $extension = pathinfo($name, PATHINFO_EXTENSION);
    if (in_array($extension, IGNORE_EXTENSIONS)) continue;
    if ($extension == EXTRACTING_EXTENSION) $name = pathinfo($name, PATHINFO_FILENAME);

    $isDmg = in_array(pathinfo($name, PATHINFO_EXTENSION), ["dmg", "aea"]);

    if (is_dir("$path/$name") && !$isDmg) {
      array_push($files, [
        "name" => $name
      ] + ($pathList ? [
        "path" => getRelativePath($path)
      ] : []) + [
        "is_dir" => true
      ]);
    } else {
      $filePath = "$path/$name";
      $actualPath = $filePath;
      if (is_file("$filePath.original")) $actualPath .= ".original";

      $plistType = identifyPlist($actualPath);

      array_push($files, [
        "name" => $name
      ] + ($pathList ? [
        "path" => getRelativePath($path)
      ] : []) + [
        "size" => filesize($actualPath)
      ] + (array_key_exists($name, $tags) ? [
        "tag" => $tags[$name]
      ] : []) + (identifyImg($actualPath) || file_get_contents($actualPath, length: 8) == "encrcdsa" ? [
        "has_key" => (bool)getKeyFromPath($filePath)
      ] : []) + ($isDmg ? [
        "extracted" => isDmgExtracted($filePath)
      ] : []) + ($plistType ? [
        "plist_type" => $plistType
      ] : []));
    }
  }

  return $files;
}

function ipswIsCached($id) {
  return is_dir(CACHE_DIR . "/$id");
}

function unitsStringToBytes($string) {
  if (str_contains($string, "GiB")) {
    $units = "GiB";
    $mult = 1024 * 1024 * 1024;
  } elseif (str_contains($string, "MiB")) {
    $units = "MiB";
    $mult = 1024 * 1024;
  } elseif (str_contains($string, "KiB")) {
    $units = "KiB";
    $mult = 1024;
  } else {
    $units = "B";
    $mult = 1;
  }

  return intval(str_replace([$units, " "], "", $string)) * $mult;
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
    $totalBytes = filesize("$cachePath/ipsw.zip");
    $prevCurrentBytes = 0;

    publishJobProgress($job, "extracting", [
      "bytes_extracted" => 0,
      "bytes_total" => $totalBytes,
      "steps_done" => 1,
      "steps_total" => 2
    ]);

    $process = new Process("script -q -c \"" . BIN_DIR . "ripunzip uf " . escapeshellarg("$cachePath/ipsw.zip") . " -d " . escapeshellarg($cachePath) . "\" /dev/null");
    $process->start();

    $process->stdout->on("data", function($output) use ($job, $totalBytes, &$prevCurrentBytes) {
      if (!str_contains($output, hex2bin("1B5B33366D"))) return;
      $result = explode("] ", $output)[2];
      $result = explode(" (", $result)[0];
      $result = explode("/", $result)[0];

      $currentBytes = unitsStringToBytes($result);
      if ($currentBytes < $prevCurrentBytes + DOWNLOAD_PROGRESS_INTERVAL_BYTES) return;
      $prevCurrentBytes = $currentBytes;
      
      publishJobProgress($job, "extracting", [
        "bytes_extracted" => $currentBytes,
        "bytes_total" => $totalBytes,
        "steps_done" => 1,
        "steps_total" => 2
      ]);
    });

    $process->on("exit", function() use ($cachePath, $job) {
      unlink("$cachePath/ipsw.zip");

      $process = new Process("chown -R :" . escapeshellarg(SHARED_OWNERSHIP_GROUP) . " " . escapeshellarg($cachePath) . " && chmod -R 775 " . escapeshellarg($cachePath));
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

    if (file_exists($cachePath)) {
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

    $makingCacheDir = !is_dir(CACHE_DIR);
    mkdir($cachePath, recursive: true);
    if ($makingCacheDir) {
      $permissionsProcess = new Process("chown :" . escapeshellarg(SHARED_OWNERSHIP_GROUP) . " " . escapeshellarg(CACHE_DIR) . " && chmod 775 " . escapeshellarg(CACHE_DIR) . " && setfacl -d -m g::rwx " . escapeshellarg(CACHE_DIR) . " && chmod g+s " . escapeshellarg(CACHE_DIR));
      $permissionsProcess->start();
    }

    $process = new Process(BIN_DIR . "aria2c --show-console-readout false --summary-interval 1 -x " . ARIA2_CONNECTIONS . " -s " . ARIA2_CONNECTIONS . " -d " . escapeshellarg($cachePath) . " -o ipsw.zip " . escapeshellarg($info->url));
    $process->start();
    $totalBytes = get_headers($info->url, true)["Content-Length"];
    if (is_array($totalBytes)) $totalBytes = end($totalBytes);
    $prevCurrentBytes = 0;

    publishJobProgress($job, "downloading", [
      "bytes_downloaded" => 0,
      "bytes_total" => $totalBytes,
      "steps_done" => 0,
      "steps_total" => 2
    ]);

    $process->stdout->on("data", function($output) use ($totalBytes, $job, &$prevCurrentBytes) {
      if (!str_contains($output, "Download Progress Summary")) return;

      $result = explode("[", $output)[1];
      $result = explode(")", $result)[0];
      $result = explode(" ", $result)[1];
      $result = explode("(", $result)[0];
      $result = explode("/", $result)[0];
      
      $currentBytes = unitsStringToBytes($result);
      if ($currentBytes == $prevCurrentBytes) return;
      $prevCurrentBytes = $currentBytes;

      publishJobProgress($job, "downloading", [
        "bytes_downloaded" => $currentBytes,
        "bytes_total" => $totalBytes,
        "steps_done" => 0,
        "steps_total" => 2
      ]);
    });

    $process->on("exit", $extract);
  });

  return $job;
}