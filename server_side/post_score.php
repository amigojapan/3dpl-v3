<?php
    header("Content-Type: application/json; charset=utf-8");
    //ini_set('display_startup_errors', 1);
    //ini_set('display_errors', 1);
    //error_reporting(-1);

    require_once __DIR__ . '/modular_security.php';

    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        http_response_code(405);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'error' => 'not_post'
        ]);
        exit;
    }

    $NICK = (string)($_POST['NICK'] ?? '');
    $PASSWORD = (string)($_POST['PASSWORD'] ?? '');

    $err = null;
    if (!is_user_identified($NICK, $PASSWORD, $err)) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'error' => 'auth',
            'message' => $err
        ]);
        exit;
    }
    
    // Open DB
    $GAMENAME = (string)($_POST['GAMENAME'] ?? '');
    if($GAMENAME=="Memorize") {
        $db = new SQLite3("Memorize_scores.db");
    } elseif($GAMENAME=="Guessmynumber") { 
            $db = new SQLite3("Guessmynumber_scores.db");
    } elseif($GAMENAME=="Americanfootball") { 
            $db = new SQLite3("Americanfootball_scores.db");
    } elseif($GAMENAME=="Oregontrail") { 
            $db = new SQLite3("Oregontrail_scores.db");
    } elseif($GAMENAME=="Lunarlander") {
            $db = new SQLite3("Lunarlander_scores.db");
    } elseif($GAMENAME=="SNAKE") {
            $db = new SQLite3("SNAKE_scores.db");
    } elseif($GAMENAME=="SOKOBAN") {
            $db = new SQLite3("SOKOBAN_scores.db");
    } elseif($GAMENAME=="Minesweeper") { 
            $db = new SQLite3("Minesweeper_scores.db");
    } elseif($GAMENAME=="PACMANBIG") {
            $db = new SQLite3("PACMANBIG_scores.db");
    }
    // Create table
    $db->exec("
        CREATE TABLE IF NOT EXISTS scores (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            NICK TEXT,
            SCORE TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        );
    ");

    // Get data
    $SCORE = $_POST['SCORE'] ?? '';

    if ($NICK === '' || $SCORE === '') {
        echo json_encode(["status" => "error", "message" => "Missing data"]);
        exit;
    }

    // Insert
    $stmt = $db->prepare("INSERT INTO scores (NICK, SCORE) VALUES (:NICK, :SCORE);");
    //force write so it does not lock the DB
    $db->busyTimeout(5000);
    $db->exec("PRAGMA journal_mode=WAL;");

    $stmt->bindValue(":NICK", $NICK, SQLITE3_TEXT);
    $stmt->bindValue(":SCORE", $SCORE, SQLITE3_TEXT);

    $result = $stmt->execute();

    if ($result) {
        echo json_encode(["status" => "ok"]);
    } else {
        echo json_encode(["status" => "error", "message" => "Insert failed"]);
}
?>
