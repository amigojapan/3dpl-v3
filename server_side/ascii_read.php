<?php
session_start();
require_once __DIR__ . '/modular_security.php';
$err = null;

function out($result){
    $row = $result->fetchArray(SQLITE3_ASSOC);
    if (!$row) {
        echo json_encode(["status" => "empty"]);
        exit;
    }

    echo json_encode([
        "status" => "ok",
        "id" => $row["id"],
        "username" => $row["username"],
        "title" => $row["title"],
        "art" => $row["art"],
        "created_at" => $row["created_at"]
    ]);
    exit(0);
}


if (!is_user_identified($_POST["NICK"], $_POST["PASSWORD"], $err)) {
    //echo "ERROR:" . $err;
    //echo (["status" => "error"]);
    //exit;
}

$db = new SQLite3("userdb.sqlite3");

$id = isset($_POST["id"]) ? intval($_POST["id"]) : null;
$direction = $_POST["direction"] ?? null;

//$query = "SELECT * FROM ascii_art ORDER BY id DESC LIMIT 1;";
//$result = $db->query($query);
//$row = $result->fetchArray(SQLITE3_ASSOC);
//out($row);


$relative = isset($_POST['relative']) ? (int)$_POST['relative'] : null;

if ($relative !== null && $relative > 0) {

    $offset = $relative - 1;

    $stmt = $db->prepare("
        SELECT * FROM ascii_art
        ORDER BY id ASC
        LIMIT 1 OFFSET :offset;
    ");
    $stmt->bindValue(":offset", $offset, SQLITE3_INTEGER);

    $result = $stmt->execute();
    out($result);
}
if ($id === null) {
    // latest
    $query = "SELECT * FROM ascii_art ORDER BY id DESC LIMIT 1";
    $result = $db->query($query);
    out($result);
}
else if ($direction === "next") {
    $stmt = $db->prepare("SELECT * FROM ascii_art WHERE id > :id ORDER BY id ASC LIMIT 1");
    $stmt->bindValue(":id", $id, SQLITE3_INTEGER);
    $result = $stmt->execute();
    out($result);
}
else if ($direction === "prev") {
    $stmt = $db->prepare("SELECT * FROM ascii_art WHERE id < :id ORDER BY id DESC LIMIT 1");
    $stmt->bindValue(":id", $id, SQLITE3_INTEGER);
    $result = $stmt->execute();
    out($result);
}
else {
    $stmt = $db->prepare("SELECT * FROM ascii_art WHERE id = :id");
    $stmt->bindValue(":id", $id, SQLITE3_INTEGER);
    $result = $stmt->execute();
    out($result);
}

if (!isset($result)) {
    $result = $db->query($query);
    out($result);
}


?>
