#!/usr/bin/env python3
"""Generate deterministic PNG thumbnails for 3DPL voxel-object JSON files.

The renderer uses only the object JSON and local Textures/ files. It deliberately
does not require WebGL, a browser, or a running web server, which makes it useful
both during development and when preparing files for deployment.
"""

from __future__ import annotations

import argparse
import hashlib
import json
import math
import sys
from dataclasses import dataclass
from pathlib import Path
from typing import Iterable, Sequence

from PIL import Image, ImageDraw, ImageFilter


ROOT = Path(__file__).resolve().parents[1]
DEFAULT_OBJECTS_DIR = ROOT / "Objects"
DEFAULT_TEXTURES_DIR = ROOT / "Textures"
DEFAULT_OUTPUT_DIR = DEFAULT_OBJECTS_DIR / "Preview"
DEFAULT_MANIFEST = DEFAULT_OBJECTS_DIR / "objects-manifest.json"

BACKGROUND_TOP = (25, 34, 48, 255)
BACKGROUND_BOTTOM = (8, 13, 22, 255)
COLLIDER_COLORS = ((255, 74, 135), (54, 224, 255), (255, 205, 55))

# View from the positive X/Y/Z octant. This displays three sides of each cube.
TO_CAMERA = (1.0, 0.78, 1.0)
VIEW_FORWARD = tuple(-component for component in TO_CAMERA)


def normalize(vector: Sequence[float]) -> tuple[float, float, float]:
    length = math.sqrt(sum(component * component for component in vector))
    return tuple(component / length for component in vector)  # type: ignore[return-value]


def cross(
    left: Sequence[float], right: Sequence[float]
) -> tuple[float, float, float]:
    return (
        left[1] * right[2] - left[2] * right[1],
        left[2] * right[0] - left[0] * right[2],
        left[0] * right[1] - left[1] * right[0],
    )


def dot(left: Sequence[float], right: Sequence[float]) -> float:
    return sum(a * b for a, b in zip(left, right))


FORWARD = normalize(VIEW_FORWARD)
RIGHT = normalize(cross(FORWARD, (0.0, 1.0, 0.0)))
UP = normalize(cross(RIGHT, FORWARD))


@dataclass(frozen=True)
class Face:
    depth: float
    points: tuple[tuple[float, float], ...]
    color: tuple[int, int, int, int]
    outline: tuple[int, int, int, int]
    sort_key: tuple[float, int, float, float, float]


class TextureSampler:
    """Caches representative colors for texture faces.

    At thumbnail scale, most voxel faces occupy only a few pixels. Sampling the
    average color of the correct atlas region preserves the texture's overall
    appearance while avoiding aliasing and thousands of perspective resamples.
    """

    def __init__(self, textures_dir: Path):
        self.textures_dir = textures_dir
        self._cache: dict[tuple[str, int, str], tuple[int, int, int, int]] = {}
        self.missing: set[str] = set()

    def color(self, filename: str, wrap: int, face_name: str) -> tuple[int, int, int, int]:
        key = (filename, wrap, face_name)
        if key in self._cache:
            return self._cache[key]

        path = self.textures_dir / filename
        if not path.is_file():
            self.missing.add(filename)
            result = (255, 0, 255, 255)
            self._cache[key] = result
            return result

        with Image.open(path) as source:
            texture = source.convert("RGBA")
            if wrap == 1:
                # Matches apply3DPLWrap1() in main.js. UV row 1 is the upper
                # image half because Three.js flips loaded textures vertically.
                atlas_face = {
                    "+z": (0, 1),  # front
                    "+y": (2, 1),  # top
                    "+x": (2, 0),  # right
                }[face_name]
                column, row = atlas_face
                x0 = round(column * texture.width / 3)
                x1 = round((column + 1) * texture.width / 3)
                y0 = 0 if row == 1 else texture.height // 2
                y1 = texture.height // 2 if row == 1 else texture.height
                texture = texture.crop((x0, y0, x1, y1))

            result = alpha_weighted_average(texture)
            self._cache[key] = result
            return result


