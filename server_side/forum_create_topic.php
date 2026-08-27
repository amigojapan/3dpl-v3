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

$title = trim((string)($_POST['TITLE'] ?? ''));
$body  = trim((string)($_POST['BODY'] ?? ''));

if ($title === '') { echo json_encode(['error' => 'empty_title']); exit; }
if ($body === '')  { echo json_encode(['error' => 'empty_body']); exit; }

$err = null;
if (!is_user_identified($nick, $password, $err)) {
    http_response_code(401);
    echo json_encode(['error' => 'auth', 'message' => $err ?? 'auth_failed']);
    exit;
}

$dbPath = __DIR__ . '/userdb.sqlite3';
$now = time();

try {
    $db = new SQLite3($dbPath);
    $db->enableExceptions(true);

    // get userID
    $u = $db->prepare('SELECT userID FROM users WHERE nick=:nick LIMIT 1');
    $u->bindValue(':nick', $nick, SQLITE3_TEXT);
    $urow = $u->execute()->fetchArray(SQLITE3_ASSOC);
    if (!$urow) {
        http_response_code(404);
        echo json_encode(['error' => 'no_user']);
        exit;
    }
    $uid = (int)$urow['userID'];

    $db->exec('BEGIN');

    $t = $db->prepare('INSERT INTO forum_topics (title, creator_id, created_at, last_post_at, is_locked) VALUES (:title, :creator_id, :created_at, :last_post_at, 0)');
    $t->bindValue(':title', $title, SQLITE3_TEXT);
    $t->bindValue(':creator_id', $uid, SQLITE3_INTEGER);
    $t->bindValue(':created_at', $now, SQLITE3_INTEGER);
    $t->bindValue(':last_post_at', $now, SQLITE3_INTEGER);
    $t->execute();

    $topicId = (int)$db->lastInsertRowID();

    // first post is the "topic root"
    $p = $db->prepare('INSERT INTO forum_posts (topic_id, parent_post_id, author_id, subject, body, created_at, is_deleted) VALUES (:topic_id, NULL, :author_id, :subject, :body, :created_at, 0)');
    $p->bindValue(':topic_id', $topicId, SQLITE3_INTEGER);
    $p->bindValue(':author_id', $uid, SQLITE3_INTEGER);
    $p->bindValue(':subject', $title, SQLITE3_TEXT);
    $p->bindValue(':body', $body, SQLITE3_TEXT);
    $p->bindValue(':created_at', $now, SQLITE3_INTEGER);
    $p->execute();

    $postId = (int)$db->lastInsertRowID();

    $db->exec('COMMIT');

    echo json_encode([
        'ok' => true,
        'topic_id' => $topicId,
        'first_post_id' => $postId
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

} catch (Exception $e) {
    try { $db->exec('ROLLBACK'); } catch (Exception $e2) {}
    http_response_code(500);
    echo json_encode(['error' => 'db', 'message' => $e->getMessage()]);
}