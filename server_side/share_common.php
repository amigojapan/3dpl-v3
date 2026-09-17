<?php
declare(strict_types=1);
require_once __DIR__ . '/api_common.php';

const SHARE_MAX_BYTES = 256 * 1024 * 1024;
const SHARE_MAX_FILES = 2048;
const SHARE_LIBRARIES = ['Objects', 'Maps', 'Textures', 'Audio', 'Sounds', 'Skyboxes'];

function share_valid_id(string $id): bool
{
    return preg_match('/^[a-f0-9]{32}$/D', $id) === 1;
}

function share_root(): string
{
    $root = __DIR__ . '/SharedGames';
    if (is_link($root) || !is_dir($root)) throw new RuntimeException('SharedGames storage is unavailable.');
    return $root;
}

/** Only named media files under a known library can enter a snapshot. */
function share_reference(string $library, string $reference): string
{
    if (!in_array($library, SHARE_LIBRARIES, true)) throw new InvalidArgumentException('Unknown asset library.');
    $reference = str_replace('\\', '/', trim($reference));
    $reference = preg_replace('~^(?:\./)+~', '', $reference) ?? '';
    $reference = preg_replace('~^/?' . preg_quote($library, '~') . '/~i', '', $reference) ?? '';
    $reference = explode('?', explode('#', $reference, 2)[0], 2)[0];
    if (strlen($reference) > 256 || $reference === '') throw new InvalidArgumentException('Invalid asset filename.');
    foreach (explode('/', $reference) as $part) {
        if ($part === '.' || $part === '..' || preg_match('/^[A-Za-z0-9][A-Za-z0-9 ._-]*$/D', $part) !== 1) {
            throw new InvalidArgumentException('Invalid asset path.');
        }
    }
    $extensions = match ($library) {
        'Objects' => ['json', 'xml'], 'Maps' => ['json'],
        'Textures', 'Skyboxes' => ['png', 'jpg', 'jpeg', 'webp', 'gif'],
        default => ['mp3', 'wav', 'ogg', 'oga', 'opus', 'm4a', 'aac', 'flac', 'webm'],
    };
    if (!in_array(strtolower(pathinfo($reference, PATHINFO_EXTENSION)), $extensions, true)) {
        throw new InvalidArgumentException('Unsupported asset type.');
    }
    return $reference;
}

function share_find_file(string $root, string $reference): ?string
{
    if (is_link($root)) throw new RuntimeException('Unsafe asset directory.');
    if (!is_dir($root)) return null;
    $parts = explode('/', $reference);
    foreach ($parts as $index => $part) {
        $entry = api_find_entry_case_insensitive($root, $part);
        if ($entry === null) return null;
        $root .= '/' . $entry;
        if (is_link($root)) throw new RuntimeException('Linked assets cannot be shared.');
        if ($index < count($parts) - 1 && !is_dir($root)) return null;
    }
    return is_file($root) ? $root : null;
}

function share_source(string $nick, string $library, string $reference): ?string
{
    // Mirrors the runtime: flat private filenames take precedence over shared files.
    if (in_array($library, ['Objects', 'Maps', 'Textures', 'Audio'], true) && !str_contains($reference, '/')) {
        $users = api_users_root(false);
        $user = $users . '/' . $nick;
        if (is_link($user)) throw new RuntimeException('Unsafe user directory.');
        $private = share_find_file($user . '/' . $library, $reference);
        if ($private !== null) return $private;
    }
    $shared = share_find_file(dirname(__DIR__) . '/' . $library, $reference);
    if ($shared === null && $library === 'Audio') {
        $shared = share_find_file(dirname(__DIR__) . '/Sounds', $reference);
    }
    return $shared;
}

function share_mime(string $reference): string
{
    return match (strtolower(pathinfo($reference, PATHINFO_EXTENSION))) {
        'json' => 'application/json', 'xml' => 'application/xml',
        'png' => 'image/png', 'jpg', 'jpeg' => 'image/jpeg', 'gif' => 'image/gif', 'webp' => 'image/webp',
        'mp3' => 'audio/mpeg', 'wav' => 'audio/wav', 'ogg', 'oga', 'opus' => 'audio/ogg',
        'm4a' => 'audio/mp4', 'aac' => 'audio/aac', 'flac' => 'audio/flac', 'webm' => 'audio/webm',
        default => 'application/octet-stream',
    };
}

