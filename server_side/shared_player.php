<?php
declare(strict_types=1);
require_once __DIR__ . '/share_common.php';
api_require_method('GET');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
if (isset($_GET['runtime'])) {
    // A fixed public module, served with CORS for the opaque-origin game frame.
    header('Access-Control-Allow-Origin: *');
    header('Content-Type: text/javascript; charset=utf-8');
    header('Cache-Control: no-cache');
    readfile(dirname(__DIR__) . '/main.js');
    exit;
}
$id = api_string($_GET, ['share']);
if (!share_valid_id($id)) api_fail('invalid_share', 'Invalid shared game link.', 404);
// Also isolate the frame when someone opens its URL directly.
header('Content-Security-Policy: sandbox allow-scripts allow-pointer-lock');
header('Content-Type: text/html; charset=utf-8');
$html = (string)file_get_contents(dirname(__DIR__) . '/3dplv3.html');
$html = str_replace('<html lang="en">', '<html lang="en" class="shared-player">', $html);
$bootstrap = '<base href="../"><script>window.THREEDPL_SHARED_GAME=' . json_encode($id) . ';</script>';
$html = str_replace('<head>', '<head>' . $bootstrap, $html);
$html = preg_replace('~src="main\.js[^"\s]*"~', 'src="server_side/shared_player.php?runtime=1"', $html);
echo $html;
