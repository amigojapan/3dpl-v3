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

$limit = (int)($_POST['LIMIT'] ?? 10);
if ($limit < 1) $limit = 10;
if ($limit > 50) $limit = 50;

$unreadOnly = (string)($_POST['UNREAD_ONLY'] ?? '0') === '1';
$markRead   = (string)($_POST['MARK_READ'] ?? '0') === '1';

$err = null;
if (!is_user_identified($nick, $password, $err)) {
    http_response_code(401);
    echo json_encode(['error' => 'auth', 'message' => $err]);
    exit;
}

// Path to your SQLite database
$dbPath = __DIR__ . '/userdb.sqlite3';

try {
    $db = new SQLite3($dbPath);
    $db->enableExceptions(true);

    // Get receiver userID (logged in user)
    $stmt = $db->prepare('SELECT userID FROM users WHERE nick = :nick LIMIT 1');
    $stmt->bindValue(':nick', $nick, SQLITE3_TEXT);
    $res = $stmt->execute();
    $userRow = $res->fetchArray(SQLITE3_ASSOC);

    if (!$userRow || !isset($userRow['userID'])) {
        http_response_code(404);
        echo json_encode(['error' => 'user_not_found']);
        exit;
    }

    $receiverId = (int)$userRow['userID'];

    $where = 'm.receiver_user_id = :rid';
    if ($unreadOnly) {
        $where .= ' AND m.is_read = 0';
    }

    $sql = "
        SELECT
            m.id,
            s.nick AS from_username,
            r.nick AS to_username,
            m.subject,
            m.message_text,
            m.is_read,
            m.created_at
        FROM bbs_mail m
        JOIN users s ON m.sender_user_id = s.userID
        JOIN users r ON m.receiver_user_id = r.userID
        WHERE $where
        ORDER BY m.created_at DESC
        LIMIT :lim
    ";

    $stmt = $db->prepare($sql);
    $stmt->bindValue(':rid', $receiverId, SQLITE3_INTEGER);
    $stmt->bindValue(':lim', $limit, SQLITE3_INTEGER);

    $result = $stmt->execute();

    $rows = [];
    $idsToMark = [];

    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $rows[] = $row;
        if ($markRead && isset($row['id'])) {
            $idsToMark[] = (int)$row['id'];
        }
    }

    // Optionally mark returned messages as read
    if ($markRead && count($idsToMark) > 0) {
        $db->exec('BEGIN');
        $markStmt = $db->prepare('
            UPDATE bbs_mail
            SET is_read = 1
            WHERE id = :id AND receiver_user_id = :rid
        ');
        foreach ($idsToMark as $mid) {
            $markStmt->bindValue(':id', $mid, SQLITE3_INTEGER);
            $markStmt->bindValue(':rid', $receiverId, SQLITE3_INTEGER);
            $markStmt->execute();
        }
        $db->exec('COMMIT');
    }

    echo json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'error' => 'db',
        'message' => $e->getMessage()
    ]);
}