def alpha_weighted_average(image: Image.Image) -> tuple[int, int, int, int]:
    pixels = list(image.getdata())
    alpha_total = sum(pixel[3] for pixel in pixels)
    if alpha_total == 0:
        return (255, 255, 255, 0)
    red = round(sum(pixel[0] * pixel[3] for pixel in pixels) / alpha_total)
    green = round(sum(pixel[1] * pixel[3] for pixel in pixels) / alpha_total)
    blue = round(sum(pixel[2] * pixel[3] for pixel in pixels) / alpha_total)
    alpha = round(alpha_total / len(pixels))
    return (red, green, blue, alpha)


def read_voxels(path: Path) -> list[dict]:
    with path.open(encoding="utf-8") as source:
        document = json.load(source)
    if isinstance(document, list):
        voxels = document
    elif isinstance(document, dict):
        voxels = document.get("voxels", document.get("objects"))
    else:
        voxels = None
    if not isinstance(voxels, list):
        raise ValueError("expected a JSON array or an object containing voxels/objects")
    return [
        voxel
        for voxel in voxels
        if isinstance(voxel, dict)
        and (voxel.get("cubename") or voxel.get("name")) != "AxisPoint"
    ]


def voxel_position(voxel: dict) -> tuple[float, float, float]:
    return tuple(float(voxel.get(axis, 0)) for axis in ("x", "y", "z"))  # type: ignore[return-value]


def grid_position(voxel: dict) -> tuple[int, int, int]:
    position = voxel_position(voxel)
    rounded = tuple(round(component) for component in position)
    if any(abs(component - integer) > 1e-6 for component, integer in zip(position, rounded)):
        raise ValueError(f"non-grid voxel coordinate {position}")
    return rounded  # type: ignore[return-value]


def project(point: Sequence[float]) -> tuple[float, float, float]:
    return dot(point, RIGHT), -dot(point, UP), dot(point, FORWARD)


def create_background(width: int, height: int) -> Image.Image:
    image = Image.new("RGBA", (width, height), BACKGROUND_TOP)
    pixels = image.load()
    for y in range(height):
        fraction = y / max(1, height - 1)
        for x in range(width):
            radial = math.hypot((x - width / 2) / width, (y - height * 0.42) / height)
            shade = min(1.0, fraction * 0.72 + radial * 0.28)
            pixels[x, y] = tuple(
                round(top * (1 - shade) + bottom * shade)
                for top, bottom in zip(BACKGROUND_TOP, BACKGROUND_BOTTOM)
            )  # type: ignore[assignment]
    return image


def face_rgba(
    voxel: dict,
    face_name: str,
    face_index: int,
    texture_sampler: TextureSampler,
    invisible_object: bool,
) -> tuple[int, int, int, int]:
    if invisible_object:
        red, green, blue = COLLIDER_COLORS[face_index % len(COLLIDER_COLORS)]
        return red, green, blue, 185

    base = tuple(
        max(0, min(255, round(float(voxel.get(component, 255)))))
        for component in ("r", "g", "b")
    )
    alpha = float(voxel.get("alpha", voxel.get("a", 1.0)))
    texture_name = str(voxel.get("TextureName", "") or "")
    texture = (255, 255, 255, 255)
    if texture_name:
        try:
            wrap = int(voxel.get("WrapOnSides", 6) or 6)
        except (TypeError, ValueError):
            wrap = 6
        texture = texture_sampler.color(texture_name, wrap, face_name)

    lighting = {"+x": 0.76, "+y": 1.10, "+z": 0.91}[face_name]
    rgb = tuple(
        max(0, min(255, round(base[channel] * texture[channel] / 255 * lighting)))
        for channel in range(3)
    )
    output_alpha = max(0, min(255, round(alpha * texture[3])))
    return rgb[0], rgb[1], rgb[2], output_alpha


