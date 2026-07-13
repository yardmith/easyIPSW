<?php

chdir(__DIR__);

require_once "vendor/autoload.php";
require_once "constants.php";
require_once "ipsw_utils.php";
require_once "db_utils.php";

use Ratchet\MessageComponentInterface;
use Ratchet\ConnectionInterface;
use Ratchet\Http\HttpServer;
use Ratchet\Server\IoServer;
use Ratchet\WebSocket\WsServer;
use React\ChildProcess\Process;
use React\EventLoop\LoopInterface;

class IpswWs implements MessageComponentInterface {
  protected $clients;
  protected $clientsWithJobs;
  public LoopInterface $loop;

  private function sendStatus(ConnectionInterface $conn, $status, $message = null, $extra_fields = []) {
    $conn->send(json_encode([
      "status" => $status
    ] + (($message != null) ? ["message" => $message] : []) + $extra_fields));
  }

  private function setHasJob(ConnectionInterface $conn, $status = null) {
    if ($status) {
      if ($status == "done" || $status == "error") {
        unset($this->clientsWithJobs[array_search($conn, $this->clientsWithJobs)]);
      }
      return;
    }

    if (in_array($conn, $this->clientsWithJobs)) return false;
    array_push($this->clientsWithJobs, $conn);

    return true;
  }

  public function __construct()
  {
    $this->clients = new SplObjectStorage();
    $this->clientsWithJobs = [];
  }

  public function onOpen(ConnectionInterface $conn) {
    $requestUri = $conn->httpRequest->getUri();
    $ipswId = explode("/", $requestUri)[3];

    if (!getIpswInfo($ipswId)) {
      $this->sendStatus($conn, "error", "Unknown IPSW ($ipswId)");
      $conn->close();
    }

    $this->clients->offsetSet($conn, $ipswId);
  }

