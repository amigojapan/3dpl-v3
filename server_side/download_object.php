<?php
declare(strict_types=1);

require_once __DIR__ . '/api_common.php';

api_require_method('GET');
$nick = api_require_user();
session_write_close();
api_require_matching_scope($_GET, $nick);

$lock = null;
try {
    $objectsDirectory = api_objects_directory($nick, true);
    $lockPath = $objectsDirectory . DIRECTORY_SEPARATOR . '.objects.lock';
    if (is_link($lockPath)) {
        throw new RuntimeException('The object storage lock path is unsafe.');
    }
    $lock = fopen($lockPath, 'c');
    if ($lock === false || !flock($lock, LOCK_SH)) {
        throw new RuntimeException('The object storage lock could not be acquired.');
    }

    $name = api_string($_GET, ['name']);
    if ($name === '') {
        $entries = scandir($objectsDirectory);
        if ($entries === false) {
            throw new RuntimeException('The object storage directory could not be read.');
        }

        $objects = [];
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

        flock($lock, LOCK_UN);
        fclose($lock);
        $lock = null;
        api_json([
            'ok' => true,
            'objects' => $objects,
        ]);
    }

    if (!api_valid_object_name($name)) {
        api_fail('invalid_object_name', 'That object filename is not valid.', 422);
    }

    $storedName = api_find_entry_case_insensitive($objectsDirectory, $name);
    if ($storedName === null) {
        api_fail('object_not_found', 'That object was not found.', 404);
    }
    $path = $objectsDirectory . DIRECTORY_SEPARATOR . $storedName;
    if (!is_file($path) || is_link($path)) {
        api_fail('object_not_found', 'That object was not found.', 404);
    }

    $size = filesize($path);
    if ($size === false) {
        throw new RuntimeException('The stored object size could not be read.');
    }

    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $storedName . '"');
    header('Content-Length: ' . (string)$size);
    header('Cache-Control: private, no-store');
    header('X-Content-Type-Options: nosniff');
    header('X-3DPL-Source: personal');
    $streamed = readfile($path);

    flock($lock, LOCK_UN);
    fclose($lock);
    $lock = null;
    if ($streamed === false) {
        error_log('3DPL object download failed while streaming: ' . $path);
    }
    exit;
} catch (Throwable $error) {
    if (is_resource($lock)) {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
    error_log('3DPL object download error: ' . $error->getMessage());
    api_fail('download_failed', 'Your object storage could not be read.', 500);
}
