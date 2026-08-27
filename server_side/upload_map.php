<?php
declare(strict_types=1);

require_once __DIR__ . '/api_common.php';

api_require_method('POST');
$nick = api_require_user();
session_write_close();

$upload = $_FILES['map'] ?? null;
if (!is_array($upload)) {
    api_fail('missing_map', 'Choose a JSON map file to upload.', 422);
}

$uploadError = (int)($upload['error'] ?? UPLOAD_ERR_NO_FILE);
if ($uploadError !== UPLOAD_ERR_OK) {
    $status = $uploadError === UPLOAD_ERR_INI_SIZE || $uploadError === UPLOAD_ERR_FORM_SIZE ? 413 : 422;
    api_fail('map_upload_failed', 'The map file could not be uploaded.', $status);
}

$size = (int)($upload['size'] ?? 0);
if ($size <= 0) {
    api_fail('empty_map', 'The map file is empty.', 422);
}
if ($size > THREEDPL_MAX_MAP_BYTES) {
    api_fail('map_too_large', 'Map files cannot be larger than 20 MB.', 413);
}

$name = api_normalize_json_name(trim((string)($_POST['map_name'] ?? $upload['name'] ?? '')));
if (!api_valid_object_name($name)) {
    api_fail(
        'invalid_map_name',
        'Map filenames must end in .json and use only letters, numbers, spaces, dots, underscores, or hyphens.',
        422
    );
}

try {
    if (api_shared_name_exists('Maps', $name)) {
        api_fail(
            'shared_name_exists',
            'A shared map already uses that filename. Choose a new name.',
            409
        );
    }
} catch (Throwable $error) {
    error_log('3DPL shared map collision check error: ' . $error->getMessage());
    api_fail('shared_library_unavailable', 'The shared map library could not be checked.', 500);
}

$temporaryUpload = (string)($upload['tmp_name'] ?? '');
if ($temporaryUpload === '' || !is_uploaded_file($temporaryUpload)) {
    api_fail('invalid_upload', 'The server did not receive a valid map upload.', 422);
}

$contents = file_get_contents($temporaryUpload);
if ($contents === false) {
    api_fail('invalid_map', 'The uploaded map could not be read.', 422);
}
try {
    $document = json_decode($contents, false, 512, JSON_THROW_ON_ERROR);
} catch (JsonException $error) {
    api_fail('invalid_map', 'The uploaded map is not valid JSON.', 422);
}

$entries = null;
if (is_array($document)) {
    $entries = $document;
} elseif ($document instanceof stdClass && isset($document->objects) && is_array($document->objects)) {
    $entries = $document->objects;
}
if ($entries === null) {
    api_fail('invalid_map', 'The uploaded map must contain an objects array.', 422);
}
if (count($entries) > 100000) {
    api_fail('invalid_map', 'The uploaded map contains too many entries.', 422);
}
foreach ($entries as $entry) {
    if (!$entry instanceof stdClass) {
        api_fail('invalid_map', 'Every map entry must be a JSON object.', 422);
    }
}
unset($contents, $document, $entries);

$overwriteValue = strtolower(trim((string)($_POST['overwrite'] ?? 'false')));
$overwrite = in_array($overwriteValue, ['1', 'true', 'yes', 'on'], true);
$lock = null;
$stagedPath = null;
$backupPath = null;
$destination = null;
$installed = false;

try {
    $mapsDirectory = api_maps_directory($nick, true);
    $lock = fopen($mapsDirectory . DIRECTORY_SEPARATOR . '.maps.lock', 'c');
    if ($lock === false || !flock($lock, LOCK_EX)) {
        throw new RuntimeException('The map storage lock could not be acquired.');
    }

    // Recheck while holding the personal write lock. Shared names are never
    // shadowed, even when the caller explicitly allows a personal overwrite.
    if (api_shared_name_exists('Maps', $name)) {
        api_fail(
            'shared_name_exists',
            'A shared map already uses that filename. Choose a new name.',
            409
        );
    }

    $existingName = api_find_entry_case_insensitive($mapsDirectory, $name);
    if ($existingName !== null && !$overwrite) {
        api_fail('map_exists', 'A personal map with that filename already exists.', 409);
    }
    if ($existingName !== null) {
        $existingPath = $mapsDirectory . DIRECTORY_SEPARATOR . $existingName;
        if (!is_file($existingPath) || is_link($existingPath)) {
            api_fail('map_storage_conflict', 'That map name conflicts with an unsafe storage entry.', 409);
        }
    }

    $destination = $mapsDirectory . DIRECTORY_SEPARATOR . ($existingName ?? $name);
    $operationId = bin2hex(random_bytes(12));
    $stagedPath = $mapsDirectory . DIRECTORY_SEPARATOR . '.upload-' . $operationId . '.map.tmp';
    if (!move_uploaded_file($temporaryUpload, $stagedPath)) {
        throw new RuntimeException('The map upload could not be staged.');
    }
    @chmod($stagedPath, 0640);

    if (file_exists($destination)) {
        $backupPath = $mapsDirectory . DIRECTORY_SEPARATOR . '.backup-' . $operationId . '.json';
        if (!rename($destination, $backupPath)) {
            throw new RuntimeException('The existing map could not be staged for replacement.');
        }
    }
    if (!rename($stagedPath, $destination)) {
        throw new RuntimeException('The map could not be saved.');
    }
    $stagedPath = null;
    $installed = true;

    if ($backupPath !== null) {
        @unlink($backupPath);
        $backupPath = null;
    }

    flock($lock, LOCK_UN);
    fclose($lock);
    $lock = null;

    api_json([
        'ok' => true,
        'name' => basename($destination),
        'size' => filesize($destination),
        'message' => 'Map uploaded successfully.',
    ], 201);
} catch (Throwable $error) {
    if ($installed && $destination !== null && is_file($destination)) {
        @unlink($destination);
    }
    if ($backupPath !== null && $destination !== null && is_file($backupPath)) {
        @rename($backupPath, $destination);
    }
    if ($stagedPath !== null && is_file($stagedPath)) {
        @unlink($stagedPath);
    }
    if (is_resource($lock)) {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
    error_log('3DPL map upload error: ' . $error->getMessage());
    api_fail('map_upload_failed', 'The map could not be saved.', 500);
}