  public function onMessage(ConnectionInterface $from, $msg) {
    $msg = json_decode($msg, true);
    if (!isset($msg["command"])) return;
    $command = $msg["command"];
    $ipswId = $this->clients[$from];
    $cachePath = CACHE_DIR . "/$ipswId";

    if (!ipswIsCached($ipswId) && $command != "cache") {
      $this->sendStatus($from, "error", "This IPSW ($ipswId) is not cached, use the `cache` command first");
      return;
    }

    switch ($command) {
      case "cache":
        if (!$this->setHasJob($from)) return;

        $job = cacheIpswContents($ipswId, $this->loop);
        subscribeToJobAsync($job, function($status, $data) use ($from) {
          $this->setHasJob($from, $status);
          $this->sendStatus($from, $status, extra_fields: $data);
        });

        break;
      
      case "listing":
        $location = $msg["location"] ?? "/";
        if (substr($location, 0, 1) != "/") {
          $location = "/$location";
        }
        $location = "$cachePath$location";
        $dmgToExtract = pathNeedsDmgExtraction($location, true);

        if (!getIpswIdFromPath($location)) {
          $this->sendStatus($from, "error", "File/directory not found");
          return;
        }
        
        if (is_dir($location) && (!$dmgToExtract || isDmgExtracted($dmgToExtract)) && pathinfo($location, PATHINFO_EXTENSION) != EXTRACTING_EXTENSION) {
          $this->sendStatus($from, "listing", null, ["listing" => getDirListing($location)]);
        } elseif ($dmgToExtract) {
          if (!$this->setHasJob($from)) return;

          $job = extractDmg($dmgToExtract, $this->loop);
          subscribeToJobAsync($job, function($status, $data) use ($from, $location) {
            $this->setHasJob($from, $status);
            $this->sendStatus($from, $status, extra_fields: $data);
            if ($status == "done") {
              $this->sendStatus($from, "listing", null, ["listing" => getDirListing($location)]);
            }
          }, $this->loop);
        } elseif (!is_file($location)) {
          $this->sendStatus($from, "error", "File/directory not found");
        } else {
          $this->sendStatus($from, "error", "Can only get listing for directories, .dmg files, or .dmg.aea files");
        }
        break;
      
      case "dmginfo":
        $dmgPath = pathNeedsDmgExtraction($cachePath . $msg["path"], true, true);
        $actualPath = $dmgPath;
        if (is_file("$dmgPath.original")) $actualPath = "$dmgPath.original";

        if (!isset($msg["path"])) {
          $this->sendStatus($from, "error", "No path specified");
          return;
        } elseif (!str_contains($msg["path"], ".dmg")) {
          $this->sendStatus($from, "error", "The path specified is not a DMG");
          return;
        } elseif (!file_exists($actualPath)  || !getIpswIdFromPath($dmgPath)) {
          $this->sendStatus($from, "error", "The path specified was not found");
          return;
        }

        $result = ["path" => $msg["path"], "extracted" => isDmgExtracted($dmgPath), "size" => filesize($actualPath)];
        $tags = getFileTags($ipswId);
        if (isset($tags[basename($dmgPath)])) $result["tag"] = $tags[basename($dmgPath)];

        $this->sendStatus($from, "dmginfo", extra_fields: $result);
        break;

      case "decryptdmg":
        if (!$this->setHasJob($from)) return;

        $dmgPath = "$cachePath/" . ltrim($msg["path"], "/");
        $actualPath = $dmgPath;
        if (is_file("$dmgPath.original")) $actualPath = "$dmgPath.original";

        if (!isset($msg["path"])) {
          $this->sendStatus($from, "error", "No path specified");
          return;
        } elseif (!str_contains($msg["path"], ".dmg")) {
          $this->sendStatus($from, "error", "The path specified is not a DMG");
          return;
        } elseif (!file_exists($actualPath) || !getIpswIdFromPath($dmgPath)) {
          $this->sendStatus($from, "error", "The path specified was not found");
          return;
        }

        if (file_get_contents($actualPath, length: 8) == "encrcdsa")
          $job = decryptRootFsDmg($dmgPath, $this->loop);
        elseif (pathinfo($dmgPath, PATHINFO_EXTENSION) == "aea")
          $job = decryptAea($actualPath, $this->loop);
        else {
          $this->setHasJob($from, "done");
          $this->sendStatus($from, "done");
          return;
        }

        subscribeToJobAsync($job, function($status, $data) use ($from) {
          $this->setHasJob($from, $status);
          $this->sendStatus($from, $status, extra_fields: $data);
        }, $this->loop);

        break;
      
      case "search":
        $searchPath = "$cachePath/" . ltrim($msg["path"], "/");
        $searchQuery = $msg["query"];

        if (!isset($msg["path"]) || !isset($msg["query"])) {
          $this->sendStatus($from, "error", "Path or query was not specified");
          return;
        } elseif (!is_dir($searchPath) || !getIpswIdFromPath($searchPath)) {
          $this->sendStatus($from, "error", "Directory not found, make sure any DMGs in the path are extracted already");
          return;
        }

        $process = new Process("find " . escapeshellarg($searchPath) . " -mindepth 1 -iname " . escapeshellarg("*$searchQuery*"));
        $process->start();

        $results = "";
        $process->stdout->on("data", function($output) use (&$results) {
          $results .= $output;
        });

        $process->on("exit", function() use (&$results, $from, $ipswId) {
          $results = explode("\n", trim($results));
          $this->sendStatus($from, "results", extra_fields: ["results" => $results[0] != "" ? getDirListing($results, $ipswId) : []]);
        });

        break;
      
      case "storefolder":
        if (!$this->setHasJob($from)) return;

        $path = "$cachePath/" . ltrim($msg["path"], "/");

        if (!isset($msg["path"])) {
          $this->sendStatus($from, "error", "No path specified");
          return;
        } elseif (!file_exists($path)  || !getIpswIdFromPath($path)) {
          $this->sendStatus($from, "error", "The path specified was not found");
          return;
        }

        $job = storeFolder($path, $this->loop);
        subscribeToJobAsync($job, function($status, $data) use ($from) {
          $this->setHasJob($from, $status);
          $this->sendStatus($from, $status, extra_fields: $data);
        }, $this->loop);

        break;
      
      case "ping":
        $this->sendStatus($from, "pong");
        break;

      default:
        $this->sendStatus($from, "error", "Invalid command ($command)");
    }
  }

  public function onClose(ConnectionInterface $conn) {
    $this->clients->offsetUnset($conn);
  }

  public function onError(ConnectionInterface $conn, Exception $e) {
    $conn->send((string)$e);
  }
}

$ipswWs = new IpswWs();

$server = IoServer::factory(
  new HttpServer(
    new WsServer(
      $ipswWs
    )
  ), WS_PORT
);

$ipswWs->loop = $server->loop;
$server->run();