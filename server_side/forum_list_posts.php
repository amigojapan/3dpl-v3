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

$topicId = (int)($_POST['TOPIC_ID'] ?? 0);
if ($topicId < 1) { echo json_encode(['error' => 'bad_topic_id']); exit; }

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

    // topic exists?
    $t = $db->prepare('SELECT topic_id, title, is_locked FROM forum_topics WHERE topic_id=:tid LIMIT 1');
    $t->bindValue(':tid', $topicId, SQLITE3_INTEGER);
    $trow = $t->execute()->fetchArray(SQLITE3_ASSOC);
    if (!$trow) { http_response_code(404); echo json_encode(['error'=>'no_topic']); exit; }

    $sql = "
      SELECT
        p.post_id,
        p.parent_post_id,
        p.subject,
        p.created_at,
        p.is_deleted,
        u.nick AS author_nick,
        CASE
          WHEN p.is_deleted=1 THEN ''
          ELSE substr(replace(replace(p.body, char(13), ''), char(10), ' '), 1, 120)
        END AS excerpt
      FROM forum_posts p
      JOIN users u ON u.userID = p.author_id
      WHERE p.topic_id = :tid
      ORDER BY p.created_at ASC
    ";

    $stmt = $db->prepare($sql);
    $stmt->bindValue(':tid', $topicId, SQLITE3_INTEGER);
    $res = $stmt->execute();

    $posts = [];
    while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
        $posts[] = $row;
    }

    echo json_encode([
        'topic' => $trow,
        'posts' => $posts
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'db', 'message' => $e->getMessage()]);
}