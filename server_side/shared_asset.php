<?php
declare(strict_types=1);
require_once __DIR__ . '/share_common.php';
header('Access-Control-Allow-Origin: *');
header('X-Content-Type-Options: nosniff');
api_require_method('GET');
try {
    $id = api_string($_GET, ['share']);
    $game = share_read($id);
    $library = api_string($_GET, ['library']);
    $reference = share_reference($library, api_string($_GET, ['name']));
    $asset = $game['assets'][strtolower($library . '/' . $reference)] ?? null;
    if (!is_array($asset) || preg_match('/^[a-f0-9]{64}\.bin$/D', $asset['file'] ?? '') !== 1) throw new RuntimeException('Missing asset.');
    $file = share_root() . '/' . $id . '/' . $asset['file'];
    if (is_link($file) || !is_file($file)) throw new RuntimeException('Missing asset.');
    header('Content-Type: ' . $asset['mime']);
    header('Cache-Control: public, max-age=31536000, immutable');
    header('Content-Length: ' . filesize($file));
    readfile($file);
} catch (Throwable $error) {
    api_fail('asset_not_shared', 'This asset is not included in the shared game.', 404);
}
