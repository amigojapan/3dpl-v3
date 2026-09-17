<?php
declare(strict_types=1);
$id = is_string($_GET['share'] ?? null) ? $_GET['share'] : '';
if (preg_match('/^[a-f0-9]{32}$/D', $id) !== 1) {
    http_response_code(404);
    exit('This shared game link is invalid.');
}
header('Referrer-Policy: no-referrer');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Play a 3DPL game</title>
    <style>html,body,iframe{margin:0;width:100%;height:100%;border:0;display:block;background:#111;overflow:hidden}</style>
</head>
<body>
    <iframe title="3DPL game" sandbox="allow-scripts allow-pointer-lock" allow="autoplay; fullscreen"
        src="server_side/shared_player.php?share=<?= htmlspecialchars($id, ENT_QUOTES) ?>"></iframe>
</body>
</html>
