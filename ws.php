<?php

require_once "vendor/autoload.php";
require_once "constants.php";
require_once "ipsw_utils.php";
require_once "db.php";

use Ratchet\MessageComponentInterface;
use Ratchet\ConnectionInterface;
use Ratchet\Http\HttpServer;
use Ratchet\Server\IoServer;
use Ratchet\WebSocket\WsServer;
use React\EventLoop\LoopInterface;

class IpswWs implements MessageComponentInterface {
  protected $clients;
  public LoopInterface $loop;
  protected $progress_current;
  protected $progress_total;

  private function sendStatus(ConnectionInterface $conn, $status, $message = null, $extra_fields = []) {
    $conn->send(json_encode([
      "status" => $status
    ] + (($message != null) ? ["message" => $message] : []) + $extra_fields));
  }

  public function __construct()
  {
    $this->clients = new SplObjectStorage();
  }

  public function onOpen(ConnectionInterface $conn) {
    global $db;

    $requestUri = $conn->httpRequest->getUri();
    $ipswId = explode("/", $requestUri)[3];

    if (!array_key_exists($ipswId, $db["ipsw"])) {
      $this->sendStatus($conn, "error", "The IPSW ($ipswId) was not found.");
      $conn->close();
    }

    $this->clients->offsetSet($conn, $ipswId);
  }

  public function onMessage(ConnectionInterface $from, $msg) {
    $msg = json_decode($msg, true);
    $command = $msg["command"];
    $ipswId = $this->clients[$from];
    $cachePath = CACHE_DIR . "/$ipswId";

    switch ($command) {
      case "cache":
        cacheIpswContents($ipswId, $this->loop, function($current, $total) use ($from) {
          $this->sendStatus($from, "downloading", null, [
            "bytes_downloaded" => $current,
            "bytes_total" => $total,
            "steps_done" => 0,
            "steps_total" => 2
          ]);
        }, function($current, $total) use ($from) {
          $this->sendStatus($from, "extracting", null, [
            "files_extracted" => $current,
            "files_total" => $total,
            "steps_done" => 1,
            "steps_total" => 2
          ]);
        }, function() use ($from) {
          $this->sendStatus($from, "done", null, [
            "steps_done" => 2,
            "steps_total" => 2
          ]);
        }, function($error) use ($from) {
          $this->sendStatus($from, "error", $error);
        });
        break;
      
      case "listing":
        if (!ipswIsCached($ipswId)) {
          $this->sendStatus($from, "error", "The IPSW ($ipswId) is not cached. Use the \"cache_ipsw\" command first.");
          return;
        }

        $location = $msg["location"] ?? "/";
        if (substr($location, 0, 1) != "/") {
          $location = "/$location";
        }
        $location = "$cachePath$location";
        
        if (is_dir($location)) {
          $this->sendStatus($from, "listing", null, getDirListing($location));
        } elseif (pathinfo($location, PATHINFO_EXTENSION) == "dmg") {
          // Not implemented
        } else {
          $this->sendStatus($from, "error", "Can only get listing for directories or .dmg files.");
        }
        break;
      
      default:
        $this->sendStatus($from, "error", "Invalid command ($command)");
    }
  }

  public function onClose(ConnectionInterface $conn) {
    
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