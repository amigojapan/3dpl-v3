<?php
declare(strict_types=1);

const THREEDPL_SESSION_NICK = '3dpl_user_nick';
const THREEDPL_MAX_OBJECT_BYTES = 20 * 1024 * 1024;
const THREEDPL_MAX_MAP_BYTES = 20 * 1024 * 1024;
const THREEDPL_MAX_PROGRAM_PART_BYTES = 2 * 1024 * 1024;
const THREEDPL_MAX_AUDIO_BYTES = 50 * 1024 * 1024;
const THREEDPL_MAX_TEXTURE_BYTES = 20 * 1024 * 1024;
const THREEDPL_MAX_TEXTURE_DIMENSION = 8192;
const THREEDPL_MAX_TEXTURE_PIXELS = 33554432;
const THREEDPL_PERSONAL_LIBRARIES = ['Objects', 'Maps', 'Programs', 'Textures', 'Audio'];

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

function api_valid_audio_name(string $name): bool
{
    if (strlen($name) > 128 || $name !== basename($name)) {
        return false;
    }

    return preg_match(
        '/^[A-Za-z0-9][A-Za-z0-9 ._-]*\.(?:mp3|wav|ogg|oga|opus|m4a|aac|flac|webm)$/iD',
        $name
    ) === 1;
}

