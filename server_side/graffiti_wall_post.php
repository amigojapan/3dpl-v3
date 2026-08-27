<?php
    ini_set('display_startup_errors', 1);
    ini_set('display_errors', 1);
    error_reporting(-1);

    require_once __DIR__ . '/modular_security.php';

    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        http_response_code(405);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'error' => 'not_post'
        ]);
        exit;
    }

    $nick = (string)($_POST['NICK'] ?? '');
    $password = (string)($_POST['PASSWORD'] ?? '');
    $graffitiText = (string)($_POST['GRAFFITI'] ?? $_POST['graffiti_text'] ?? '');

    $err = null;
    if (!is_user_identified($nick, $password, $err)) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'error' => 'auth',
            'message' => $err
        ]);
        exit;
    }

    $graffitiText = trim($graffitiText);
    if ($graffitiText === '') {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'error' => 'empty_graffiti'
        ]);
        exit;
    }

    header('Content-Type: application/json; charset=utf-8');

    // Path to your SQLite database
    $dbPath = __DIR__ . '/userdb.sqlite3'; // <-- adjust if needed

    try {
        $db = new SQLite3($dbPath);
        $db->enableExceptions(true);

        $stmt = $db->prepare('SELECT userID FROM users WHERE nick = :nick LIMIT 1');
        $stmt->bindValue(':nick', $nick, SQLITE3_TEXT);
        $result = $stmt->execute();
        $row = $result->fetchArray(SQLITE3_ASSOC);

        if (!$row || !isset($row['userID'])) {
            http_response_code(404);
            echo json_encode([
                'error' => 'user_not_found'
            ]);
            exit;
        }

        $stmt = $db->prepare('
            INSERT INTO graffiti (user_id, graffiti_text)
            VALUES (:user_id, :graffiti_text)
        ');
        $stmt->bindValue(':user_id', (int)$row['userID'], SQLITE3_INTEGER);
        $stmt->bindValue(':graffiti_text', $graffitiText, SQLITE3_TEXT);
        $stmt->execute();

        echo json_encode([
            'ok' => true,
            'id' => $db->lastInsertRowID()
        ]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode([
            'error' => 'Database error',
            'message' => $e->getMessage()
        ]);
    }
?>
