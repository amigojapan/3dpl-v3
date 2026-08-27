<?php
declare(strict_types=1);

const THREEDPL_SESSION_NICK = '3dpl_user_nick';
const THREEDPL_MAX_OBJECT_BYTES = 20 * 1024 * 1024;
const THREEDPL_MAX_MAP_BYTES = 20 * 1024 * 1024;
const THREEDPL_MAX_PROGRAM_PART_BYTES = 2 * 1024 * 1024;

function api_json(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function api_fail(string $code, string $message, int $status): never
{
    api_json([
        'ok' => false,
        'error' => $code,
        'message' => $message,
    ], $status);
}

function api_require_method(string $method): void
{
    if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? '')) !== strtoupper($method)) {
        header('Allow: ' . strtoupper($method));
        api_fail('method_not_allowed', 'This endpoint only accepts ' . strtoupper($method) . ' requests.', 405);
    }
}

/** @return array<string, mixed> */
function api_request_data(): array
{
    $contentType = strtolower(trim(explode(';', (string)($_SERVER['CONTENT_TYPE'] ?? ''))[0]));
    if ($contentType !== 'application/json') {
        return $_POST;
    }

    $raw = file_get_contents('php://input');
    if ($raw === false || trim($raw) === '') {
        api_fail('invalid_json', 'The request body must contain a JSON object.', 400);
    }

    try {
        $data = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
    } catch (JsonException $error) {
        api_fail('invalid_json', 'The request body contains invalid JSON.', 400);
    }

    if (!is_array($data) || array_is_list($data)) {
        api_fail('invalid_json', 'The request body must contain a JSON object.', 400);
    }

    return $data;
}

function api_string(array $data, array $keys, bool $trim = true): string
{
    foreach ($keys as $key) {
        if (array_key_exists($key, $data) && is_scalar($data[$key])) {
            $value = (string)$data[$key];
            return $trim ? trim($value) : $value;
        }
    }
    return '';
}

function api_valid_nick(string $nick): bool
{
    return preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{0,31}$/D', $nick) === 1;
}

function api_valid_object_name(string $name): bool
{
    if (strlen($name) > 128 || $name !== basename($name)) {
        return false;
    }

    return preg_match('/^[A-Za-z0-9][A-Za-z0-9 ._-]*\.json$/iD', $name) === 1;
}

function api_valid_program_name(string $name): bool
{
    if (strlen($name) > 128 || $name !== basename($name)) {
        return false;
    }

    return preg_match('/^[A-Za-z0-9](?:[A-Za-z0-9 ._-]{0,126}[A-Za-z0-9_-])?$/D', $name) === 1;
}

function api_normalize_program_name(string $name): string
{
    $name = trim($name);
    $name = preg_replace('/\.(?:declarations|update)$/i', '', $name) ?? '';
    return trim($name);
}

function api_normalize_json_name(string $name): string
{
    $name = trim($name);
    if ($name !== '' && !str_ends_with(strtolower($name), '.json')) {
        $name .= '.json';
    }
    return $name;
}

function api_find_entry_case_insensitive(string $directory, string $name): ?string
{
    $entries = @scandir($directory);
    if ($entries === false) {
        throw new RuntimeException('The storage directory cannot be read.');
    }

    foreach ($entries as $entry) {
        if ($entry !== '.' && $entry !== '..' && strcasecmp($entry, $name) === 0) {
            return $entry;
        }
    }
    return null;
}

function api_shared_library_directory(string $library): string
{
    if (!in_array($library, ['Objects', 'Programs', 'Maps'], true)) {
        throw new InvalidArgumentException('Invalid shared library.');
    }

    $directory = dirname(__DIR__) . DIRECTORY_SEPARATOR . $library;
    if (!is_dir($directory) || is_link($directory)) {
        throw new RuntimeException('The shared library is not a safe directory.');
    }
    return $directory;
}

function api_shared_name_exists(string $library, string $name): bool
{
    $directory = api_shared_library_directory($library);
    return api_find_entry_case_insensitive($directory, $name) !== null;
}

function api_database_path(): string
{
    return __DIR__ . DIRECTORY_SEPARATOR . 'userdb.sqlite3';
}

/**
 * Return the part of the SQLite storage that PHP cannot write, or null when
 * the database and its rollback-journal directory are writable.
 */
function api_database_write_issue(): ?string
{
    $databasePath = api_database_path();

    if (is_link($databasePath) || (file_exists($databasePath) && !is_file($databasePath))) {
        return 'unsafe_database_path';
    }

    if (file_exists($databasePath) && !is_writable($databasePath)) {
        // This can repair a mode changed by an uploader when PHP owns the file.
        // It cannot override Unix ownership or hosting-provider ACLs.
        @chmod($databasePath, 0664);
        clearstatcache(true, $databasePath);
    }

    if (file_exists($databasePath) && !is_writable($databasePath)) {
        return 'database_file_not_writable';
    }

    // SQLite's default rollback journal is created beside the database. The
    // directory therefore needs write access even when the .sqlite3 file does.
    clearstatcache(true, __DIR__);
    if (!is_writable(__DIR__)) {
        return 'database_directory_not_writable';
    }

    return null;
}

