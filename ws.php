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
        if (!ipswIsCached($ipswId)) {
          $this->sendStatus($from, "error", "This IPSW ($ipswId) is not cached, use the `cache` command first");
          return;
        }

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
        
        if (is_dir($location) && pathinfo($location, PATHINFO_EXTENSION) != EXTRACTING_EXTENSION) {
          $this->sendStatus($from, "listing", null, ["listing" => getDirListing($location)]);
        } elseif ($dmgToExtract && (is_file($dmgToExtract) || is_file("$dmgToExtract.decrypted"))) {
          if (!$this->setHasJob($from)) return;

          $job = extractDmg($dmgToExtract, $this->loop);
          subscribeToJobAsync($job, function($status, $data) use ($from, $location) {
            $this->setHasJob($from, $status);
            if ($status == "done") {
              $this->sendStatus($from, "listing", null, ["listing" => getDirListing($location)]);
            } else {
              $this->sendStatus($from, $status, extra_fields: $data);
            }
          }, $this->loop);
        } elseif (!is_file($location)) {
          $this->sendStatus($from, "error", "File/directory not found");
        } else {
          $this->sendStatus($from, "error", "Can only get listing for directories, .dmg files, or .dmg.aea files");
        }
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