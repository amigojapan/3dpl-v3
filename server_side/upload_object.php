<?php
declare(strict_types=1);

require_once __DIR__ . '/api_common.php';

api_require_method('POST');
$nick = api_require_user();

$upload = $_FILES['object'] ?? $_FILES['file'] ?? null;
if (!is_array($upload)) {
    api_fail('missing_object', 'Choose a JSON object file to upload.', 422);
}

$uploadError = (int)($upload['error'] ?? UPLOAD_ERR_NO_FILE);
if ($uploadError !== UPLOAD_ERR_OK) {
    $status = $uploadError === UPLOAD_ERR_INI_SIZE || $uploadError === UPLOAD_ERR_FORM_SIZE ? 413 : 422;
    api_fail('upload_failed', 'The object file could not be uploaded.', $status);
}

$size = (int)($upload['size'] ?? 0);
if ($size <= 0) {
    api_fail('empty_object', 'The object file is empty.', 422);
}
if ($size > THREEDPL_MAX_OBJECT_BYTES) {
    api_fail('object_too_large', 'Object files cannot be larger than 20 MB.', 413);
}

$name = trim((string)($_POST['object_name'] ?? $upload['name'] ?? ''));
if (!api_valid_object_name($name)) {
    api_fail(
        'invalid_object_name',
        'Object filenames must end in .json and use only letters, numbers, spaces, dots, underscores, or hyphens.',
        422
    );
}

try {
    if (api_shared_name_exists('Objects', $name)) {
        api_fail(
            'shared_name_exists',
            'A shared object already uses that filename. Choose a new name.',
            409
        );
    }
} catch (Throwable $error) {
    error_log('3DPL shared object collision check error: ' . $error->getMessage());
    api_fail('shared_library_unavailable', 'The shared object library could not be checked.', 500);
}

$temporaryUpload = (string)($upload['tmp_name'] ?? '');
if ($temporaryUpload === '' || !is_uploaded_file($temporaryUpload)) {
    api_fail('invalid_upload', 'The server did not receive a valid uploaded file.', 422);
}

$contents = file_get_contents($temporaryUpload);
if ($contents === false) {
    api_fail('invalid_object', 'The uploaded object could not be read.', 422);
}
try {
    $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
} catch (JsonException $error) {
    api_fail('invalid_object', 'The uploaded object is not valid JSON.', 422);
}
if (!is_array($decoded) || !array_is_list($decoded)) {
    api_fail('invalid_object', 'The uploaded object must contain a JSON array.', 422);
}
if (count($decoded) > 250000) {
    api_fail('invalid_object', 'The uploaded object contains too many blocks.', 422);
}
foreach ($decoded as $block) {
    if (!is_array($block) || array_is_list($block)) {
        api_fail('invalid_object', 'Every object block must be a JSON object.', 422);
    }
    foreach (['x', 'y', 'z', 'r', 'g', 'b'] as $field) {
        $value = $block[$field] ?? null;
        if ((!is_int($value) && !is_float($value)) || !is_finite((float)$value)) {
            api_fail('invalid_object', 'Every object block must have numeric x, y, z, r, g, and b fields.', 422);
        }
    }
}
unset($contents, $decoded);

$overwriteValue = strtolower(trim((string)($_POST['overwrite'] ?? 'false')));
$overwrite = in_array($overwriteValue, ['1', 'true', 'yes', 'on'], true);
$lock = null;
$stagedPath = null;
$backupPath = null;
$destination = null;
$installed = false;

try {
    $objectsDirectory = api_objects_directory($nick, true);
    $lock = fopen($objectsDirectory . DIRECTORY_SEPARATOR . '.objects.lock', 'c');
    if ($lock === false || !flock($lock, LOCK_EX)) {
        throw new RuntimeException('The object storage lock could not be acquired.');
    }

    // Shared names can never be shadowed, including during an explicit
    // personal overwrite. Recheck after taking the personal write lock.
    if (api_shared_name_exists('Objects', $name)) {
        api_fail(
            'shared_name_exists',
            'A shared object already uses that filename. Choose a new name.',
            409
        );
    }

    $existingName = api_find_entry_case_insensitive($objectsDirectory, $name);
    if ($existingName !== null && !$overwrite) {
        api_fail('object_exists', 'An object with that filename already exists.', 409);
    }
    if ($existingName !== null) {
        $existingPath = $objectsDirectory . DIRECTORY_SEPARATOR . $existingName;
        if (!is_file($existingPath) || is_link($existingPath)) {
            api_fail('object_storage_conflict', 'That object name conflicts with an unsafe storage entry.', 409);
        }
    }

    $destination = $objectsDirectory . DIRECTORY_SEPARATOR . ($existingName ?? $name);
    $operationId = bin2hex(random_bytes(12));
    $stagedPath = $objectsDirectory . DIRECTORY_SEPARATOR . '.upload-' . $operationId . '.tmp';
    if (!move_uploaded_file($temporaryUpload, $stagedPath)) {
        throw new RuntimeException('The uploaded object could not be moved into storage.');
    }
    @chmod($stagedPath, 0640);

    if (file_exists($destination)) {
        $backupPath = $objectsDirectory . DIRECTORY_SEPARATOR . '.backup-' . $operationId . '.json';
        if (!rename($destination, $backupPath)) {
            throw new RuntimeException('The existing object could not be staged for replacement.');
        }
    }
    if (!rename($stagedPath, $destination)) {
        throw new RuntimeException('The uploaded object could not be saved.');
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
        'message' => 'Object uploaded successfully.',
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
    error_log('3DPL object upload error: ' . $error->getMessage());
    api_fail('upload_failed', 'The object could not be saved.', 500);
}
