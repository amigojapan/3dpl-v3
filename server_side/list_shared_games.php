<?php
declare(strict_types=1);
require_once __DIR__ . '/share_common.php';
api_require_method('GET');
try {
    api_json(['ok' => true, 'games' => share_list()]);
} catch (Throwable $error) {
    error_log('3DPL game listing error: ' . $error->getMessage());
    api_fail('listing_failed', 'The games could not be loaded. Please try again.', 500);
}
