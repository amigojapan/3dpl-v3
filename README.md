# 3dpl-v3
 an attempt to port 3dpl to three.js

In programming mode, **Share** saves the current program and its loaded assets
as a playable snapshot and copies a link. The Share control uses a share icon.
Each game is stored at `server_side/SharedGames/<nick>/<game name>`.
Sharing the same game again warns before replacing its published program and
assets; cancelling leaves it unchanged. The link stays the same and opens the
latest published version. Players need no account or creator
password. The game starts automatically with editing controls hidden; the
onscreen controller remains available, including Pause/Resume. Audio starts
after the player's first tap or key press, as required by browsers.

Sharing requires PHP 8.1 or later and a `server_side/SharedGames` directory writable
by PHP. Keep its `.htaccess` protection; on servers without Apache access rules,
deny direct HTTP access to that directory. The public PHP endpoints serve each
snapshot. Local edits do not affect a game until it is shared again. Used private assets
are included in the snapshot; unrelated private library files are not included.
Run through any sections that generate asset filenames before sharing so those
assets are captured too. Players cannot save changes to the creator's program;
browser-delivered program source can still be inspected.

**Play Games** on the main menu lists all published games by nickname and game
name, with links to play them. Unshared private programs are not published.
Older hash-based links still work and appear under “Unknown creator (legacy)”
because those snapshots did not record their creator's nickname.

Offline sharing checks: `php scripts/test_sharing.php` and
`node scripts/test_sharing_client.cjs`.

The dragon game uses **both** `Programs/finalx3.declarations` and
`Programs/finalx3.update`. Load both into their corresponding programming panes.
The declarations initialize the wave and building state, then attach `music.mp3`
once. Upload that file to your Audio library; do not attach it in every update.
