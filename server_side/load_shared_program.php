<?php
declare(strict_types=1);
require_once __DIR__ . '/share_common.php';
// Public, immutable snapshots: no account session or private-library access.
header('Access-Control-Allow-Origin: *');
api_require_method('GET');
try {
    $game = share_read(api_string($_GET, ['share']));
    api_json(['ok' => true, 'name' => $game['name'], 'declarations' => $game['declarations'], 'update' => $game['update']]);
} catch (Throwable $error) {
    api_fail('share_not_found', 'This shared game was not found.', 404);
}
