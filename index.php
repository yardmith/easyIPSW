<?php

use React\EventLoop\Loop;

require_once "vendor/autoload.php";
require_once "constants.php";
require_once "db.php";
require_once "ipsw_utils.php";

Flight::set("flight.debug", DEBUG);

Flight::route("/@id/download", function($id) {
  global $db;

  if (!array_key_exists($id, $db["ipsw"])) {
    Flight::halt(404, "Unknown IPSW ($id)");
  }
  if (!array_key_exists("url", $db["ipsw"][$id])) {
    Flight::halt(500, "This IPSW ($id) doesn't have a download URL");
  }

  Flight::redirect($db["ipsw"][$id]["url"]);
});

Flight::route("/@id/raw/*", function($id) {
  $path = explode("/$id/raw", parse_url(Flight::request()->getFullUrl(), PHP_URL_PATH))[1];
  $cachePath = CACHE_DIR . "/$id$path";

  if (!getIpswIdFromPath($cachePath)) {
    Flight::halt(404, "File/directory not found");
  }

  $serveFile = function() use ($cachePath, $path) {
    $defry = isset(Flight::request()->query->defry);

    if (is_file("$cachePath.original")) {
      /** @disregard */
      Flight::download("$cachePath.original", pathinfo($cachePath, PATHINFO_BASENAME));
      return;
    }
    if ($defry && pathinfo($cachePath, PATHINFO_EXTENSION) == "png") {
      if (!is_file("$cachePath.defried")) {
        exec("bin/pngdefry -s .defried " . escapeshellarg($cachePath));
        rename(pathinfo($cachePath, PATHINFO_DIRNAME) . "/" . pathinfo($cachePath, PATHINFO_FILENAME) . ".defried.png", "$cachePath.defried");
      }
      /** @disregard */
      Flight::download("$cachePath.defried", pathinfo($cachePath, PATHINFO_BASENAME));
      return;
    }

    if (is_dir($cachePath)) {
      Flight::halt(400, "Path ($path) is a directory");
    } elseif (!is_file($cachePath) || in_array(pathinfo($cachePath, PATHINFO_EXTENSION), IGNORE_EXTENSIONS)) {
      Flight::halt(404, "File/directory not found");
    }
    
    Flight::download($cachePath);
  };

  cacheIpswContents($id, Loop::get(), completedCallback: function() use ($cachePath, $serveFile) {
    $dmgToExtract = pathNeedsDmgExtraction($cachePath);
    if ($dmgToExtract) {
      extractDmg($dmgToExtract, Loop::get(), completedCallback: $serveFile, errorCallback: function($error) {
        echo $error;
      });
    } else {
      $serveFile();
    }
  }, errorCallback: function($error) {
    echo $error;
  });
})->stream();

Flight::start();