<?php
session_start();
require_once __DIR__ . '/modular_security.php';
$err = null;
if (!is_user_identified($_POST["NICK"], $_POST["PASSWORD"], $err)) {
    exit;
}

$id = intval($_GET["id"]);
$db = new SQLite3("userdb.sqlite3");

$stmt = $db->prepare("SELECT * FROM ascii_art WHERE id = :id");
$stmt->bindValue(":id", $id, SQLITE3_INTEGER);
$result = $stmt->execute();
$row = $result->fetchArray(SQLITE3_ASSOC);

if (!$row) exit;

header("Content-Type: text/plain");
header("Content-Disposition: attachment; filename=ascii_art_" . $id . ".txt");

echo $row["art"];
?>
