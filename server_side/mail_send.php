<?php
ini_set('display_startup_errors', 1);
ini_set('display_errors', 1);
error_reporting(-1);

require_once __DIR__ . '/modular_security.php';

header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'not_post']);
    exit;
}

$nick     = (string)($_POST['NICK'] ?? '');
$password = (string)($_POST['PASSWORD'] ?? '');

$toNick   = (string)($_POST['TO'] ?? $_POST['to'] ?? '');
$subject  = (string)($_POST['SUBJECT'] ?? $_POST['subject'] ?? '');
$message  = (string)($_POST['MESSAGE'] ?? $_POST['message'] ?? '');

$err = null;
if (!is_user_identified($nick, $password, $err)) {
    http_response_code(401);
    echo json_encode(['error' => 'auth', 'message' => $err]);
    exit;
}

$toNick  = trim($toNick);
$subject = trim($subject);
$message = trim($message);

if ($toNick === '') {
    echo json_encode(['error' => 'missing_to']);
    exit;
}
if ($subject === '') {
    echo json_encode(['error' => 'empty_subject']);
    exit;
}
if ($message === '') {
    echo json_encode(['error' => 'empty_message']);
    exit;
}

// Path to your SQLite database
$dbPath = __DIR__ . '/userdb.sqlite3';

try {
    $db = new SQLite3($dbPath);
    $db->enableExceptions(true);

    // Sender userID
    $stmt = $db->prepare('SELECT userID FROM users WHERE nick = :nick LIMIT 1');
    $stmt->bindValue(':nick', $nick, SQLITE3_TEXT);
    $res = $stmt->execute();
    $senderRow = $res->fetchArray(SQLITE3_ASSOC);

    if (!$senderRow || !isset($senderRow['userID'])) {
        http_response_code(404);
        echo json_encode(['error' => 'sender_not_found']);
        exit;
    }

    // Receiver userID
    $stmt = $db->prepare('SELECT userID FROM users WHERE nick = :nick LIMIT 1');
    $stmt->bindValue(':nick', $toNick, SQLITE3_TEXT);
    $res = $stmt->execute();
    $recvRow = $res->fetchArray(SQLITE3_ASSOC);

    if (!$recvRow || !isset($recvRow['userID'])) {
        http_response_code(404);
        echo json_encode(['error' => 'receiver_not_found']);
        exit;
    }

    // Insert message
    $stmt = $db->prepare('
        INSERT INTO bbs_mail (sender_user_id, receiver_user_id, subject, message_text, is_read)
        VALUES (:sender_id, :receiver_id, :subject, :message_text, 0)
    ');
    $stmt->bindValue(':sender_id', (int)$senderRow['userID'], SQLITE3_INTEGER);
    $stmt->bindValue(':receiver_id', (int)$recvRow['userID'], SQLITE3_INTEGER);
    $stmt->bindValue(':subject', $subject, SQLITE3_TEXT);
    $stmt->bindValue(':message_text', $message, SQLITE3_TEXT);
    $stmt->execute();

    echo json_encode([
        'ok' => true,
        'id' => $db->lastInsertRowID()
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'error' => 'db',
        'message' => $e->getMessage()
    ]);
}
