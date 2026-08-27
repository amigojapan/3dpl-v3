<?php
declare(strict_types=1);

require_once __DIR__ . '/api_common.php';

api_require_method('POST');
$nick = api_require_user();
session_write_close();

/** @return array{name: string, tmp_name: string, size: int} */
function program_upload_part(string $field, string $extension): array
{
    $upload = $_FILES[$field] ?? null;
    if (!is_array($upload)) {
        api_fail('missing_program_file', 'Choose both declarations and update files.', 422);
    }

    $error = (int)($upload['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($error !== UPLOAD_ERR_OK) {
        $status = $error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE ? 413 : 422;
        api_fail('program_upload_failed', 'A program file could not be uploaded.', $status);
    }

    $size = (int)($upload['size'] ?? -1);
    if ($size < 0 || $size > THREEDPL_MAX_PROGRAM_PART_BYTES) {
        api_fail('program_too_large', 'Each program file must be 2 MB or smaller.', 413);
    }

    $name = trim((string)($upload['name'] ?? ''));
    if (!preg_match('/\.' . preg_quote($extension, '/') . '$/iD', $name)) {
        api_fail('invalid_program_file', 'The program files must use .declarations and .update extensions.', 422);
    }

    $temporaryPath = (string)($upload['tmp_name'] ?? '');
    if ($temporaryPath === '' || !is_uploaded_file($temporaryPath)) {
        api_fail('invalid_upload', 'The server did not receive a valid program upload.', 422);
    }

    $contents = file_get_contents($temporaryPath);
    if ($contents === false || str_contains($contents, "\0") || preg_match('//u', $contents) !== 1) {
        api_fail('invalid_program_file', 'Program files must contain valid UTF-8 text.', 422);
    }
    unset($contents);

    return [
        'name' => $name,
        'tmp_name' => $temporaryPath,
        'size' => $size,
    ];
}

$declarationsUpload = program_upload_part('declarations', 'declarations');
$updateUpload = program_upload_part('update', 'update');

$declarationsBase = api_normalize_program_name($declarationsUpload['name']);
$updateBase = api_normalize_program_name($updateUpload['name']);
$programName = api_normalize_program_name((string)($_POST['program_name'] ?? ''));
if ($programName === '') {
    if (strcasecmp($declarationsBase, $updateBase) !== 0) {
        api_fail('program_name_mismatch', 'The declarations and update filenames must have the same program name.', 422);
    }
    $programName = $declarationsBase;
}
if (!api_valid_program_name($programName)) {
    api_fail(
        'invalid_program_name',
        'Program names must use only letters, numbers, spaces, dots, underscores, or hyphens.',
        422
    );
}

$overwriteValue = strtolower(trim((string)($_POST['overwrite'] ?? 'false')));
$overwrite = in_array($overwriteValue, ['1', 'true', 'yes', 'on'], true);
$lock = null;
$stagedDeclarations = null;
$stagedUpdate = null;
$backupDeclarations = null;
$backupUpdate = null;
$installedDeclarations = false;
$installedUpdate = false;
$declarationsDestination = null;
$updateDestination = null;

try {
    $programsDirectory = api_programs_directory($nick, true);
    $lock = fopen($programsDirectory . DIRECTORY_SEPARATOR . '.programs.lock', 'c');
    if ($lock === false || !flock($lock, LOCK_EX)) {
        throw new RuntimeException('The program storage lock could not be acquired.');
    }

    $requestedDeclarations = $programName . '.declarations';
    $requestedUpdate = $programName . '.update';
    $existingDeclarations = api_find_entry_case_insensitive($programsDirectory, $requestedDeclarations);
    $existingUpdate = api_find_entry_case_insensitive($programsDirectory, $requestedUpdate);
    if (($existingDeclarations !== null || $existingUpdate !== null) && !$overwrite) {
        api_fail('program_exists', 'A personal program with that name already exists.', 409);
    }

    foreach ([$existingDeclarations, $existingUpdate] as $existingName) {
        if ($existingName === null) {
            continue;
        }
        $existingPath = $programsDirectory . DIRECTORY_SEPARATOR . $existingName;
        if (!is_file($existingPath) || is_link($existingPath)) {
            api_fail('program_storage_conflict', 'That program name conflicts with an unsafe storage entry.', 409);
        }
    }

    $declarationsDestination = $programsDirectory . DIRECTORY_SEPARATOR
        . ($existingDeclarations ?? $requestedDeclarations);
    $updateDestination = $programsDirectory . DIRECTORY_SEPARATOR
        . ($existingUpdate ?? $requestedUpdate);
    $operationId = bin2hex(random_bytes(12));
    $stagedDeclarations = $programsDirectory . DIRECTORY_SEPARATOR . '.upload-' . $operationId . '.declarations.tmp';
    $stagedUpdate = $programsDirectory . DIRECTORY_SEPARATOR . '.upload-' . $operationId . '.update.tmp';

    if (!move_uploaded_file($declarationsUpload['tmp_name'], $stagedDeclarations)) {
        throw new RuntimeException('The declarations upload could not be staged.');
    }
    if (!move_uploaded_file($updateUpload['tmp_name'], $stagedUpdate)) {
        throw new RuntimeException('The update upload could not be staged.');
    }
    @chmod($stagedDeclarations, 0640);
    @chmod($stagedUpdate, 0640);

    if (file_exists($declarationsDestination)) {
        $backupDeclarations = $programsDirectory . DIRECTORY_SEPARATOR . '.backup-' . $operationId . '.declarations';
        if (!rename($declarationsDestination, $backupDeclarations)) {
            throw new RuntimeException('The existing declarations file could not be staged for replacement.');
        }
    }
    if (file_exists($updateDestination)) {
        $backupUpdate = $programsDirectory . DIRECTORY_SEPARATOR . '.backup-' . $operationId . '.update';
        if (!rename($updateDestination, $backupUpdate)) {
            throw new RuntimeException('The existing update file could not be staged for replacement.');
        }
    }

    if (!rename($stagedDeclarations, $declarationsDestination)) {
        throw new RuntimeException('The declarations file could not be saved.');
    }
    $stagedDeclarations = null;
    $installedDeclarations = true;
    if (!rename($stagedUpdate, $updateDestination)) {
        throw new RuntimeException('The update file could not be saved.');
    }
    $stagedUpdate = null;
    $installedUpdate = true;

    if ($backupDeclarations !== null) {
        @unlink($backupDeclarations);
        $backupDeclarations = null;
    }
    if ($backupUpdate !== null) {
        @unlink($backupUpdate);
        $backupUpdate = null;
    }

    flock($lock, LOCK_UN);
    fclose($lock);
    $lock = null;

    api_json([
        'ok' => true,
        'name' => $programName,
        'declarations_size' => filesize($declarationsDestination),
        'update_size' => filesize($updateDestination),
        'message' => 'Program uploaded successfully.',
    ], 201);
} catch (Throwable $error) {
    if ($installedDeclarations && $declarationsDestination !== null && is_file($declarationsDestination)) {
        @unlink($declarationsDestination);
    }
    if ($installedUpdate && $updateDestination !== null && is_file($updateDestination)) {
        @unlink($updateDestination);
    }
    if ($backupDeclarations !== null && $declarationsDestination !== null && is_file($backupDeclarations)) {
        @rename($backupDeclarations, $declarationsDestination);
    }
    if ($backupUpdate !== null && $updateDestination !== null && is_file($backupUpdate)) {
        @rename($backupUpdate, $updateDestination);
    }
    foreach ([$stagedDeclarations, $stagedUpdate] as $stagedPath) {
        if ($stagedPath !== null && is_file($stagedPath)) {
            @unlink($stagedPath);
        }
    }
    if (is_resource($lock)) {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
    error_log('3DPL program upload error: ' . $error->getMessage());
    api_fail('program_upload_failed', 'The program could not be saved.', 500);
}
