<?php
declare(strict_types=1);

require_once __DIR__ . '/api_common.php';

api_require_method('POST');
$data = api_request_data();

$nick = api_string($data, ['nick', 'nickname']);
$email = api_string($data, ['email']);
$password = api_string($data, ['password', 'pw'], false);

if (!api_valid_nick($nick)) {
    api_fail(
        'invalid_nick',
        'Nickname must be 1 to 32 characters and use only letters, numbers, underscores, or hyphens.',
        422
    );
}
if (filter_var($email, FILTER_VALIDATE_EMAIL) === false || strlen($email) > 254) {
    api_fail('invalid_email', 'Enter a valid email address.', 422);
}
if (strlen($password) < 8 || strlen($password) > 4096) {
    api_fail('invalid_password', 'Password must contain at least 8 characters.', 422);
}

try {
    $usersRoot = api_users_root(true);
} catch (Throwable $error) {
    error_log('3DPL user storage setup error: ' . $error->getMessage());
    api_fail(
        'storage_unavailable',
        'User storage is unavailable. The server administrator must make server_side/Users writable by PHP.',
        503
    );
}
if (!is_writable($usersRoot)) {
    api_fail(
        'storage_not_writable',
        'User storage is not writable. The server administrator must grant PHP write access to server_side/Users.',
        503
    );
}

$databaseWriteIssue = api_database_write_issue();
if ($databaseWriteIssue === 'database_file_not_writable') {
    api_fail(
        'database_file_not_writable',
        'The account database is not writable. Grant PHP write access to server_side/userdb.sqlite3.',
        503
    );
}
if ($databaseWriteIssue === 'database_directory_not_writable') {
    api_fail(
        'database_directory_not_writable',
        'SQLite cannot create its journal. Grant PHP write access to the server_side directory.',
        503
    );
}
if ($databaseWriteIssue !== null) {
    api_fail('database_unavailable', 'The account database path is not safe to use.', 503);
}

$db = null;
$transactionStarted = false;
$createdUserDirectory = null;
$registrationStage = 'database_open';

try {
    $db = api_database();
    $db->exec('BEGIN IMMEDIATE');
    $transactionStarted = true;

    $statement = $db->prepare(
        'SELECT nick, email FROM users '
        . 'WHERE nick = :nick COLLATE NOCASE OR email = :email COLLATE NOCASE LIMIT 1'
    );
    $statement->bindValue(':nick', $nick, SQLITE3_TEXT);
    $statement->bindValue(':email', $email, SQLITE3_TEXT);
    $existing = $statement->execute()->fetchArray(SQLITE3_ASSOC);
    if ($existing !== false) {
        $db->exec('ROLLBACK');
        $transactionStarted = false;
        if (strcasecmp((string)$existing['nick'], $nick) === 0) {
            api_fail('nick_taken', 'That nickname is already registered.', 409);
        }
        api_fail('email_taken', 'That email address is already registered.', 409);
    }

    // Refuse to attach a new account to files from an old/orphaned account.
    $registrationStage = 'user_storage_check';
    if (api_find_user_directory_case_insensitive($nick) !== null) {
        $db->exec('ROLLBACK');
        $transactionStarted = false;
        api_fail('nick_storage_exists', 'Storage already exists for that nickname.', 409);
    }

    $salt = bin2hex(random_bytes(16));
    $hash = api_password_hash($password, $salt);

    $registrationStage = 'database_write';
    $statement = $db->prepare(
        'INSERT INTO users (nick, pw, salt, email) VALUES (:nick, :pw, :salt, :email)'
    );
    $statement->bindValue(':nick', $nick, SQLITE3_TEXT);
    $statement->bindValue(':pw', $hash, SQLITE3_TEXT);
    $statement->bindValue(':salt', $salt, SQLITE3_TEXT);
    $statement->bindValue(':email', $email, SQLITE3_TEXT);
    $statement->execute();

    $registrationStage = 'user_storage_create';
    $objectsDirectory = api_objects_directory($nick, true);
    $createdUserDirectory = dirname($objectsDirectory);

    $registrationStage = 'database_commit';
    $db->exec('COMMIT');
    $transactionStarted = false;

    api_json([
        'ok' => true,
        'nick' => $nick,
        'message' => 'Registration successful. You can now log in.',
    ], 201);
} catch (Throwable $error) {
    if ($transactionStarted && $db instanceof SQLite3) {
        try {
            $db->exec('ROLLBACK');
        } catch (Throwable $rollbackError) {
            error_log('3DPL registration rollback error: ' . $rollbackError->getMessage());
        }
    }
    if ($createdUserDirectory !== null && is_dir($createdUserDirectory)) {
        $objectsDirectory = $createdUserDirectory . DIRECTORY_SEPARATOR . 'Objects';
        @rmdir($objectsDirectory);
        @rmdir($createdUserDirectory);
    }
    error_log('3DPL registration error: ' . $error->getMessage());
    if ($registrationStage === 'user_storage_check' || $registrationStage === 'user_storage_create') {
        api_fail(
            'user_storage_failed',
            'The account was not created because its user storage directory could not be created.',
            503
        );
    }
    if (
        $registrationStage === 'database_open'
        || $registrationStage === 'database_write'
        || $registrationStage === 'database_commit'
    ) {
        api_fail(
            'database_write_failed',
            'The account database could not be updated. Check PHP write access to userdb.sqlite3 and server_side.',
            503
        );
    }
    api_fail('registration_failed', 'Registration could not be completed.', 500);
} finally {
    if ($db instanceof SQLite3) {
        $db->close();
    }
}
