<?php

// Adjust if you require authentication
// require_once __DIR__ . "/modular_security.php";

$dbPath = __DIR__ . "/userdb.sqlite3";

if (!file_exists($dbPath)) {
    http_response_code(500);
    echo 0;
    exit;
}

$db = new SQLite3($dbPath);

$result = $db->query("SELECT COUNT(*) as count FROM ascii_art");

if (!$result) {
    http_response_code(500);
    echo 0;
    exit;
}

$row = $result->fetchArray(SQLITE3_ASSOC);

echo $row['count'];
