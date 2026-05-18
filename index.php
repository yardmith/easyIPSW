<?php

require_once "vendor/autoload.php";
require_once "constants.php";
require_once "db.php";

Flight::set("flight.debug", DEBUG);

Flight::route("/@id/download", function($id) {
  global $db;

  if (!array_key_exists($id, $db["ipsw"])) {
    Flight::halt("404", "IPSW ($id) was not found.");
  }
  if (!array_key_exists("url", $db["ipsw"][$id])) {
    Flight::halt("500", "The IPSW ($id) does not have a download URL.");
  }

  Flight::redirect($db["ipsw"][$id]["url"]);
});

Flight::start();