def build_faces(
    voxels: Sequence[dict], texture_sampler: TextureSampler
) -> tuple[list[Face], tuple[float, float, float, float]]:
    # The last voxel at a duplicated coordinate matches the effective visible
    # result of overlapping cubes while keeping preview generation deterministic.
    occupied = {grid_position(voxel): voxel for voxel in voxels}
    invisible_object = bool(occupied) and all(
        float(voxel.get("alpha", voxel.get("a", 1.0))) <= 0
        for voxel in occupied.values()
    )

    corners: list[tuple[float, float]] = []
    for x, y, z in occupied:
        for dx in (-0.5, 0.5):
            for dy in (-0.5, 0.5):
                for dz in (-0.5, 0.5):
                    px, py, _ = project((x + dx, y + dy, z + dz))
                    corners.append((px, py))
    if not corners:
        raise ValueError("object contains no renderable voxels")
    bounds = (
        min(point[0] for point in corners),
        min(point[1] for point in corners),
        max(point[0] for point in corners),
        max(point[1] for point in corners),
    )

    definitions = (
        ("+x", (1, 0, 0), ((0.5, -0.5, -0.5), (0.5, 0.5, -0.5), (0.5, 0.5, 0.5), (0.5, -0.5, 0.5))),
        ("+y", (0, 1, 0), ((-0.5, 0.5, -0.5), (0.5, 0.5, -0.5), (0.5, 0.5, 0.5), (-0.5, 0.5, 0.5))),
        ("+z", (0, 0, 1), ((-0.5, -0.5, 0.5), (0.5, -0.5, 0.5), (0.5, 0.5, 0.5), (-0.5, 0.5, 0.5))),
    )

    faces: list[Face] = []
    for (x, y, z), voxel in occupied.items():
        for face_index, (face_name, neighbor, offsets) in enumerate(definitions):
            adjacent = (x + neighbor[0], y + neighbor[1], z + neighbor[2])
            if adjacent in occupied:
                continue
            projected = [project((x + dx, y + dy, z + dz)) for dx, dy, dz in offsets]
            points = tuple((point[0], point[1]) for point in projected)
            depth = sum(point[2] for point in projected) / len(projected)
            color = face_rgba(
                voxel, face_name, face_index, texture_sampler, invisible_object
            )
            outline_alpha = 220 if invisible_object else min(150, color[3])
            outline = tuple(max(0, round(channel * 0.42)) for channel in color[:3]) + (
                outline_alpha,
            )
            faces.append(
                Face(
                    depth=depth,
                    points=points,
                    color=color,
                    outline=outline,
                    sort_key=(-depth, face_index, x, y, z),
                )
            )

    faces.sort(key=lambda face: face.sort_key)
    return faces, bounds


def render_object(
    source: Path,
    destination: Path,
    texture_sampler: TextureSampler,
    size: tuple[int, int],
) -> int:
    voxels = read_voxels(source)
    faces, bounds = build_faces(voxels, texture_sampler)
    width, height = size
    image = create_background(width, height)

    left, top, right, bottom = bounds
    model_width = max(1e-6, right - left)
    model_height = max(1e-6, bottom - top)
    padding_x = max(16, round(width * 0.09))
    padding_top = max(14, round(height * 0.07))
    padding_bottom = max(24, round(height * 0.14))
    scale = min(
        (width - padding_x * 2) / model_width,
        (height - padding_top - padding_bottom) / model_height,
    )
    center_x = (left + right) / 2
    center_y = (top + bottom) / 2
    target_x = width / 2
    target_y = padding_top + (height - padding_top - padding_bottom) / 2

    # A soft grounding shadow helps tiny or sparse objects read as 3D.
    shadow = Image.new("RGBA", image.size, (0, 0, 0, 0))
    shadow_draw = ImageDraw.Draw(shadow, "RGBA")
    shadow_width = min(width * 0.64, max(30, model_width * scale * 0.72))
    shadow_height = min(height * 0.12, max(6, shadow_width * 0.13))
    shadow_y = min(height - padding_bottom * 0.38, target_y + model_height * scale * 0.39)
    shadow_draw.ellipse(
        (
            target_x - shadow_width / 2,
            shadow_y - shadow_height / 2,
            target_x + shadow_width / 2,
            shadow_y + shadow_height / 2,
        ),
        fill=(0, 0, 0, 130),
    )
    shadow = shadow.filter(ImageFilter.GaussianBlur(max(2, round(width / 85))))
    image = Image.alpha_composite(image, shadow)

    draw = ImageDraw.Draw(image, "RGBA")
    projected_unit = scale * math.sqrt(0.5)
    outline_width = 0 if projected_unit < 1.5 else (2 if projected_unit > 18 else 1)
    for face in faces:
        points = [
            (
                target_x + (x - center_x) * scale,
                target_y + (y - center_y) * scale,
            )
            for x, y in face.points
        ]
        draw.polygon(
            points,
            fill=face.color,
            outline=face.outline if outline_width else None,
            width=outline_width,
        )

    destination.parent.mkdir(parents=True, exist_ok=True)
    temporary = destination.with_suffix(destination.suffix + ".tmp")
    image.convert("RGB").save(temporary, format="PNG", optimize=True)
    temporary.replace(destination)
    return len(voxels)


