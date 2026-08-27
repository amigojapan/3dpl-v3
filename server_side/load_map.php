<?php
declare(strict_types=1);

require_once __DIR__ . '/api_common.php';

api_require_method('GET');
$nick = api_require_user();
session_write_close();

$lock = null;
try {
    $mapsDirectory = api_maps_directory($nick, true);
    $lock = fopen($mapsDirectory . DIRECTORY_SEPARATOR . '.maps.lock', 'c');
    if ($lock === false || !flock($lock, LOCK_SH)) {
        throw new RuntimeException('The map storage lock could not be acquired.');
    }

    $name = api_normalize_json_name(api_string($_GET, ['name']));
    if ($name === '') {
        $maps = [];
        $entries = scandir($mapsDirectory);
        if ($entries === false) {
            throw new RuntimeException('The map storage directory could not be read.');
        }

        foreach ($entries as $entry) {
            if (!api_valid_object_name($entry)) {
                continue;
            }
            $path = $mapsDirectory . DIRECTORY_SEPARATOR . $entry;
            if (!is_file($path) || is_link($path)) {
                continue;
            }
            $maps[] = [
                'name' => $entry,
                'size' => filesize($path),
                'modified' => filemtime($path),
            ];
        }
        usort($maps, static fn(array $left, array $right): int => strnatcasecmp($left['name'], $right['name']));

        flock($lock, LOCK_UN);
        fclose($lock);
        $lock = null;
        api_json([
            'ok' => true,
            'maps' => $maps,
        ]);
    }

    if (!api_valid_object_name($name)) {
        api_fail('invalid_map_name', 'That map filename is not valid.', 422);
    }

    $storedName = api_find_entry_case_insensitive($mapsDirectory, $name);
    if ($storedName === null) {
        api_fail('map_not_found', 'That personal map was not found.', 404);
    }
    $path = $mapsDirectory . DIRECTORY_SEPARATOR . $storedName;
    if (!is_file($path) || is_link($path)) {
        api_fail('map_not_found', 'That personal map was not found.', 404);
    }

    $size = filesize($path);
    if ($size === false) {
        throw new RuntimeException('The stored map size could not be read.');
    }

    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $storedName . '"');
    header('Content-Length: ' . (string)$size);
    header('Cache-Control: private, no-store');
    header('X-Content-Type-Options: nosniff');
    $streamed = readfile($path);

    flock($lock, LOCK_UN);
    fclose($lock);
    $lock = null;
    if ($streamed === false) {
        error_log('3DPL map load failed while streaming: ' . $path);
    }
    exit;
} catch (Throwable $error) {
    if (is_resource($lock)) {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
    error_log('3DPL map load error: ' . $error->getMessage());
    api_fail('map_load_failed', 'Your map storage could not be read.', 500);
}
