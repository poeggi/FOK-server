<?php
declare(strict_types=1);

const FOK_VERSION = '0.1.0';

// Never leak stack traces or paths to clients; errors go to the server log.
ini_set('display_errors', '0');
ini_set('log_errors', '1');

// Data lives one level ABOVE the docroot so it is never web-accessible.
define('FOK_DOCROOT', dirname(__DIR__));
define('FOK_DATA_DIR', dirname(FOK_DOCROOT) . '/fok-server-data');
define('FOK_DB_FILE', FOK_DATA_DIR . '/fok.db');
define('FOK_ADMIN_HASH_FILE', FOK_DATA_DIR . '/admin.hash');
define('FOK_BACKUP_DIR', FOK_DATA_DIR . '/backups');

// A player counts as online while its last heartbeat is within this window.
const FOK_ONLINE_WINDOW = 60;
// A duel counts as running while either peer refreshed it within this window.
const FOK_DUEL_WINDOW = 60;
// Undelivered signaling messages expire after this many seconds.
const FOK_SIGNAL_TTL = 120;
const FOK_SIGNAL_MAX_PAYLOAD = 16384;
const FOK_TOP_SCORES = 100;
const FOK_MAX_NAME_LEN = 16;

// Game clients are served from these origins (CORS allowlist).
const FOK_ALLOWED_ORIGINS = [
    'https://poeggi.github.io',
    'http://localhost:8000',
    'http://127.0.0.1:8000',
];

const FOK_ADMIN_MAX_FAILS = 5;
const FOK_ADMIN_LOCK_SECONDS = 300;