def parse_size(value: str) -> tuple[int, int]:
    try:
        width_text, height_text = value.lower().split("x", 1)
        width, height = int(width_text), int(height_text)
    except (ValueError, TypeError) as error:
        raise argparse.ArgumentTypeError("size must look like 256x192") from error
    if width < 64 or height < 64 or width > 2048 or height > 2048:
        raise argparse.ArgumentTypeError("size must be between 64x64 and 2048x2048")
    return width, height


def find_sources(objects_dir: Path, requested: Iterable[str]) -> list[Path]:
    requested = list(requested)
    if not requested:
        return sorted(
            (
                path
                for path in objects_dir.glob("*.json")
                if path.name != DEFAULT_MANIFEST.name
            ),
            key=lambda path: path.name.casefold(),
        )
    sources = []
    for name in requested:
        candidate = objects_dir / name
        if candidate.suffix.lower() != ".json":
            candidate = candidate.with_suffix(".json")
        if not candidate.is_file():
            raise FileNotFoundError(candidate)
        sources.append(candidate)
    return sources


def write_manifest(
    sources: Sequence[Path],
    objects_dir: Path,
    output_dir: Path,
    manifest_path: Path,
) -> None:
    entries = []
    revision = hashlib.sha256()
    for source in sources:
        preview_path = (output_dir / f"{source.stem}.png").resolve()
        try:
            relative_preview = preview_path.relative_to(objects_dir.resolve())
        except ValueError as error:
            raise ValueError(
                "preview output must be inside the Objects directory "
                "when writing the browser manifest"
            ) from error
        entries.append(
            {
                "file": source.name,
                "label": source.stem,
                "preview": relative_preview.as_posix(),
            }
        )
        revision.update(source.name.encode("utf-8"))
        revision.update(source.read_bytes())
        revision.update(preview_path.read_bytes())

    manifest = {
        "format": "3dpl-object-manifest-v1",
        "revision": revision.hexdigest()[:16],
        "objects": entries,
    }
    manifest_path.parent.mkdir(parents=True, exist_ok=True)
    temporary = manifest_path.with_suffix(manifest_path.suffix + ".tmp")
    temporary.write_text(
        json.dumps(manifest, indent=2) + "\n",
        encoding="utf-8",
    )
    temporary.replace(manifest_path)


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("objects", nargs="*", help="optional JSON filenames to render")
    parser.add_argument("--objects-dir", type=Path, default=DEFAULT_OBJECTS_DIR)
    parser.add_argument("--textures-dir", type=Path, default=DEFAULT_TEXTURES_DIR)
    parser.add_argument("--output-dir", type=Path, default=DEFAULT_OUTPUT_DIR)
    parser.add_argument("--manifest", type=Path, default=DEFAULT_MANIFEST)
    parser.add_argument("--size", type=parse_size, default=(256, 192))
    arguments = parser.parse_args()

    try:
        sources = find_sources(arguments.objects_dir, arguments.objects)
    except FileNotFoundError as error:
        parser.error(f"object file not found: {error}")
    if not sources:
        parser.error(f"no JSON objects found in {arguments.objects_dir}")

    texture_sampler = TextureSampler(arguments.textures_dir)
    failures: list[str] = []
    for source in sources:
        destination = arguments.output_dir / f"{source.stem}.png"
        try:
            count = render_object(
                source, destination, texture_sampler, arguments.size
            )
            print(f"{source.name}: {count} voxels -> {destination.relative_to(ROOT)}")
        except Exception as error:  # Continue so one malformed object is visible.
            failures.append(f"{source.name}: {error}")

    if texture_sampler.missing:
        print(
            "Missing textures: " + ", ".join(sorted(texture_sampler.missing)),
            file=sys.stderr,
        )
    if failures:
        for failure in failures:
            print(f"ERROR: {failure}", file=sys.stderr)
        return 1

    # A partial render should not erase the full browser object library.
    if not arguments.objects:
        try:
            write_manifest(
                sources,
                arguments.objects_dir,
                arguments.output_dir,
                arguments.manifest,
            )
            print(f"Manifest -> {arguments.manifest.relative_to(ROOT)}")
        except Exception as error:
            print(f"ERROR: manifest: {error}", file=sys.stderr)
            return 1
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
