<?php
declare(strict_types=1);
// Run with: php scripts/test_sharing.php
// All mutable fixtures live in a temporary copy, never in real user storage.
$root = sys_get_temp_dir() . '/3dpl-share-test-' . bin2hex(random_bytes(6));
mkdir($root . '/server_side/SharedGames', 0775, true);
foreach (['Objects', 'Maps', 'Textures', 'Audio', 'Skyboxes', 'server_side/Users/creator/Objects',
    'server_side/Users/creator/Maps', 'server_side/Users/creator/Textures', 'server_side/Users/creator/Audio',
    'server_side/Users/other/Objects'] as $dir) mkdir($root . '/' . $dir, 0775, true);
foreach (['api_common.php', 'share_common.php', 'share_program.php', 'load_shared_program.php', 'shared_asset.php', 'shared_player.php', 'list_shared_games.php'] as $file) {
    copy(__DIR__ . '/../server_side/' . $file, $root . '/server_side/' . $file);
}
require $root . '/server_side/share_common.php';
function check(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
function rejects(callable $fn, string $message): void {
    try { $fn(); } catch (Throwable $error) { return; }
    throw new RuntimeException($message);
}
function endpoint(string $root, string $file, string $setup): array {
    $code = $setup . '; require ' . var_export($root . '/server_side/' . $file, true) . ';';
    $command = [PHP_BINARY, '-r', $code];
    $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    check(proc_close($process) === 0, $err);
    return json_decode($out, true, 128, JSON_THROW_ON_ERROR);
}
try {
    check(share_list() === [], 'Empty catalog');
    file_put_contents($root . '/Skyboxes/sunflowers_puresky_2k.jpg', 'sky');
    file_put_contents($root . '/Objects/building.json', '[{"x":99}]');
    $privateObject = '[{"x":1,"y":2,"z":3,"TextureName":"Textures/wall.png"}]';
    file_put_contents($root . '/server_side/Users/creator/Objects/building.json', $privateObject);
    file_put_contents($root . '/server_side/Users/creator/Objects/private-secret.json', '[]');
    file_put_contents($root . '/server_side/Users/other/Objects/other-secret.json', '[]');
    file_put_contents($root . '/server_side/Users/creator/Objects/later.json', '[]');
    file_put_contents($root . '/server_side/Users/creator/Maps/city.json', '{"objects":[{"file":"Objects/building.json"}]}');
    file_put_contents($root . '/server_side/Users/creator/Textures/wall.png', 'texture');
    file_put_contents($root . '/server_side/Users/creator/Audio/fire.wav', 'audio');
    $decl = 'vars.city=LoadMap("city.json", "city"); console.log("private-secret.json"); // Obj("private-secret.json")';
    $update = 'Obj("later.json","later",0,0,0); AttachSound("camera","fire.wav");';
    $id = share_create('creator', 'City game', $decl, $update, [['library'=>'Maps','reference'=>'city.json']]);
    check($id === 'creator/City game' && share_valid_id($id), 'Share is named by creator and game');
    $game = share_read($id);
    check($game['declarations'] === $decl && $game['update'] === $update, 'Exact program snapshot');
    foreach (['maps/city.json','objects/building.json','textures/wall.png','audio/fire.wav','objects/later.json','skyboxes/sunflowers_puresky_2k.jpg'] as $key) {
        check(isset($game['assets'][$key]), 'Missing transitive or later-used asset: ' . $key);
    }
    check(count($game['assets']) === 6, 'Private library or commented references leaked');
    $objectFile = $root . '/server_side/SharedGames/' . $id . '/' . $game['assets']['objects/building.json']['file'];
    check(file_get_contents($objectFile) === $privateObject, 'Private asset must take precedence');
    file_put_contents($root . '/server_side/Users/creator/Objects/building.json', '[]');
    check(file_get_contents($objectFile) === $privateObject, 'Snapshot must remain immutable');
    $public = endpoint($root, 'load_shared_program.php', '$_SERVER["REQUEST_METHOD"]="GET"; $_GET=["share"=>' . var_export($id,true) . ']');
    check($public['ok'] && $public['declarations'] === $decl, 'Anonymous public playback');
    check(!isset($public['owner']) && !isset($public['assets']), 'Public manifest must not reveal user storage');
    $post = endpoint($root, 'load_shared_program.php', '$_SERVER["REQUEST_METHOD"]="POST"');
    check($post['error'] === 'method_not_allowed', 'Public manifest must be read-only');
    $unauth = endpoint($root, 'share_program.php', 'ini_set("session.save_path",sys_get_temp_dir()); $_SERVER["REQUEST_METHOD"]="POST"; $_SERVER["HTTP_X_3DPL_SHARE"]="1"');
    check($unauth['error'] === 'not_logged_in', 'Sharing requires creator authentication');
    $csrf = endpoint($root, 'share_program.php', '$_SERVER["REQUEST_METHOD"]="POST"');
    check($csrf['error'] === 'invalid_request', 'Cross-site forms cannot create shares');
    $sessionSetup = 'ini_set("session.save_path",' . var_export($root, true) . '); session_start(); $_SESSION["3dpl_user_nick"]="creator"; $_SERVER["REQUEST_METHOD"]="POST"; $_SERVER["HTTP_X_3DPL_SHARE"]="1";';
    $created = endpoint($root, 'share_program.php', $sessionSetup . '$_POST=' . var_export([
        'scope'=>'creator', 'name'=>'Published', 'declarations'=>'qb("cube",0,0,0);', 'update'=>'', 'assets'=>[]
    ],true));
    check($created['ok'] && share_valid_id($created['id']), 'Authenticated Share endpoint must publish');
    check(share_read($created['id'])['name'] === 'Published', 'Published snapshot must be readable');
    $replacement = ['scope'=>'creator', 'name'=>'Published', 'declarations'=>'qb("replacement",1,2,3);', 'update'=>'', 'assets'=>[]];
    for ($attempt = 0; $attempt < 2; $attempt++) {
        $conflict = endpoint($root, 'share_program.php', $sessionSetup . '$_POST=' . var_export($replacement, true));
        check($conflict['error'] === 'overwrite_required', 'Every unconfirmed overwrite must be rejected');
        check(share_read($created['id'])['declarations'] === 'qb("cube",0,0,0);', 'Rejected share must not change published game');
    }
    $replacement['overwrite'] = true;
    $replaced = endpoint($root, 'share_program.php', $sessionSetup . '$_POST=' . var_export($replacement, true));
    check($replaced['ok'] && $replaced['id'] === $created['id'], 'Overwrite keeps the same link');
    check(share_read($created['id'])['declarations'] === $replacement['declarations'], 'Overwrite updates code');
    rejects(fn()=>share_create('creator', 'Published', '', '', [['library'=>'Objects','reference'=>'missing.json']], true), 'Failed replacement should fail');
    check(share_read($created['id'])['declarations'] === $replacement['declarations'], 'Failed replacement preserves published game');
    $otherId = share_create('other', 'Published', 'other creator', '', []);
    check($otherId !== $created['id'] && share_read($created['id'])['declarations'] === $replacement['declarations'], 'Creators have separate game namespaces');
    $legacyId = str_repeat('a', 32);
    mkdir($root . '/server_side/SharedGames/' . $legacyId);
    file_put_contents($root . '/server_side/SharedGames/' . $legacyId . '/manifest.json', json_encode(['name'=>'Old game','declarations'=>'old code','update'=>'','assets'=>[]]));
    check(share_read($legacyId)['declarations'] === 'old code', 'Legacy links remain readable');
    $listing = endpoint($root, 'list_shared_games.php', '$_SERVER["REQUEST_METHOD"]="GET"');
    check($listing['ok'] && count($listing['games']) === 4, 'Anonymous catalog includes all published games, including legacy');
    check(array_column($listing['games'], 'id') === [$id, $created['id'], $otherId, $legacyId], 'Catalog sorted by nickname and game');
    check(array_keys($listing['games'][0]) === ['id', 'nick', 'name'], 'Catalog exposes only public listing fields');
    $wrongScope = endpoint($root, 'share_program.php', $sessionSetup . '$_POST=["scope"=>"other"]');
    check($wrongScope['error'] === 'session_scope_mismatch', 'Creator cannot share another account scope');
    $hidden = endpoint($root, 'shared_asset.php', '$_SERVER["REQUEST_METHOD"]="GET"; $_GET=' . var_export(['share'=>$id,'library'=>'Objects','name'=>'private-secret.json'],true));
    check($hidden['error'] === 'asset_not_shared', 'Shared token cannot access unshared private assets');
    $traversal = endpoint($root, 'shared_asset.php', '$_SERVER["REQUEST_METHOD"]="GET"; $_GET=' . var_export(['share'=>$id,'library'=>'Objects','name'=>'../Users/other/Objects/other-secret.json'],true));
    check($traversal['error'] === 'asset_not_shared', 'Traversal must be denied');
    share_create('creator', 'City game', $decl, $update, [], true);
    check(file_get_contents($objectFile) === '[]', 'Republishing replaces asset bytes');
    share_create('creator', 'City game', 'new code', '', [], true);
    check(!isset(share_read($id)['assets']['objects/building.json']) && !file_exists($objectFile), 'Replacement removes obsolete assets');
    foreach (['creator/../Published', '../City game', 'creator/..', 'creator/Game/extra', 'creator/Game\\extra'] as $badId) {
        rejects(fn()=>share_read($badId), 'Named share traversal accepted: ' . $badId);
    }
    rejects(fn()=>share_read('../../userdb.sqlite3'), 'Share ID traversal accepted');
    rejects(fn()=>share_reference('Programs','secret.declarations'), 'Unsupported private library accepted');
    rejects(fn()=>share_create('creator','Bad','', '', [['library'=>'Objects','reference'=>'missing.json']]), 'Missing dependencies silently shared');
    check(count(glob($root . '/server_side/SharedGames/.stage-*')) === 0, 'Failed shares must clean staging data');
    symlink($root . '/server_side/Users/other/Objects/other-secret.json', $root . '/Objects/link.json');
    rejects(fn()=>share_create('creator','Linked','', '', [['library'=>'Objects','reference'=>'link.json']]), 'Symlink leaked a different user asset');
    symlink($root . '/server_side/SharedGames/creator', $root . '/server_side/SharedGames/linked');
    rejects(fn()=>share_read('linked/Published'), 'Linked creator directory accepted');
    rejects(fn()=>share_create('linked','Published','', '', [], true), 'Linked creator directory overwritten');
    check(count(share_list()) === 4, 'Catalog excludes symlinks and unfinished directories');
    check(count(glob($root . '/server_side/SharedGames/.stage-*')) === 0, 'Replacement leaves no staging or backup data');
    echo "PASS: named shares, confirmed overwrites, stable links, asset replacement, failed replacement recovery, anonymous catalog, legacy links, transitive assets, authentication, CSRF, namespace isolation, traversal, symlinks, and cleanup.\n";
} finally {
    $entries = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($entries as $entry) {
        if ($entry->isDir() && !$entry->isLink()) rmdir($entry->getPathname()); else unlink($entry->getPathname());
    }
    rmdir($root);
}
