<?php

header('Content-Type: application/json');

$dbPath = __DIR__ . "/userdb.sqlite3";

if (!file_exists($dbPath)) {
    echo json_encode([
        "status" => "error",
        "message" => "Database file not found",
        "path" => $dbPath
    ]);
    exit;
}

$db = new SQLite3($dbPath);

// List tables
$tablesResult = $db->query("SELECT name FROM sqlite_master WHERE type='table'");
$tables = [];

while ($row = $tablesResult->fetchArray(SQLITE3_ASSOC)) {
    $tables[] = $row['name'];
}

// Check ascii_art table exists
if (!in_array("ascii_art", $tables)) {
    echo json_encode([
        "status" => "error",
        "message" => "ascii_art table does not exist",
        "tables_found" => $tables
    ]);
    exit;
}

// Count rows
$countResult = $db->query("SELECT COUNT(*) as count FROM ascii_art");
$countRow = $countResult->fetchArray(SQLITE3_ASSOC);
$rowCount = $countRow['count'] ?? 0;

// Get latest row
$latestResult = $db->query("SELECT * FROM ascii_art ORDER BY id DESC LIMIT 1");
$latestRow = $latestResult->fetchArray(SQLITE3_ASSOC);

echo json_encode([
    "status" => "ok",
    "db_path" => realpath($dbPath),
    "tables" => $tables,
    "row_count" => $rowCount,
    "latest_row" => $latestRow
]);
