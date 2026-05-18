<?php

require_once "vendor/autoload.php";
require_once "constants.php";

$db = [];

foreach (scandir(DB_DIR) as $file) {
  if (pathinfo($file, PATHINFO_EXTENSION) == "json") {
    $db[pathinfo($file, PATHINFO_FILENAME)] = json_decode(file_get_contents(DB_DIR . "/" . $file), true);
  }
}