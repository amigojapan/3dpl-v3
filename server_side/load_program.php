<?php
declare(strict_types=1);

require_once __DIR__ . '/api_common.php';

api_require_method('GET');
$nick = api_require_user();
session_write_close();

$lock = null;
try {
    $programsDirectory = api_programs_directory($nick, true);
    $lock = fopen($programsDirectory . DIRECTORY_SEPARATOR . '.programs.lock', 'c');
    if ($lock === false || !flock($lock, LOCK_SH)) {
        throw new RuntimeException('The program storage lock could not be acquired.');
    }

    $requestedName = api_normalize_program_name(api_string($_GET, ['name']));
    if ($requestedName === '') {
        $entries = scandir($programsDirectory);
        if ($entries === false) {
            throw new RuntimeException('The program storage directory could not be read.');
        }

        $programs = [];
        $seenNames = [];
        foreach ($entries as $entry) {
            if (preg_match('/^(.*)\.declarations$/iD', $entry, $matches) !== 1) {
                continue;
            }
            $programName = $matches[1];
            $nameKey = strtolower($programName);
            if (!api_valid_program_name($programName) || isset($seenNames[$nameKey])) {
                continue;
            }

            $updateEntry = api_find_entry_case_insensitive($programsDirectory, $programName . '.update');
            if ($updateEntry === null) {
                continue;
            }
            $declarationsPath = $programsDirectory . DIRECTORY_SEPARATOR . $entry;
            $updatePath = $programsDirectory . DIRECTORY_SEPARATOR . $updateEntry;
            if (!is_file($declarationsPath) || is_link($declarationsPath)
                || !is_file($updatePath) || is_link($updatePath)) {
                continue;
            }

            $seenNames[$nameKey] = true;
            $programs[] = [
                'name' => $programName,
                'declarations_size' => filesize($declarationsPath),
                'update_size' => filesize($updatePath),
                'modified' => max((int)filemtime($declarationsPath), (int)filemtime($updatePath)),
            ];
        }
        usort($programs, static fn(array $left, array $right): int => strnatcasecmp($left['name'], $right['name']));

        flock($lock, LOCK_UN);
        fclose($lock);
        $lock = null;
        api_json([
            'ok' => true,
            'programs' => $programs,
        ]);
    }

    if (!api_valid_program_name($requestedName)) {
        api_fail('invalid_program_name', 'That program name is not valid.', 422);
    }

    $declarationsEntry = api_find_entry_case_insensitive(
        $programsDirectory,
        $requestedName . '.declarations'
    );
    $updateEntry = api_find_entry_case_insensitive($programsDirectory, $requestedName . '.update');
    if ($declarationsEntry === null || $updateEntry === null) {
        api_fail('program_not_found', 'That personal program was not found.', 404);
    }

    $declarationsPath = $programsDirectory . DIRECTORY_SEPARATOR . $declarationsEntry;
    $updatePath = $programsDirectory . DIRECTORY_SEPARATOR . $updateEntry;
    if (!is_file($declarationsPath) || is_link($declarationsPath)
        || !is_file($updatePath) || is_link($updatePath)) {
        api_fail('program_not_found', 'That personal program was not found.', 404);
    }

    $declarations = file_get_contents($declarationsPath);
    $update = file_get_contents($updatePath);
    if ($declarations === false || $update === false
        || str_contains($declarations, "\0") || str_contains($update, "\0")
        || preg_match('//u', $declarations) !== 1 || preg_match('//u', $update) !== 1) {
        throw new RuntimeException('The stored program files are not valid UTF-8 text.');
    }

    flock($lock, LOCK_UN);
    fclose($lock);
    $lock = null;
    api_json([
        'ok' => true,
        'name' => api_normalize_program_name($declarationsEntry),
        'declarations' => $declarations,
        'update' => $update,
    ]);
} catch (Throwable $error) {
    if (is_resource($lock)) {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
    error_log('3DPL program load error: ' . $error->getMessage());
    api_fail('program_load_failed', 'Your program storage could not be read.', 500);
}
