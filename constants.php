<?php

const DEBUG = true;
const DB_FILENAME = "db.sqlite";
const WS_PORT = 8081;
const CACHE_DIR_NAME = "cache";
const CACHE_DIR = __DIR__ . "/" . CACHE_DIR_NAME;
const DOWNLOAD_PROGRESS_INTERVAL_BYTES = 10490000;
const CACHE_MAX_AGE = 7200;
const CACHE_DEBUG_MODE = false;
const SHARED_OWNERSHIP_GROUP = "ipsw";
const IGNORE_EXTENSIONS = ["original", "defried", "decrypted"];
const JOB_SUBSCRIBE_SCRIPT = __DIR__ . "/job_subscribe.php";
const JOB_CLEAR_RESULT_AFTER_SECONDS = 5;
const JOB_REMOVE_AFTER_INACTIVITY_SECONDS = 10;
const BIN_DIR = __DIR__ . "/bin/";