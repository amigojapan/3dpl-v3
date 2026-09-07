<?php
declare(strict_types=1);

require_once __DIR__ . '/api_common.php';

api_require_method('GET');
$nick = api_require_user();
session_write_close();

api_require_matching_scope($_GET, $nick);

/** @param array{mime: string, width: int, height: int} $metadata */
function stream_texture_file(string $path, string $name, array $metadata, string $source): never
{
    $size = filesize($path);
    if ($size === false) {
        throw new RuntimeException('The stored texture size could not be read.');
    }

    header('Content-Type: ' . $metadata['mime']);
    header('Content-Disposition: inline; filename="' . $name . '"');
    header('Content-Length: ' . (string)$size);
    header('Cache-Control: private, no-store');
    header('X-Content-Type-Options: nosniff');
    header('X-3DPL-Source: ' . $source);
    if (readfile($path) === false) {
        error_log('3DPL texture load failed while streaming: ' . $path);
    }
    exit;
}

$personalLock = null;
try {
    $texturesDirectory = api_textures_directory($nick, true);
    $lockPath = $texturesDirectory . DIRECTORY_SEPARATOR . '.textures.lock';
    if (is_link($lockPath)) {
        throw new RuntimeException('The texture storage lock path is unsafe.');
    }
    $personalLock = fopen($lockPath, 'c');
    if ($personalLock === false || !flock($personalLock, LOCK_SH)) {
        throw new RuntimeException('The texture storage lock could not be acquired.');
    }

    $name = api_string($_GET, ['name']);
    if ($name === '') {
        $entries = scandir($texturesDirectory);
        if ($entries === false) {
            throw new RuntimeException('The texture storage directory could not be read.');
        }

        $textures = [];
        foreach ($entries as $entry) {
            if (!api_valid_texture_name($entry)) {
                continue;
            }
            $path = $texturesDirectory . DIRECTORY_SEPARATOR . $entry;
            try {
                $metadata = api_texture_metadata($path, $entry);
            } catch (Throwable $error) {
                error_log('3DPL skipped invalid personal texture ' . $entry . ': ' . $error->getMessage());
                continue;
            }
            $textures[] = [
                'name' => $entry,
                'size' => filesize($path),
                'modified' => filemtime($path),
                'mime' => $metadata['mime'],
                'width' => $metadata['width'],
                'height' => $metadata['height'],
            ];
        }
        usort($textures, static fn(array $left, array $right): int => strnatcasecmp($left['name'], $right['name']));

        flock($personalLock, LOCK_UN);
        fclose($personalLock);
        $personalLock = null;
        api_json([
            'ok' => true,
            'textures' => $textures,
        ]);
    }

    if (!api_valid_texture_name($name)) {
        flock($personalLock, LOCK_UN);
        fclose($personalLock);
        $personalLock = null;
        api_fail('invalid_texture_name', 'That texture filename is not valid.', 422);
    }

    $storedName = api_find_entry_case_insensitive($texturesDirectory, $name);
    if ($storedName !== null) {
        $path = $texturesDirectory . DIRECTORY_SEPARATOR . $storedName;
        $metadata = api_texture_metadata($path, $storedName);

        // Keep the personal lock until the response body has been read so an
        // overwrite cannot replace the file mid-stream.
        $size = filesize($path);
        if ($size === false) {
            throw new RuntimeException('The stored texture size could not be read.');
        }
        header('Content-Type: ' . $metadata['mime']);
        header('Content-Disposition: inline; filename="' . $storedName . '"');
        header('Content-Length: ' . (string)$size);
        header('Cache-Control: private, no-store');
        header('X-Content-Type-Options: nosniff');
        header('X-3DPL-Source: personal');
        $streamed = readfile($path);

        flock($personalLock, LOCK_UN);
        fclose($personalLock);
        $personalLock = null;
        if ($streamed === false) {
            error_log('3DPL personal texture load failed while streaming: ' . $path);
        }
        exit;
    }

    flock($personalLock, LOCK_UN);
    fclose($personalLock);
    $personalLock = null;

    // Named requests fall back to the built-in shared texture library only
    // after the authenticated user's library has been checked.
    $sharedDirectory = api_shared_library_directory('Textures');
    $sharedName = api_find_entry_case_insensitive($sharedDirectory, $name);
    if ($sharedName === null) {
        api_fail('texture_not_found', 'That personal or shared texture was not found.', 404);
    }
    $sharedPath = $sharedDirectory . DIRECTORY_SEPARATOR . $sharedName;
    $sharedMetadata = api_texture_metadata($sharedPath, $sharedName);
    stream_texture_file($sharedPath, $sharedName, $sharedMetadata, 'shared');
} catch (Throwable $error) {
    if (is_resource($personalLock)) {
        flock($personalLock, LOCK_UN);
        fclose($personalLock);
    }
    error_log('3DPL texture load error: ' . $error->getMessage());
    api_fail('texture_load_failed', 'Your texture storage could not be read.', 500);
}
