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

$postId = (int)($_POST['POST_ID'] ?? 0);
if ($postId < 1) { echo json_encode(['error' => 'bad_post_id']); exit; }

$err = null;
if (!is_user_identified($nick, $password, $err)) {
    http_response_code(401);
    echo json_encode(['error' => 'auth', 'message' => $err ?? 'auth_failed']);
    exit;
}

$dbPath = __DIR__ . '/userdb.sqlite3';

try {
    $db = new SQLite3($dbPath);
    $db->enableExceptions(true);

    $sql = "
      SELECT
        p.post_id,
        p.topic_id,
        p.parent_post_id,
        p.subject,
        p.body,
        p.created_at,
        p.is_deleted,
        u.nick AS author_nick,
        t.title AS topic_title,
        t.is_locked
      FROM forum_posts p
      JOIN users u ON u.userID = p.author_id
      JOIN forum_topics t ON t.topic_id = p.topic_id
      WHERE p.post_id = :pid
      LIMIT 1
    ";

    $stmt = $db->prepare($sql);
    $stmt->bindValue(':pid', $postId, SQLITE3_INTEGER);
    $row = $stmt->execute()->fetchArray(SQLITE3_ASSOC);

    if (!$row) { http_response_code(404); echo json_encode(['error'=>'no_post']); exit; }

    if ((int)$row['is_deleted'] === 1) {
        $row['body'] = '[DELETED]';
    }

    echo json_encode($row, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'db', 'message' => $e->getMessage()]);
}