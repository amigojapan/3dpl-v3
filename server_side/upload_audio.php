<?php
declare(strict_types=1);

require_once __DIR__ . '/api_common.php';

api_require_method('POST');
$nick = api_require_user();
session_write_close();
api_require_matching_scope($_POST, $nick);
$userLayout = api_require_user_layout($nick);
$audioDirectory = $userLayout['Audio'];

$upload = $_FILES['audio'] ?? null;
if (!is_array($upload)) {
    api_fail('missing_audio', 'Choose an audio file to upload.', 422);
}

$uploadError = (int)($upload['error'] ?? UPLOAD_ERR_NO_FILE);
if ($uploadError !== UPLOAD_ERR_OK) {
    $status = in_array($uploadError, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true) ? 413 : 422;
    api_fail('audio_upload_failed', 'The audio file could not be uploaded.', $status);
}

$name = trim((string)($_POST['audio_name'] ?? $upload['name'] ?? ''));
if (!api_valid_audio_name($name)) {
    api_fail(
        'invalid_audio_name',
        'Audio filenames may use letters, numbers, spaces, dots, underscores, or hyphens and must end in mp3, wav, ogg, oga, opus, m4a, aac, flac, or webm.',
        422
    );
}

$temporaryUpload = (string)($upload['tmp_name'] ?? '');
if ($temporaryUpload === '' || !is_uploaded_file($temporaryUpload)) {
    api_fail('invalid_upload', 'The server did not receive a valid audio upload.', 422);
}
$size = filesize($temporaryUpload);
if ($size === false || $size <= 0) {
    api_fail('empty_audio', 'The audio file is empty.', 422);
}
if ($size > THREEDPL_MAX_AUDIO_BYTES) {
    api_fail('audio_too_large', 'Audio files cannot be larger than 50 MB.', 413);
}

try {
    $metadata = api_audio_metadata($temporaryUpload, $name);
} catch (Throwable $error) {
    error_log('3DPL audio validation error: ' . $error->getMessage());
    api_fail('invalid_audio', 'The uploaded file is not a supported audio file or does not match its extension.', 422);
}

$lock = null;
$stagedPath = null;
$destination = null;

try {
    $lockPath = $audioDirectory . DIRECTORY_SEPARATOR . '.audio.lock';
    if (is_link($lockPath)) {
        throw new RuntimeException('The audio storage lock path is unsafe.');
    }
    $lock = fopen($lockPath, 'c');
    if ($lock === false || !flock($lock, LOCK_EX)) {
        throw new RuntimeException('The audio storage lock could not be acquired.');
    }

    $existingName = api_find_entry_case_insensitive($audioDirectory, $name);
    if ($existingName !== null) {
        flock($lock, LOCK_UN);
        fclose($lock);
        $lock = null;
        api_fail('audio_exists', 'A personal audio file with that name already exists. Choose a new name.', 409);
    }

    $operationId = bin2hex(random_bytes(12));
    $stagedPath = $audioDirectory . DIRECTORY_SEPARATOR . '.upload-' . $operationId . '.audio.tmp';
    if (!move_uploaded_file($temporaryUpload, $stagedPath)) {
        throw new RuntimeException('The audio upload could not be staged.');
    }
    @chmod($stagedPath, 0640);

    $destination = $audioDirectory . DIRECTORY_SEPARATOR . $name;
    // A hard-link install is atomic and, unlike rename(), cannot replace a
    // destination created outside this endpoint after the collision check.
    if (!@link($stagedPath, $destination)) {
        if (api_find_entry_case_insensitive($audioDirectory, $name) !== null) {
            @unlink($stagedPath);
            $stagedPath = null;
            flock($lock, LOCK_UN);
            fclose($lock);
            $lock = null;
            api_fail('audio_exists', 'A personal audio file with that name already exists. Choose a new name.', 409);
        }
        throw new RuntimeException('The audio file could not be installed.');
    }
    @unlink($stagedPath);
    $stagedPath = null;

    flock($lock, LOCK_UN);
    fclose($lock);
    $lock = null;

    api_json([
        'ok' => true,
        'name' => $name,
        'size' => $size,
        'mime' => $metadata['mime'],
        'message' => 'Audio uploaded to your personal library successfully.',
    ], 201);
} catch (Throwable $error) {
    if ($stagedPath !== null && is_file($stagedPath) && !is_link($stagedPath)) {
        @unlink($stagedPath);
    }
    if (is_resource($lock)) {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
    error_log('3DPL audio upload error: ' . $error->getMessage());
    api_fail('audio_upload_failed', 'The audio file could not be saved.', 500);
}
