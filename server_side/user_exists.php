<?php
header('Content-Type: text/plain');

error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo "ERROR:not POST";
    exit;
}

$nick = $_POST["nickname"] ?? "";
$nick = trim($nick);

if ($nick === "") {
    echo "ERROR:empty";
    exit;
}

try {
    // SAME DB as is_loged_in.php
    $db = new SQLite3(__DIR__ . '/userdb.sqlite3');

    // SAME column name as register.php / is_loged_in.php
    $stmt = $db->prepare("SELECT 1 FROM users WHERE nick = :nick LIMIT 1");
    $stmt->bindValue(":nick", $nick, SQLITE3_TEXT);

    $res = $stmt->execute();
    $row = $res->fetchArray(SQLITE3_NUM);

    echo $row ? "OK" : "ERROR";
} catch (Exception $e) {
    echo "ERROR:" . $e->getMessage();
}
