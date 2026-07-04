<?php

use CFPropertyList\CFPropertyList;
use React\EventLoop\Loop;

require_once "vendor/autoload.php";
require_once "constants.php";
require_once "db_utils.php";
require_once "ipsw_utils.php";

Flight::set("flight.debug", DEBUG);
Flight::set("flight.views.path", FRONTEND_DIR);
Flight::set("flight.views.extension", "html");

Flight::map("notFound", function() {
  $messages = [
    "Dead end",
    "Nothing here",
    "Not found",
    "Empty handed"
  ];

  Flight::render("404.html", ["message" => $messages[rand(0, count($messages) - 1)]]);
});

Flight::route("/@id/download", function($id) {
  $info = getIpswInfo($id);

  if (!$info) {
    Flight::halt(404, "Unknown IPSW ($id)");
  }
  if (!$info->url) {
    Flight::halt(500, "This IPSW ($id) doesn't have a download URL");
  }

  Flight::redirect($info->url);
});

Flight::route("/@id/raw/*", function($id) {
  $path = urldecode(explode("/$id/raw", parse_url(Flight::request()->getFullUrl(), PHP_URL_PATH))[1]);
  $cachePath = CACHE_DIR . "/$id$path";

  if (!getIpswIdFromPath($cachePath)) {
    Flight::halt(404, "File/directory not found");
  }

  $serveFile = function() use ($cachePath, $path) {
    $query = Flight::request()->query;
    $defry = isset($query->defry);
    $decrypt = isset($query->decrypt);
    $png = isset($query->png);
    $xml = isset($query->xml);
    $json = isset($query->json);

    if ($defry && pathinfo($cachePath, PATHINFO_EXTENSION) == "png") {
      if (!is_file("$cachePath.defried")) {
        exec(BIN_DIR . "pngdefry -s .defried " . escapeshellarg($cachePath));
        rename(dirname($cachePath) . "/" . pathinfo($cachePath, PATHINFO_FILENAME) . ".defried.png", "$cachePath.defried");
      }
      /** @disregard */
      Flight::download("$cachePath.defried", basename($cachePath));
      return;
    }
    if ($decrypt) {
      $extension = pathinfo($cachePath, PATHINFO_EXTENSION);
      $actualPath = is_file("$cachePath.original") ? "$cachePath.original" : $cachePath;
      $isRootFs = file_get_contents($actualPath, length: 8) == "encrcdsa";
      $isAea = $extension == "aea";

      if (identifyImg($cachePath) && !is_dir($cachePath)) {
        $result = decryptImg($cachePath);
        if ($result === null) {
          Flight::halt(404, "No decryption key for this file was found");
        } elseif ($result === false) {
          Flight::halt(500, "Failed to decrypt IMG");
        }
      } elseif ($isRootFs || $isAea) {
        if ($isRootFs)
          $decryptJob = decryptRootFsDmg($cachePath, Loop::get());
        else
          $decryptJob = decryptAea($cachePath, Loop::get());  

        subscribeToJobAsync($decryptJob, function($status, $data) use ($cachePath) {
          if ($status == "done") {
            /** @disregard */
            Flight::download("$cachePath.decrypted", basename($cachePath));
          } elseif ($status == "error") {
            Flight::halt(isset($data["code"]) ? $data["code"] : 500, $data["message"]);
          }
        });
        return;
      }

      if (is_file("$cachePath.decrypted") && !$isRootFs && !$isAea) {
        /** @disregard */
        Flight::download("$cachePath.decrypted", basename($cachePath));
        return;
      }
    }
    if ($png && (identifyImg($cachePath) | identifyImg("$cachePath.original")) ) {
      $imgType = is_file($cachePath) ? identifyImg($cachePath) : identifyImg("$cachePath.original");

      if (!is_file("$cachePath.pngified")) {
        if ($imgType < 4) {
          $actualPath = is_file($cachePath) ? $cachePath : "$cachePath.original";
          if ($imgType > 2) {
            $key = getKeyFromPath($cachePath);
            if (!$key) Flight::halt(404, "No decryption key for this file was found");
            $keyString = " " . escapeshellarg($key["iv"]) . " " . escapeshellarg($key["key"]);
          } else {
            $keyString = "";
          }
          $output = [];
          exec(BIN_DIR . "imagetool extract " . escapeshellarg($actualPath) . " " . escapeshellarg("$cachePath.pngified") . $keyString, $output);
          if (str_contains($output[0], "error converting img to png")) {
            unlink("$cachePath.pngified");
            Flight::halt(500, "Failed to convert IMG to PNG. This IMG likely doesn't contain a viewable image.");
          }
        } else {
          if (!is_file("$cachePath.decrypted")) {
            $result = decryptImg($cachePath);
            if ($result === null) {
              Flight::halt(404, "No decryption key for this file was found");
            } elseif ($result === false) {
              Flight::halt(500, "Failed to decrypt IMG");
            }
          }
          exec(BIN_DIR . "ibootim " . escapeshellarg("$cachePath.decrypted") . " " . escapeshellarg("$cachePath.pngified"));
        }
      }

      /** @disregard */
      Flight::download("$cachePath.pngified", basename($cachePath) . ".png");
      return;
    }

    $plistType = identifyPlist($cachePath);
    if ($xml && $plistType == "binary") {
      if (!is_file("$cachePath.xmlified")) {
        $plist = new CFPropertyList($cachePath);
        $plist->saveXML("$cachePath.xmlified", true);
      }
      /** @disregard */
      Flight::download("$cachePath.xmlified", basename($cachePath));
      return;
    }
    if ($json && $plistType) {
      if (!is_file("$cachePath.jsonified")) {
        $plist = new CFPropertyList($cachePath);

        $array = $plist->toArray();
        convertDataToStrings($array);
        
        $json = json_encode($array, JSON_PRETTY_PRINT);
        file_put_contents("$cachePath.jsonified", str_replace("    ", "  ", $json));
      }
      /** @disregard */
      Flight::download("$cachePath.jsonified", basename($cachePath) . ".json");
      return;
    }

    if (is_file("$cachePath.original")) {
      /** @disregard */
      Flight::download("$cachePath.original", basename($cachePath));
      return;
    }

    if (is_dir($cachePath)) {
      Flight::halt(400, "Path ($path) is a directory");
    } elseif (!is_file($cachePath) || in_array(pathinfo($cachePath, PATHINFO_EXTENSION), IGNORE_EXTENSIONS)) {
      Flight::halt(404, "File/directory not found");
    }
    
    Flight::download($cachePath);
  };

  $cacheJob = cacheIpswContents($id, Loop::get());
  subscribeToJobAsync($cacheJob, function($status, $data) use ($cachePath, $serveFile) {
    if ($status == "done") {
      $dmgToExtract = pathNeedsDmgExtraction($cachePath);
      if ($dmgToExtract && !(str_ends_with($cachePath, ".dmg") || str_ends_with($cachePath, ".dmg/")) ) {
        $dmgJob = extractDmg($dmgToExtract, Loop::get());
        subscribeToJobAsync($dmgJob, function($status, $data) use ($serveFile) {
          if ($status == "done") {
            $serveFile();
          } elseif ($status == "error") {
            exit($data["message"]);
          }
        });
      } else {
        $serveFile();
      }
    } elseif ($status == "error") {
      exit($data["message"]);
    }
  });
})->stream();

