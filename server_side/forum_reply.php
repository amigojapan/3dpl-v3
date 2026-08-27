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

$parentPostIdRaw = trim((string)($_POST['PARENT_POST_ID'] ?? ''));
$parentPostId = 0;
if ($parentPostIdRaw !== '') $parentPostId = (int)$parentPostIdRaw; // 0 => treat as no parent

$subject = trim((string)($_POST['SUBJECT'] ?? ''));
$body    = trim((string)($_POST['BODY'] ?? ''));

if ($subject === '') { echo json_encode(['error' => 'empty_subject']); exit; }
if ($body === '')    { echo json_encode(['error' => 'empty_body']); exit; }

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

    // userID
    $u = $db->prepare('SELECT userID FROM users WHERE nick = :nick LIMIT 1');
    $u->bindValue(':nick', $nick, SQLITE3_TEXT);
    $urow = $u->execute()->fetchArray(SQLITE3_ASSOC);
    if (!$urow) { http_response_code(404); echo json_encode(['error' => 'no_user']); exit; }
    $uid = (int)$urow['userID'];

    // topic exists and not locked
    $t = $db->prepare('SELECT is_locked FROM forum_topics WHERE topic_id=:tid LIMIT 1');
    $t->bindValue(':tid', $topicId, SQLITE3_INTEGER);
    $trow = $t->execute()->fetchArray(SQLITE3_ASSOC);
    if (!$trow) { http_response_code(404); echo json_encode(['error'=>'no_topic']); exit; }
    if ((int)$trow['is_locked'] === 1) { http_response_code(403); echo json_encode(['error'=>'locked']); exit; }

    // if parent is specified, verify it belongs to same topic
    $parentSqlValue = null;
    if ($parentPostId > 0) {
        $pp = $db->prepare('SELECT post_id FROM forum_posts WHERE post_id=:pid AND topic_id=:tid LIMIT 1');
        $pp->bindValue(':pid', $parentPostId, SQLITE3_INTEGER);
        $pp->bindValue(':tid', $topicId, SQLITE3_INTEGER);
        $pprow = $pp->execute()->fetchArray(SQLITE3_ASSOC);
        if (!$pprow) { http_response_code(400); echo json_encode(['error'=>'bad_parent']); exit; }
        $parentSqlValue = $parentPostId;
    }

    $db->exec('BEGIN');

    if ($parentSqlValue === null) {
        $p = $db->prepare('INSERT INTO forum_posts (topic_id, parent_post_id, author_id, subject, body, created_at, is_deleted)
                           VALUES (:topic_id, NULL, :author_id, :subject, :body, :created_at, 0)');
    } else {
        $p = $db->prepare('INSERT INTO forum_posts (topic_id, parent_post_id, author_id, subject, body, created_at, is_deleted)
                           VALUES (:topic_id, :parent_post_id, :author_id, :subject, :body, :created_at, 0)');
        $p->bindValue(':parent_post_id', $parentSqlValue, SQLITE3_INTEGER);
    }

    $p->bindValue(':topic_id', $topicId, SQLITE3_INTEGER);
    $p->bindValue(':author_id', $uid, SQLITE3_INTEGER);
    $p->bindValue(':subject', $subject, SQLITE3_TEXT);
    $p->bindValue(':body', $body, SQLITE3_TEXT);
    $p->bindValue(':created_at', $now, SQLITE3_INTEGER);
    $p->execute();

    $newPostId = (int)$db->lastInsertRowID();

    $up = $db->prepare('UPDATE forum_topics SET last_post_at=:ts WHERE topic_id=:tid');
    $up->bindValue(':ts', $now, SQLITE3_INTEGER);
    $up->bindValue(':tid', $topicId, SQLITE3_INTEGER);
    $up->execute();

    $db->exec('COMMIT');

    echo json_encode(['ok'=>true, 'post_id'=>$newPostId], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

} catch (Exception $e) {
    try { $db->exec('ROLLBACK'); } catch (Exception $e2) {}
    http_response_code(500);
    echo json_encode(['error'=>'db', 'message'=>$e->getMessage()]);
}