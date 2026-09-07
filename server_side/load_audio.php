<?php
declare(strict_types=1);

require_once __DIR__ . '/api_common.php';

api_require_method('GET');
$nick = api_require_user();
session_write_close();
api_require_matching_scope($_GET, $nick);

$lock = null;
try {
    $audioDirectory = api_audio_directory($nick, true);
    $lockPath = $audioDirectory . DIRECTORY_SEPARATOR . '.audio.lock';
    if (is_link($lockPath)) {
        throw new RuntimeException('The audio storage lock path is unsafe.');
    }
    $lock = fopen($lockPath, 'c');
    if ($lock === false || !flock($lock, LOCK_SH)) {
        throw new RuntimeException('The audio storage lock could not be acquired.');
    }

    $name = api_string($_GET, ['name']);
    if ($name === '') {
        $entries = scandir($audioDirectory);
        if ($entries === false) {
            throw new RuntimeException('The audio storage directory could not be read.');
        }

        $audio = [];
        foreach ($entries as $entry) {
            if (!api_valid_audio_name($entry)) {
                continue;
            }
            $path = $audioDirectory . DIRECTORY_SEPARATOR . $entry;
            try {
                $metadata = api_audio_metadata($path, $entry);
            } catch (Throwable $error) {
                error_log('3DPL skipped invalid personal audio ' . $entry . ': ' . $error->getMessage());
                continue;
            }
            $audio[] = [
                'name' => $entry,
                'size' => filesize($path),
                'modified' => filemtime($path),
                'mime' => $metadata['mime'],
            ];
        }
        usort($audio, static fn(array $left, array $right): int => strnatcasecmp($left['name'], $right['name']));

        flock($lock, LOCK_UN);
        fclose($lock);
        $lock = null;
        api_json([
            'ok' => true,
            'audio' => $audio,
        ]);
    }

    if (!api_valid_audio_name($name)) {
        flock($lock, LOCK_UN);
        fclose($lock);
        $lock = null;
        api_fail('invalid_audio_name', 'That audio filename is not valid.', 422);
    }

    $storedName = api_find_entry_case_insensitive($audioDirectory, $name);
    if ($storedName === null) {
        flock($lock, LOCK_UN);
        fclose($lock);
        $lock = null;
        api_fail('audio_not_found', 'That personal audio file was not found.', 404);
    }

    $path = $audioDirectory . DIRECTORY_SEPARATOR . $storedName;
    try {
        $metadata = api_audio_metadata($path, $storedName);
    } catch (Throwable $error) {
        throw new RuntimeException('The stored audio failed validation: ' . $error->getMessage());
    }
    $size = filesize($path);
    if ($size === false) {
        throw new RuntimeException('The stored audio size could not be read.');
    }

    header('Content-Type: ' . $metadata['mime']);
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
        error_log('3DPL audio load failed while streaming: ' . $path);
    }
    exit;
} catch (Throwable $error) {
    if (is_resource($lock)) {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
    error_log('3DPL audio load error: ' . $error->getMessage());
    api_fail('audio_load_failed', 'Your audio library could not be read.', 500);
}
