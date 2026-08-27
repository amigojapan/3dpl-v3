<?php
    //header("Content-Type: application/json; charset=utf-8");
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
        echo json_encode([
            "status" => "auth_failed",
            "debug" => [
                "NICK_received" => $NICK,
                "PASSWORD_received" => $PASSWORD,
                "error_code" => $err,
                "post_data" => $_POST
            ]
        ]);
        exit;
    }
    // Open DB
    $db = new SQLite3("scores.db");
    $db->busyTimeout(5000);
    // Order (default DESC)
    $order = "DESC";
    if (isset($_POST['order']) && strtolower($_POST['order']) === "asc") {
        $order = "ASC";
    }

    // Query
    $query = "SELECT NICK, SCORE FROM scores ORDER BY CAST(SCORE AS INTEGER) $order";
    $result = $db->query($query);
    if (!$result) {
        echo "Error! DB locked or query failed";
        exit;
    }

    $data = [];

    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        //$data[] = [
        //    "NICK" => $row["NICK"],
        //    "SCORE" => $row["SCORE"]
        //];
        ECHO $row["NICK"]." ".$row["SCORE"]." | ";
    }
    exit(0);
    //***try returning only data and dont rely on json...
    echo json_encode([
        "status" => "ok",
        "data" => $data
    ]);
?>
