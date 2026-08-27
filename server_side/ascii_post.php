<?php
session_start();
require_once __DIR__ . '/modular_security.php';
$err = null;
if (!is_user_identified($_POST["NICK"], $_POST["PASSWORD"], $err)) {
    //echo "ERROR:" . $err;
    echo json_encode(["status" => "error"]);
    exit;
}

//$_POST = json_decode(file_get_contents("php://input"), true);

if (!isset($_POST["art"]) || trim($_POST["art"]) === "") {
    echo json_encode(["status" => "error", "message" => "No art provided"]);
    exit;
}

$username = $_POST["NICK"];
$title = substr($_POST["title"] ?? "", 0, 100);
$art = substr($_POST["art"], 0, 20000); // limit size

$db = new SQLite3("userdb.sqlite3");

$stmt = $db->prepare("INSERT INTO ascii_art (username, title, art) VALUES (:u, :t, :a)");
$stmt->bindValue(":u", $username, SQLITE3_TEXT);
$stmt->bindValue(":t", $title, SQLITE3_TEXT);
$stmt->bindValue(":a", $art, SQLITE3_TEXT);
$stmt->execute();

echo "ok";
//$result = $stmt->execute();
/*
if (!$stmt) {
    echo json_encode([
        "status" => "error",
        "message" => $db->lastErrorMsg()
    ]);
    exit;
}

echo json_encode(["status"=>"ok"]);
*/
?>