Flight::route("/@id/browse/*", function($id) {
  $ipswInfo = getIpswInfo($id);

  if ($ipswInfo) {
    $versionString = $ipswInfo["version_string"];
    $deviceName = $ipswInfo["device"]["name"];
  } else {
    $versionString = "Unknown";
    $deviceName = "Unknown";
  }

  Flight::render("browse.html", [
    "url" => Flight::request()->getFullUrl(),
    "ipswId" => $id,
    "versionString" => $versionString,
    "deviceName" => $deviceName
  ]);
});

Flight::route("/@id/keys", function($id) {
  $keys = [];
  $tags = getFileTags($id);
  foreach (getIpswKeys($id) as $filename =>  $key) {
    $info = ["filename" => $filename];
    if ($tags && array_key_exists($filename, $tags)) $info["tag"] = $tags[$filename];
    $info["key"] = $key["key"];
    if (array_key_exists("iv", $key)) $info["iv"] = $key["iv"];
    array_push($keys, $info);
  }
  if (isset(Flight::request()->query->json)) Flight::jsonHalt(str_replace("    ", "  ", json_encode($keys, JSON_PRETTY_PRINT)), encode: false);

  $ipswInfo = getIpswInfo($id);

  if ($ipswInfo) {
    $versionString = $ipswInfo["version_string"];
    $deviceName = $ipswInfo["device"]["name"];
  } else {
    $versionString = "Unknown";
    $deviceName = "Unknown";
  }

  Flight::render("keys.html", [
    "url" => Flight::request()->getFullUrl(),
    "ipswId" => $id,
    "versionString" => $versionString,
    "deviceName" => $deviceName
  ]);
});

Flight::start();