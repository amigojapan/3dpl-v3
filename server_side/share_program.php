<?php
declare(strict_types=1);
require_once __DIR__ . '/share_common.php';
api_require_method('POST');
if (($_SERVER['HTTP_X_3DPL_SHARE'] ?? '') !== '1') api_fail('invalid_request', 'Use the Share button to create a link.', 403);
$nick = api_require_user();
session_write_close();
if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 5 * 1024 * 1024) api_fail('too_large', 'Share request is too large.', 413);
$data = api_request_data();
api_require_matching_scope($data, $nick);
try {
    $assets = $data['assets'] ?? [];
    if (!is_array($assets)) throw new InvalidArgumentException('Invalid asset list.');
    $id = share_create($nick, api_string($data, ['name']), api_string($data, ['declarations'], false), api_string($data, ['update'], false), $assets);
    api_json(['ok' => true, 'id' => $id], 201);
} catch (Throwable $error) {
    error_log('3DPL share error: ' . $error->getMessage());
    api_fail('share_failed', $error->getMessage(), 422);
}