/** A snapshot contains only explicitly requested assets and their dependencies. */
function share_create(string $nick, string $name, string $declarations, string $update, array $requested): string
{
    if (!api_valid_nick($nick) || !api_valid_program_name($name)) throw new InvalidArgumentException('Invalid creator or program name.');
    foreach ([$declarations, $update] as $code) {
        if (strlen($code) > THREEDPL_MAX_PROGRAM_PART_BYTES || str_contains($code, "\0") || preg_match('//u', $code) !== 1) {
            throw new InvalidArgumentException('Each program part must be valid UTF-8 text up to 2 MB.');
        }
    }
    if (count($requested) > SHARE_MAX_FILES) throw new InvalidArgumentException('Too many assets.');
    $queue = [];
    $enqueue = static function(string $library, string $reference, bool $required = true) use (&$queue): void {
        $reference = share_reference($library, $reference);
        $key = strtolower($library . '/' . $reference);
        if (count($queue) >= SHARE_MAX_FILES && !isset($queue[$key])) throw new RuntimeException('Too many shared assets.');
        $queue[$key] = ['library' => $library, 'reference' => $reference, 'required' => $required || ($queue[$key]['required'] ?? false)];
    };
    foreach ($requested as $asset) {
        if (!is_array($asset) || !is_string($asset['library'] ?? null) || !is_string($asset['reference'] ?? null)) {
            throw new InvalidArgumentException('Invalid asset list.');
        }
        $enqueue($asset['library'], $asset['reference']);
    }
    $enqueue('Skyboxes', 'sunflowers_puresky_2k.jpg');

    // Include literal asset-loader calls used later in update code. Generic
    // strings and comments are skipped, so mentioning a private filename in a
    // message or comment does not accidentally publish that file.
    // Computed filenames are captured by the runtime when they are loaded.
    $pattern = <<<'REGEX'
~//[^\r\n]*|/\*.*?\*/
|\b(?<jsonApi>Obj|XMLObj|Map|LoadMap)\s*\(\s*(?<jsonQuote>["'])(?<jsonValue>(?:\\.|(?!\k<jsonQuote>)[^\\])*?)\k<jsonQuote>
|\b(?<mediaApi>AttachSound|tx)\s*\([^,\r\n()]+,\s*(?<mediaQuote>["'])(?<mediaValue>(?:\\.|(?!\k<mediaQuote>)[^\\])*?)\k<mediaQuote>
|(?<quote>["'`])(?:\\.|(?!\k<quote>)[^\\])*?\k<quote>
~sx
REGEX;
    preg_match_all($pattern, $declarations . "\n" . $update, $matches, PREG_SET_ORDER);
    foreach ($matches as $match) {
        $api = $match['jsonApi'] ?? '';
        if ($api !== '') {
            $library = $api === 'LoadMap' ? 'Maps' : 'Objects';
            $value = stripcslashes($match['jsonValue']);
        } elseif (($match['mediaApi'] ?? '') !== '') {
            $library = $match['mediaApi'] === 'tx' ? 'Textures' : 'Audio';
            $value = stripcslashes($match['mediaValue']);
            $value = basename(str_replace('\\', '/', $value));
        } else {
            continue;
        }
        try { $reference = share_reference($library, $value); } catch (InvalidArgumentException $error) { continue; }
        $enqueue($library, $reference);
    }

    $id = bin2hex(random_bytes(16));
    $stage = share_root() . '/.stage-' . $id;
    if (!mkdir($stage, 0750)) throw new RuntimeException('SharedGames must be writable by PHP.');
    $written = [];
    try {
        $assets = [];
        $visited = [];
        $bytes = strlen($declarations) + strlen($update);
        while (count($visited) < count($queue)) {
            foreach ($queue as $key => $asset) {
                if (isset($visited[$key])) continue;
                $visited[$key] = true;
                $library = $asset['library'];
                $reference = $asset['reference'];
                $source = share_source($nick, $library, $reference);
                if ($source === null) {
                    if ($asset['required']) throw new RuntimeException('Missing asset: ' . $library . '/' . $reference);
                    continue;
                }
                $size = filesize($source);
                if ($size === false || $size > THREEDPL_MAX_AUDIO_BYTES || $bytes + $size > SHARE_MAX_BYTES) {
                    throw new RuntimeException('A shared game must be 256 MB or smaller, with each asset at most 50 MB.');
                }
                $content = file_get_contents($source, false, null, 0, THREEDPL_MAX_AUDIO_BYTES + 1);
                if ($content === false || strlen($content) > THREEDPL_MAX_AUDIO_BYTES) throw new RuntimeException('Could not read shared asset.');
                $bytes += strlen($content);
                if ($bytes > SHARE_MAX_BYTES) throw new RuntimeException('Shared game exceeds 256 MB.');
                $file = hash('sha256', $key) . '.bin';
                $written[] = $stage . '/' . $file;
                if (file_put_contents($stage . '/' . $file, $content, LOCK_EX) !== strlen($content)) throw new RuntimeException('Could not save shared asset.');
                $assets[$key] = ['file' => $file, 'mime' => share_mime($reference)];

                if (strtolower(pathinfo($reference, PATHINFO_EXTENSION)) === 'json') {
                    $data = json_decode($content, true, 128, JSON_THROW_ON_ERROR);
                    if (!is_array($data)) throw new RuntimeException('Invalid JSON asset: ' . $reference);
                    $entries = array_is_list($data) ? $data : ($data['objects'] ?? $data['voxels'] ?? []);
                    foreach ($entries as $entry) {
                        if (!is_array($entry)) continue;
                        if ($library === 'Maps' && ($entry['primitive'] ?? '') !== 'cube') {
                            $dependency = $entry['file'] ?? $entry['filename'] ?? $entry['objectFile'] ?? '';
                            if (is_string($dependency) && $dependency !== '' && !preg_match('~^(?:https?:|data:|blob:)~i', $dependency)) {
                                $enqueue('Objects', $dependency);
                            }
                        }
                        $texture = $entry['TextureName'] ?? '';
                        if (is_string($texture) && $texture !== '') {
                            $texture = basename(str_replace('\\', '/', $texture));
                            if (api_valid_texture_name($texture)) $enqueue('Textures', $texture);
                        }
                    }
                } elseif (strtolower(pathinfo($reference, PATHINFO_EXTENSION)) === 'xml') {
                    preg_match_all('/TextureName=["\']([^"\']+)["\']/', $content, $textures);
                    foreach ($textures[1] as $texture) {
                        $texture = basename(str_replace('\\', '/', html_entity_decode($texture, ENT_QUOTES | ENT_XML1)));
                        if (api_valid_texture_name($texture)) $enqueue('Textures', $texture);
                    }
                }
            }
        }
        $manifest = ['name' => $name, 'declarations' => $declarations, 'update' => $update, 'assets' => $assets];
        $encoded = json_encode($manifest, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $written[] = $stage . '/manifest.json';
        if (file_put_contents($stage . '/manifest.json', $encoded, LOCK_EX) !== strlen($encoded)) throw new RuntimeException('Could not save shared game.');
        if (!rename($stage, share_root() . '/' . $id)) throw new RuntimeException('Could not publish shared game.');
        return $id;
    } catch (Throwable $error) {
        foreach ($written as $file) { if (is_file($file)) unlink($file); }
        rmdir($stage);
        throw $error;
    }
}

function share_read(string $id): array
{
    if (!share_valid_id($id)) throw new InvalidArgumentException('Invalid shared game link.');
    $directory = share_root() . '/' . $id;
    $file = $directory . '/manifest.json';
    if (is_link($directory) || is_link($file) || !is_file($file)) throw new RuntimeException('Shared game not found.');
    return json_decode((string)file_get_contents($file), true, 128, JSON_THROW_ON_ERROR);
}
