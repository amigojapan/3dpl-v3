# 3DPLv3 archive conversion

This directory mirrors `obsolete/` and preserves its subdirectory structure.

- Source files processed: 343
- Binary Unity voxel objects converted to JSON: 219
- XML voxel objects converted to JSON: 21
- Unity-style program files converted to 3DPLv3 JavaScript: 60
- Other assets copied byte-for-byte: 43

Every original is accounted for in `conversion-manifest.json`, including hashes and its output path. Files ending in `のコピー` receive distinct `.copy.json` names. Where both `.obj` and `.xml` versions existed, the `.obj` conversion is `name.json` and the XML conversion is `name.xml.json`.

The newest game scripts referenced six renamed or misspelled assets absent under those old names. They were linked to the corresponding models present in the immediately preceding archived revisions; the exact aliases are recorded in the manifest.

To run a converted program, make its referenced JSON objects available through the shared `Objects/` directory or the logged-in user's `server_side/Users/<nick>/Objects/` storage, then load its matching `.declarations` and `.update` files in 3DPL programming mode.
