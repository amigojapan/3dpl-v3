<?php
declare(strict_types=1);

require_once __DIR__ . '/api_common.php';

api_require_method('POST');
$nick = api_require_user();
session_write_close();
api_require_matching_scope($_POST, $nick);
$userLayout = api_require_user_layout($nick);
$texturesDirectory = $userLayout['Textures'];

$upload = $_FILES['texture'] ?? null;
if (!is_array($upload)) {
    api_fail('missing_texture', 'Choose a texture image to upload.', 422);
}

$uploadError = (int)($upload['error'] ?? UPLOAD_ERR_NO_FILE);
if ($uploadError !== UPLOAD_ERR_OK) {
    $status = in_array($uploadError, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true) ? 413 : 422;
    api_fail('texture_upload_failed', 'The texture file could not be uploaded.', $status);
}

$name = trim((string)($_POST['texture_name'] ?? $upload['name'] ?? ''));
if (!api_valid_texture_name($name)) {
    api_fail(
        'invalid_texture_name',
        'Texture filenames may use letters, numbers, spaces, dots, underscores, or hyphens and must end in png, jpg, jpeg, webp, or gif.',
        422
    );
}

$temporaryUpload = (string)($upload['tmp_name'] ?? '');
if ($temporaryUpload === '' || !is_uploaded_file($temporaryUpload)) {
    api_fail('invalid_upload', 'The server did not receive a valid texture upload.', 422);
}
$size = filesize($temporaryUpload);
if ($size === false || $size <= 0) {
    api_fail('empty_texture', 'The texture file is empty.', 422);
}
if ($size > THREEDPL_MAX_TEXTURE_BYTES) {
    api_fail('texture_too_large', 'Texture files cannot be larger than 20 MB.', 413);
}

try {
    $metadata = api_texture_metadata($temporaryUpload, $name);
} catch (Throwable $error) {
    error_log('3DPL texture validation error: ' . $error->getMessage());
    api_fail('invalid_texture', 'The uploaded file is not a supported image or does not match its extension.', 422);
}

$overwriteValue = strtolower(trim((string)($_POST['overwrite'] ?? 'false')));
$overwrite = in_array($overwriteValue, ['1', 'true', 'yes', 'on'], true);
$lock = null;
$stagedPath = null;
$backupPath = null;
$destination = null;
$installed = false;

try {
    $lockPath = $texturesDirectory . DIRECTORY_SEPARATOR . '.textures.lock';
    if (is_link($lockPath)) {
        throw new RuntimeException('The texture storage lock path is unsafe.');
    }
    $lock = fopen($lockPath, 'c');
    if ($lock === false || !flock($lock, LOCK_EX)) {
        throw new RuntimeException('The texture storage lock could not be acquired.');
    }

    $existingName = api_find_entry_case_insensitive($texturesDirectory, $name);
    if ($existingName !== null && !$overwrite) {
        flock($lock, LOCK_UN);
        fclose($lock);
        $lock = null;
        api_fail('texture_exists', 'A personal texture with that filename already exists.', 409);
    }
    if ($existingName !== null) {
        $existingPath = $texturesDirectory . DIRECTORY_SEPARATOR . $existingName;
        if (!is_file($existingPath) || is_link($existingPath)) {
            flock($lock, LOCK_UN);
            fclose($lock);
            $lock = null;
            api_fail('texture_storage_conflict', 'That texture name conflicts with an unsafe storage entry.', 409);
        }
    }

    $destination = $texturesDirectory . DIRECTORY_SEPARATOR . ($existingName ?? $name);
    $operationId = bin2hex(random_bytes(12));
    $stagedPath = $texturesDirectory . DIRECTORY_SEPARATOR . '.upload-' . $operationId . '.texture.tmp';
    if (!move_uploaded_file($temporaryUpload, $stagedPath)) {
        throw new RuntimeException('The texture upload could not be staged.');
    }
    @chmod($stagedPath, 0640);

    if (file_exists($destination)) {
        $backupPath = $texturesDirectory . DIRECTORY_SEPARATOR . '.backup-' . $operationId . '.texture';
        if (!rename($destination, $backupPath)) {
            throw new RuntimeException('The existing texture could not be staged for replacement.');
        }
    }
    if (!rename($stagedPath, $destination)) {
        throw new RuntimeException('The texture file could not be installed.');
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
        'size' => $size,
        'mime' => $metadata['mime'],
        'width' => $metadata['width'],
        'height' => $metadata['height'],
        'message' => 'Texture uploaded successfully.',
    ], 201);
} catch (Throwable $error) {
    if ($installed && $destination !== null && is_file($destination) && !is_link($destination)) {
        @unlink($destination);
    }
    if ($backupPath !== null && $destination !== null && is_file($backupPath) && !is_link($backupPath)) {
        @rename($backupPath, $destination);
    }
    if ($stagedPath !== null && is_file($stagedPath) && !is_link($stagedPath)) {
        @unlink($stagedPath);
    }
    if (is_resource($lock)) {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
    error_log('3DPL texture upload error: ' . $error->getMessage());
    api_fail('texture_upload_failed', 'The texture could not be saved.', 500);
}
