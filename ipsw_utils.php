<?php

use Psr\Http\Message\ResponseInterface;
use React\ChildProcess\Process;
use React\EventLoop\LoopInterface;
use React\Http\Browser;
use React\Stream\WritableResourceStream;
use Symfony\Component\Filesystem\Path;

require_once "db.php";
require_once "constants.php";
require_once "cache_utils.php";
require_once "job_utils.php";

function pathNeedsDmgExtraction($path) {
  if (!str_contains($path, ".dmg")) {
    return false;
  }
  $dmgPath = explode(".dmg", $path)[0] . ".dmg";
  if (is_dir($dmgPath)) return false;
  return $dmgPath;
}

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

function getIpswIdFromPath($path) {
  $path = Path::canonicalize($path);
  $pathParts = explode("/", $path);
  $cacheIdx = array_search(CACHE_DIR_NAME, $pathParts);
  if ($cacheIdx === false || count($pathParts) <= $cacheIdx + 1) {
    return false;
  }
  return $pathParts[$cacheIdx + 1];
}

function getKeysFromPath($path) {
  global $db;

  $pathParts = explode("/", $path);
  $cacheIdx = array_search(CACHE_DIR_NAME, $pathParts);
  if ($cacheIdx === false || count($pathParts) <= $cacheIdx + 1) {
    return false;
  }

  $ipswId = $pathParts[$cacheIdx + 1];
  $filename = basename($path);
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

  rename($path, "$path.original");
  $result_code = null;
  $pathEsc = escapeshellarg($path);
  $encPath = escapeshellarg("$path.original");

  switch ($type) {
    case 2:
      exec(BIN_DIR . "xpwntool $encPath $pathEsc", result_code: $result_code);
      break;
    case 3:
      exec(BIN_DIR . "xpwntool $encPath $pathEsc -k " . $keys["key"] . " -iv " . $keys["iv"], result_code: $result_code);
      break;
    case 4:
      exec(BIN_DIR . "img4 -i $encPath -o $pathEsc -k " . $keys["iv"] . $keys["key"], result_code: $result_code);
      break;
    default:
      return false;
  }

  if ($result_code !== 0) {
    rename("$path.original", $path);
    return false;
  }
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
    $dirname = dirname($path);
    $oldList = scandir($dirname);

    $process = new Process(BIN_DIR . "7zz x -o" . escapeshellarg($dirname) . " -y -bso2 -bse2 -bsp1 " . escapeshellarg($path) . " 2> /dev/null");
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
      unlink($path);
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
  
  $loop->futureTick(function() use ($path, $extract, $job) {
    if (identifyImg($path) !== false) {
      if (decryptImg($path) === false) {
        removeJob($job, "Failed to decrypt DMG");
        return;
      }
      $extract();
    } else {
      $keys = getKeysFromPath($path);
      if (!$keys) {
        removeJob($job, "No keys found");
        return;
      }
      rename($path, "$path.original");

      $process = new Process(BIN_DIR . "dmg extract " . escapeshellarg("$path.original") . " " . escapeshellarg($path) . " -k " . $keys["key"]);
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
          "bytes_total" => $fileSize,
          "steps_done" => 0,
          "steps_total" => 2
        ]);

        $prevOffset = $fileOffset;
      });

      $process->on("exit", $extract);
    }
  });
  
  return $job;
}

function getDirListing($path) {
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

  return ["files" => $files, "directories" => $dirs];
}

function ipswIsCached($id) {
  return is_dir(CACHE_DIR . "/$id");
}

function cacheIpswContents($id, LoopInterface $loop) {
  global $db;

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

  $loop->futureTick(function() use ($id, $db, $cachePath, $extract, $job, $loop) {
    if (!array_key_exists($id, $db["ipsw"])) {
      removeJob($job, "Unknown IPSW ($id)");
      return;
    }
    if (!array_key_exists("url", $db["ipsw"][$id])) {
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

    $ipswUrl = $db["ipsw"][$id]["url"];
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