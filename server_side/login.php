<?php
declare(strict_types=1);

require_once __DIR__ . '/api_common.php';

api_require_method('POST');
$data = api_request_data();

$nick = api_string($data, ['nick', 'nickname']);
$password = api_string($data, ['password', 'pw'], false);

if (!api_valid_nick($nick) || $password === '' || strlen($password) > 4096) {
    api_fail('invalid_credentials', 'Invalid nickname or password.', 401);
}

$db = null;
try {
    $db = api_database();
    $statement = $db->prepare(
        'SELECT nick, pw, salt FROM users WHERE nick = :nick'
    );
    $statement->bindValue(':nick', $nick, SQLITE3_TEXT);
    $result = $statement->execute();
    $row = false;
    while (($candidate = $result->fetchArray(SQLITE3_ASSOC)) !== false) {
        if (isset($candidate['pw'], $candidate['salt'])
            && hash_equals(
                (string)$candidate['pw'],
                api_password_hash($password, (string)$candidate['salt'])
            )) {
            $row = $candidate;
            break;
        }
    }
    if ($row === false) {
        api_fail('invalid_credentials', 'Invalid nickname or password.', 401);
    }

    $canonicalNick = (string)$row['nick'];
    api_objects_directory($canonicalNick, true);
    api_start_session();
    if (!@session_regenerate_id(true)) {
        throw new RuntimeException('The login session could not be secured.');
    }
    $_SESSION[THREEDPL_SESSION_NICK] = $canonicalNick;
    session_write_close();

    api_json([
        'ok' => true,
        'nick' => $canonicalNick,
        'message' => 'Login successful.',
    ]);
} catch (Throwable $error) {
    error_log('3DPL login error: ' . $error->getMessage());
    api_fail('login_failed', 'Login could not be completed.', 500);
} finally {
    if ($db instanceof SQLite3) {
        $db->close();
    }
}
