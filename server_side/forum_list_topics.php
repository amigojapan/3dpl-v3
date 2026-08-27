<?php
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(E_ALL);
header('Content-Type: application/json; charset=utf-8');

ob_start();

require_once __DIR__ . '/modular_security.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'not_post']);
    exit;
}

$nick     = (string)($_POST['NICK'] ?? '');
$password = (string)($_POST['PASSWORD'] ?? '');

$limit  = (int)($_POST['LIMIT'] ?? 20);
$offset = (int)($_POST['OFFSET'] ?? 0);
if ($limit < 1) $limit = 20;
if ($limit > 50) $limit = 50;
if ($offset < 0) $offset = 0;

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
        t.topic_id,
        t.title,
        t.created_at,
        t.last_post_at,
        t.is_locked,
        u.nick AS creator_nick,
        (SELECT COUNT(*) FROM forum_posts p WHERE p.topic_id=t.topic_id AND p.is_deleted=0) AS post_count
      FROM forum_topics t
      JOIN users u ON u.userID = t.creator_id
      ORDER BY t.last_post_at DESC
      LIMIT :limit OFFSET :offset
    ";

    $stmt = $db->prepare($sql);
    $stmt->bindValue(':limit', $limit, SQLITE3_INTEGER);
    $stmt->bindValue(':offset', $offset, SQLITE3_INTEGER);

    $res = $stmt->execute();

    $rows = [];
    while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
        $row['reply_count'] = max(0, ((int)$row['post_count']) - 1); // minus first post
        unset($row['post_count']);
        $rows[] = $row;
    }

    echo json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

} catch (Exception $e) {
    ob_clean();
    http_response_code(500);
    echo json_encode(['error' => 'db', 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    exit;
}