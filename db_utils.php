<?php

use flight\database\SimplePdo;
use flight\util\Collection;

require_once "vendor/autoload.php";
require_once "constants.php";

Flight::register("db", SimplePdo::class, ["sqlite:" . DB_FILENAME]);

// This wrapper class exists to provide autocompletion for the database connection
class SimplePdoWrapper {
  private $conn;

  public function __construct($conn) {
    $this->conn = $conn;
  }

  public function runQuery(string $sql, array $params = []): PDOStatement {
    return $this->conn->runQuery($sql, $params);
  }

  public function fetchField(string $sql, array $params = []): mixed {
    return $this->conn->fetchField($sql, $params);
  }

  public function fetchRow(string $sql, array $params = []): ?Collection {
    return $this->conn->fetchRow($sql, $params);
  }

  public function fetchAll(string $sql, array $params = []): array {
    return $this->conn->fetchAll($sql, $params);
  }

  public function fetchColumn(string $sql, array $params = []): array {
    return $this->conn->fetchColumn($sql, $params);
  }

  public function fetchPairs(string $sql, array $params = []): array {
    return $this->conn->fetchPairs($sql, $params);
  }
}
$db = new SimplePdoWrapper(Flight::db());

function getIpswInfo($id) {
  global $db;

  $info = $db->fetchRow("SELECT * FROM ipsw WHERE id = ?", [$id]);
  if (!$info) return;

  $deviceName = $db->fetchField("SELECT name FROM devices WHERE id = ?", [$info->device_id]);
  if ($deviceName) {
    $info->device = new Collection([
      "id" => $info->device_id,
      "name" => $deviceName
    ]);
    unset($info->device_id);
  }

  return $info;
}

function getDeviceInfo($id) {
  global $db;
  return $db->fetchRow("SELECT * FROM devices WHERE id = ?", [$id]);
}

function getIpswKeys($id) {
  global $db;

  $rows = $db->fetchAll("SELECT * FROM keys WHERE ipsw_id = ?", [$id]);
  if (empty($rows)) return;

  $keys = [];
  foreach ($rows as $row) {
    $keys[$row->filename] = [
      "key" => $row->key
    ] + ($row->iv ? [
      "iv" => $row->iv
    ] : []);
  }

  return $keys;
}

function getKeyForIpswFile($ipswId, $filename) {
  global $db;

  $key = $db->fetchRow("SELECT key, iv FROM keys WHERE ipsw_id = ? AND filename = ?", [$ipswId, $filename]);
  if (!$key) return;
  if (!$key->iv) unset($key->iv);

  return $key;
}

function getCacheEntries() {
  global $db;

  $rows = $db->fetchAll("SELECT * FROM cache");

  $caches = [];
  foreach ($rows as $row) {
    $caches[$row->ipsw_id] = $row->expires_at;
  }

  return $caches;
}

function updateExpireTimestamp($ipswId) {
  global $db;

  if (CACHE_DEBUG_MODE) return;
  if (!$ipswId) return;

  if ($db->fetchRow("SELECT * FROM cache WHERE ipsw_id = ?", [$ipswId])) {
    $db->runQuery("UPDATE cache SET expires_at = ? WHERE ipsw_id = ?", [time() + CACHE_MAX_AGE, $ipswId]);
  } else {
    $db->runQuery("INSERT INTO cache (ipsw_id, expires_at) VALUES (?, ?)", [$ipswId, time() + CACHE_MAX_AGE]);
  }
}

function removeCacheEntry($ipswId) {
  global $db;
  $db->runQuery("DELETE FROM cache WHERE ipsw_id = ?", [$ipswId]);
}