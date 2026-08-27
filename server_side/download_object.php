<?php
declare(strict_types=1);

require_once __DIR__ . '/api_common.php';

api_require_method('GET');
$nick = api_require_user();

try {
    $objectsDirectory = api_objects_directory($nick, true);
} catch (Throwable $error) {
    error_log('3DPL object download error: ' . $error->getMessage());
    api_fail('storage_unavailable', 'Your object storage is not available.', 500);
}

$name = trim((string)($_GET['name'] ?? ''));
if ($name === '') {
    $objects = [];
    $entries = scandir($objectsDirectory);
    if ($entries === false) {
        api_fail('storage_unavailable', 'Your object storage could not be read.', 500);
    }

    foreach ($entries as $entry) {
        if (!api_valid_object_name($entry)) {
            continue;
        }
        $path = $objectsDirectory . DIRECTORY_SEPARATOR . $entry;
        if (!is_file($path) || is_link($path)) {
            continue;
        }
        $objects[] = [
            'name' => $entry,
            'size' => filesize($path),
            'modified' => filemtime($path),
        ];
    }
    usort($objects, static fn(array $left, array $right): int => strnatcasecmp($left['name'], $right['name']));

    api_json([
        'ok' => true,
        'objects' => $objects,
    ]);
}

if (!api_valid_object_name($name)) {
    api_fail('invalid_object_name', 'That object filename is not valid.', 422);
}

$path = $objectsDirectory . DIRECTORY_SEPARATOR . $name;
if (!is_file($path) || is_link($path)) {
    api_fail('object_not_found', 'That object was not found.', 404);
}

$size = filesize($path);
if ($size === false) {
    api_fail('download_failed', 'The object could not be read.', 500);
}

session_write_close();
header('Content-Type: application/json; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $name . '"');
header('Content-Length: ' . (string)$size);
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');
if (readfile($path) === false) {
    error_log('3DPL object download failed while streaming: ' . $path);
}
exit;
