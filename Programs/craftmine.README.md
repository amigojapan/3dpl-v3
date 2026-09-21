# craftmine

A small creative block sandbox for 3DPLv3. It starts in a grassy 36 × 36 world
with trees, hills, a pond, and soil and stone beneath the surface. There is no
crafting or resource cost. Save your builds as 3DPL object JSON files before
reloading the program.

## Load

Choose **Creative Mode → craftmine** to load and start it directly. Click the
world to capture the mouse. **Creative menu** exits; **Code** opens the program.

To load it manually:

Load `craftmine.declarations` into the declarations pane and `craftmine.update`
into the update pane in 3DPL programming mode. Press START, hide the programming
panel for a full view, then click the world to capture the mouse.

The script uses your existing 3DPL texture library. No engine changes or new
models are required. Install the package's `main.js`, `3dplv3.html`, and program
files to add the Creative menu button. Keep the program files in `Programs/`.

## Controls

- **WASD:** walk; **Space:** jump.
- **Mouse:** look; **Escape:** release the mouse.
- **Left click:** break the aimed block; **right click:** place a block.
- **1–9** or **mouse wheel:** select a hotbar slot.
- **E / Textures:** browse and search all 175 built-in textures, plus textures
  uploaded to the signed-in account. Picking one replaces the selected slot.
- **F / Corner 1** and **G / Corner 2:** aim at two blocks to mark a region.
- **Save JSON:** download the whole world or just the selected region.
- **Touch:** drag on the world to look, use the 3DPL onscreen WASD controls to
  walk, and use the game's Break, Place, and Jump buttons.

Reach is six blocks. You cannot place a block inside yourself, remove the
bottom stone layer, or build above height 24. Falling off the world returns
you to the starting area. The texture picker and save dialog pause movement.

## Save a world or a build

For the whole world, click **Save JSON**, choose **Whole world**, and download.
For an individual build, aim at its opposite corners and press **F** then **G**.
The yellow box shows the region, including both corner blocks. Click **Save JSON**
and choose **Selected region**. You can also enter or adjust all six corner
coordinates directly in the dialog; the block count updates as you edit.

Select **Move the lower corner to (0, 0, 0)** to make a reusable object.
Leave it unchecked to retain the original world coordinates. Give the file a
name, then click **Download JSON**. The exported array works with the 3DPL
Object Editor's JSON import and with `Obj("my-house.json", "house", 0, 0, 0)`
after placing the file in your Objects library.

Textures are referenced by filename, so the receiving installation needs those
textures too. Standard 3DPL objects store one texture per voxel; exported grass
uses its grass texture on every face. The file contains blocks only, not the
player, selection outline, or controls. Export does not automatically save edits
back into craftmine or add a craftmine world-import feature.

For downloads through shared links, also install the included `play.php` and
`server_side/shared_player.php`. They enable file downloads in the isolated player.

The built-in textures are registered with 3DPL's Share feature, so shared
players can use the public texture palette without signing in. New shared
games start from the original landscape; download JSON to keep your builds.

Offline checks: `node scripts/test_craftmine.mjs /path/to/three.module.mjs`.