function api_valid_texture_name(string $name): bool
{
    if (strlen($name) > 128 || $name !== basename($name)) {
        return false;
    }

    return preg_match('/^[A-Za-z0-9][A-Za-z0-9 ._-]*\.(?:png|jpe?g|webp|gif)$/iD', $name) === 1;
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

/** @return array{mime: string} */
function api_audio_metadata(string $path, string $name): array
{
    if (!api_valid_audio_name($name) || !is_file($path) || is_link($path)) {
        throw new RuntimeException('The audio file path is not safe.');
    }

    $size = filesize($path);
    if ($size === false || $size <= 0 || $size > THREEDPL_MAX_AUDIO_BYTES) {
        throw new RuntimeException('The audio file size is not allowed.');
    }

    if (!class_exists('finfo')) {
        throw new RuntimeException('The PHP Fileinfo extension is not installed.');
    }
    $detector = new finfo(FILEINFO_MIME_TYPE);
    $detectedMime = $detector->file($path);
    if (!is_string($detectedMime) || $detectedMime === '') {
        throw new RuntimeException('The audio MIME type could not be detected.');
    }
    $detectedMime = strtolower(trim(explode(';', $detectedMime, 2)[0]));

    $extension = strtolower((string)pathinfo($name, PATHINFO_EXTENSION));
    $allowedMimes = [
        'mp3' => ['audio/mpeg', 'audio/mp3'],
        'wav' => ['audio/wav', 'audio/x-wav', 'audio/vnd.wave'],
        'ogg' => ['audio/ogg', 'application/ogg'],
        'oga' => ['audio/ogg', 'application/ogg'],
        'opus' => ['audio/ogg', 'audio/opus', 'application/ogg'],
        'm4a' => ['audio/mp4', 'audio/x-m4a', 'application/mp4', 'video/mp4'],
        'aac' => ['audio/aac', 'audio/x-aac', 'audio/mpeg'],
        'flac' => ['audio/flac', 'audio/x-flac'],
        'webm' => ['audio/webm', 'video/webm'],
    ];
    if (!isset($allowedMimes[$extension]) || !in_array($detectedMime, $allowedMimes[$extension], true)) {
        throw new RuntimeException('The audio content does not match its filename extension.');
    }

    // Use a deterministic audio Content-Type rather than reflecting a detector
    // value such as video/mp4 for an otherwise valid M4A container.
    $responseMimes = [
        'mp3' => 'audio/mpeg',
        'wav' => 'audio/wav',
        'ogg' => 'audio/ogg',
        'oga' => 'audio/ogg',
        'opus' => 'audio/ogg',
        'm4a' => 'audio/mp4',
        'aac' => 'audio/aac',
        'flac' => 'audio/flac',
        'webm' => 'audio/webm',
    ];
    return ['mime' => $responseMimes[$extension]];
}

/** @return array{mime: string, width: int, height: int} */
function api_texture_metadata(string $path, string $name): array
{
    if (!api_valid_texture_name($name) || !is_file($path) || is_link($path)) {
        throw new RuntimeException('The texture file path is not safe.');
    }

    $size = filesize($path);
    if ($size === false || $size <= 0 || $size > THREEDPL_MAX_TEXTURE_BYTES) {
        throw new RuntimeException('The texture file size is not allowed.');
    }

    $image = @getimagesize($path);
    if (!is_array($image) || !isset($image[0], $image[1], $image[2])) {
        throw new RuntimeException('The texture is not a recognized image.');
    }
    $width = (int)$image[0];
    $height = (int)$image[1];
    if (
        $width <= 0 || $height <= 0
        || $width > THREEDPL_MAX_TEXTURE_DIMENSION
        || $height > THREEDPL_MAX_TEXTURE_DIMENSION
        || $width * $height > THREEDPL_MAX_TEXTURE_PIXELS
    ) {
        throw new RuntimeException('The texture dimensions are not allowed.');
    }

    $extension = strtolower((string)pathinfo($name, PATHINFO_EXTENSION));
    $expectedTypes = [
        'png' => IMAGETYPE_PNG,
        'jpg' => IMAGETYPE_JPEG,
        'jpeg' => IMAGETYPE_JPEG,
        'webp' => IMAGETYPE_WEBP,
        'gif' => IMAGETYPE_GIF,
    ];
    if (!isset($expectedTypes[$extension]) || (int)$image[2] !== $expectedTypes[$extension]) {
        throw new RuntimeException('The texture content does not match its filename extension.');
    }

    $expectedMimes = [
        IMAGETYPE_PNG => 'image/png',
        IMAGETYPE_JPEG => 'image/jpeg',
        IMAGETYPE_WEBP => 'image/webp',
        IMAGETYPE_GIF => 'image/gif',
    ];
    $mime = $expectedMimes[(int)$image[2]] ?? '';
    if ($mime === '' || !class_exists('finfo')) {
        throw new RuntimeException('The texture MIME type could not be verified.');
    }
    $detector = new finfo(FILEINFO_MIME_TYPE);
    $detectedMime = $detector->file($path);
    if (!is_string($detectedMime) || strtolower(trim(explode(';', $detectedMime, 2)[0])) !== $mime) {
        throw new RuntimeException('The texture MIME type does not match its image data.');
    }

    return [
        'mime' => $mime,
        'width' => $width,
        'height' => $height,
    ];
}

function api_shared_library_directory(string $library): string
{
    if (!in_array($library, ['Objects', 'Programs', 'Maps', 'Textures'], true)) {
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

function api_require_matching_scope(array $query, string $nick): void
{
    $scope = api_string($query, ['scope']);
    if ($scope !== '' && strcasecmp($scope, $nick) !== 0) {
        api_fail(
            'session_scope_mismatch',
            'The requested user storage does not match the logged-in session.',
            409
        );
    }
}

function api_users_root(bool $create = false): string
{
    $root = __DIR__ . DIRECTORY_SEPARATOR . 'Users';
    $createdRoot = false;
    if (!file_exists($root) && !is_link($root)) {
        if (!$create) {
            throw new RuntimeException('The user storage directory does not exist.');
        }
        // mkdir() may report false when another request creates the directory
        // between this check and the call, so validate the resulting path below.
        $createdRoot = @mkdir($root, 0775, true);
        clearstatcache(true, $root);
    }
    if (is_link($root) || (file_exists($root) && !is_dir($root))) {
        throw new RuntimeException('The user storage path is not a safe directory.');
    }
    if (!is_dir($root)) {
        throw new RuntimeException('The user storage directory could not be created.');
    }
    // An existing Users root need not be writable when the account directory
    // already exists. The account-directory creation below performs the
    // stronger check only when it is actually needed.
    if ($createdRoot && !is_writable($root)) {
        // This succeeds when PHP owns the deployed directory but its mode was
        // made read-only by an uploader. It cannot override OS ownership.
        @chmod($root, 0775);
        clearstatcache(true, $root);
    }
    if ($createdRoot && !is_writable($root)) {
        throw new RuntimeException('The user storage directory is not writable by PHP.');
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
    if (!in_array($library, THREEDPL_PERSONAL_LIBRARIES, true)) {
        throw new InvalidArgumentException('Invalid personal library.');
    }

    $root = api_users_root($create);
    $userDirectory = $root . DIRECTORY_SEPARATOR . $nick;
    $createdUserDirectory = false;
    if (!file_exists($userDirectory) && !is_link($userDirectory)) {
        if (!$create) {
            throw new RuntimeException('The user directory does not exist.');
        }
        $createdUserDirectory = @mkdir($userDirectory, 0775);
        clearstatcache(true, $userDirectory);
    }
    if (is_link($userDirectory) || (file_exists($userDirectory) && !is_dir($userDirectory))) {
        throw new RuntimeException('The user path is not a safe directory.');
    }
    if (!is_dir($userDirectory)) {
        throw new RuntimeException('The user directory could not be created.');
    }
    $libraryDirectory = $userDirectory . DIRECTORY_SEPARATOR . $library;
    $createdLibraryDirectory = false;
    if (!file_exists($libraryDirectory) && !is_link($libraryDirectory)) {
        if (!$create) {
            throw new RuntimeException('The personal ' . $library . ' directory does not exist.');
        }
        if (!is_writable($userDirectory)) {
            @chmod($userDirectory, 0775);
            clearstatcache(true, $userDirectory);
        }
        if (!is_writable($userDirectory)) {
            throw new RuntimeException('The user directory is not writable by PHP.');
        }
        $createdLibraryDirectory = @mkdir($libraryDirectory, 0775);
        clearstatcache(true, $libraryDirectory);
        if (is_link($libraryDirectory) || (file_exists($libraryDirectory) && !is_dir($libraryDirectory))) {
            if ($createdUserDirectory) {
                @rmdir($userDirectory);
            }
            throw new RuntimeException('The personal ' . $library . ' path is not a safe directory.');
        }
        if (!is_dir($libraryDirectory)) {
            if ($createdUserDirectory) {
                @rmdir($userDirectory);
            }
            throw new RuntimeException('The personal ' . $library . ' directory could not be created.');
        }
    }
    if (!is_dir($libraryDirectory) || is_link($libraryDirectory)) {
        throw new RuntimeException('The personal ' . $library . ' path is not a safe directory.');
    }
    if ($create && !is_writable($libraryDirectory)) {
        @chmod($libraryDirectory, 0775);
        clearstatcache(true, $libraryDirectory);
    }
    if ($create && !is_writable($libraryDirectory)) {
        if ($createdLibraryDirectory) {
            @rmdir($libraryDirectory);
        }
        if ($createdUserDirectory) {
            @rmdir($userDirectory);
        }
        throw new RuntimeException('The personal ' . $library . ' directory is not writable by PHP.');
    }

    return $libraryDirectory;
}

/**
 * Ensure that an account has its complete private storage layout.
 *
 * @return array{Objects: string, Maps: string, Programs: string, Textures: string, Audio: string}
 */
function api_ensure_user_layout(string $nick): array
{
    $directories = [];
    foreach (THREEDPL_PERSONAL_LIBRARIES as $library) {
        $directories[$library] = api_personal_library_directory($nick, $library, true);
    }
    return $directories;
}

function api_user_layout_error_message(string $nick): string
{
    return 'Your personal storage folders could not be created. The server administrator must grant PHP write and directory access to server_side/Users/' . $nick . '.';
}

/**
 * Ensure personal storage for an authenticated API request and emit a stable
 * JSON error instead of allowing a filesystem exception to become an HTML 500.
 *
 * @return array{Objects: string, Maps: string, Programs: string, Textures: string, Audio: string}
 */
function api_require_user_layout(string $nick): array
{
    try {
        return api_ensure_user_layout($nick);
    } catch (Throwable $error) {
        error_log('3DPL personal storage setup error for ' . $nick . ': ' . $error->getMessage());
        api_fail(
            'personal_storage_unavailable',
            api_user_layout_error_message($nick),
            503
        );
    }
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

function api_textures_directory(string $nick, bool $create = false): string
{
    return api_personal_library_directory($nick, 'Textures', $create);
}

function api_audio_directory(string $nick, bool $create = false): string
{
    return api_personal_library_directory($nick, 'Audio', $create);
}
