# 3DPL maps

Place JSON files exported by Creative Mode's Map Editor in this directory.

Load one from a 3DPL declaration with:

```javascript
vars["level"] = LoadMap("my_map.json", "level");
```

The filename is resolved inside this `Maps/` directory. `LoadMap` does not
load map files from `Objects/` or from the site root.

The second argument is the map's object name. Use it with normal functions such as
`mv("level", 1, 0, 0)`, `rt("level", 0, 90, 0)`, or `sc("level", 2, 2, 2)`.

For an optimized collision check against a named collider loaded inside an
object, use:

```javascript
if (is_object_colliding_with_map(vars["level"], "collider_back")) {
    // The collider is touching a voxel in one of the map's placed objects.
}
```

The function performs a bounding-box `cd()` check against each placed map
object first. It runs the voxel-level check only for a matching map object.

For movement fast enough to jump through a one-unit voxel, use the swept
movement helper. It divides the movement into small steps and rolls back the
first colliding step:

```javascript
move_object_with_map_collision(
    vars["level"], vars["helicopter"],
    "collider_front", 0, 0, 1
);
```

`LoadMap(...).userData.loaded` becomes true only after every referenced JSON
object has finished loading. Tutorial 32 demonstrates the complete helicopter
setup.

Maps may also contain a self-contained cube entry instead of an external object
file:

```json
{
  "primitive": "cube",
  "color": [180, 85, 45],
  "name": "wall",
  "x": 0,
  "y": 4,
  "z": -20,
  "rotationY": 0,
  "scale": 8
}
```

Tutorial 32 uses inline cubes so its collision map does not depend on additional
files in `Objects/`.