function api_database(): SQLite3
{
    if (!class_exists('SQLite3')) {
        throw new RuntimeException('The PHP SQLite3 extension is not installed.');
    }

    $db = new SQLite3(api_database_path(), SQLITE3_OPEN_READWRITE | SQLITE3_OPEN_CREATE);
    $db->enableExceptions(true);
    $db->busyTimeout(5000);
    $db->exec('PRAGMA foreign_keys = ON');
    $db->exec(
        'CREATE TABLE IF NOT EXISTS users (' .
        'userID INTEGER PRIMARY KEY AUTOINCREMENT,' .
        'nick TEXT NOT NULL,' .
        'pw TEXT NOT NULL,' .
        'email TEXT NOT NULL UNIQUE,' .
        'salt TEXT NOT NULL UNIQUE' .
        ')'
    );
    return $db;
}

function api_password_hash(string $password, string $salt): string
{
    // Keep the existing database format so accounts continue to work with the
    // other legacy server-side scripts that read the same users table.
    return hash('sha512', $salt . $password);
}

function api_start_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    $secure = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off')
        || (string)($_SERVER['SERVER_PORT'] ?? '') === '443';

    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    session_name('THREEDPLSESSID');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    if (!@session_start()) {
        throw new RuntimeException('Unable to start a login session.');
    }
}

function api_require_user(): string
{
    try {
        api_start_session();
    } catch (Throwable $error) {
        error_log('3DPL session error: ' . $error->getMessage());
        api_fail('server_error', 'The login session could not be opened.', 500);
    }

    $nick = (string)($_SESSION[THREEDPL_SESSION_NICK] ?? '');
    if (!api_valid_nick($nick)) {
        api_fail('not_logged_in', 'You must log in before using this option.', 401);
    }
    return $nick;
}

function api_users_root(bool $create = false): string
{
    $root = __DIR__ . DIRECTORY_SEPARATOR . 'Users';
    if (!file_exists($root)) {
        if (!$create || !@mkdir($root, 0775, true)) {
            throw new RuntimeException('The user storage directory does not exist.');
        }
    }
    if (!is_dir($root) || is_link($root)) {
        throw new RuntimeException('The user storage path is not a safe directory.');
    }
    if ($create && !is_writable($root)) {
        // This succeeds when PHP owns the deployed directory but its mode was
        // made read-only by an uploader. It cannot override OS ownership.
        @chmod($root, 0775);
        clearstatcache(true, $root);
    }
    return $root;
}

function api_find_user_directory_case_insensitive(string $nick): ?string
{
    $root = api_users_root(true);
    $entries = scandir($root);
    if ($entries === false) {
        throw new RuntimeException('The user storage directory cannot be read.');
    }

    foreach ($entries as $entry) {
        if ($entry !== '.' && $entry !== '..' && strcasecmp($entry, $nick) === 0) {
            return $root . DIRECTORY_SEPARATOR . $entry;
        }
    }
    return null;
}

function api_personal_library_directory(string $nick, string $library, bool $create = false): string
{
    if (!api_valid_nick($nick)) {
        throw new InvalidArgumentException('Invalid nickname.');
    }
    if (!in_array($library, ['Objects', 'Programs', 'Maps'], true)) {
        throw new InvalidArgumentException('Invalid personal library.');
    }

    $root = api_users_root($create);
    $userDirectory = $root . DIRECTORY_SEPARATOR . $nick;
    $createdUserDirectory = false;
    if (!file_exists($userDirectory)) {
        if (!$create || !@mkdir($userDirectory, 0775)) {
            throw new RuntimeException('The user directory does not exist.');
        }
        $createdUserDirectory = true;
    }
    if (!is_dir($userDirectory) || is_link($userDirectory)) {
        throw new RuntimeException('The user path is not a safe directory.');
    }
    if ($create && !is_writable($userDirectory)) {
        @chmod($userDirectory, 0775);
        clearstatcache(true, $userDirectory);
    }

    $libraryDirectory = $userDirectory . DIRECTORY_SEPARATOR . $library;
    if (!file_exists($libraryDirectory)) {
        if (!$create || !@mkdir($libraryDirectory, 0775)) {
            if ($createdUserDirectory) {
                @rmdir($userDirectory);
            }
            throw new RuntimeException('The personal library directory does not exist.');
        }
    }
    if (!is_dir($libraryDirectory) || is_link($libraryDirectory)) {
        throw new RuntimeException('The personal library path is not a safe directory.');
    }
    if ($create && !is_writable($libraryDirectory)) {
        @chmod($libraryDirectory, 0775);
        clearstatcache(true, $libraryDirectory);
    }

    return $libraryDirectory;
}

function api_objects_directory(string $nick, bool $create = false): string
{
    return api_personal_library_directory($nick, 'Objects', $create);
}

function api_programs_directory(string $nick, bool $create = false): string
{
    return api_personal_library_directory($nick, 'Programs', $create);
}

function api_maps_directory(string $nick, bool $create = false): string
{
    return api_personal_library_directory($nick, 'Maps', $create);
}
