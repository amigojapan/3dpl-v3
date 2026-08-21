#!/usr/bin/env python3
"""Convert legacy 3DPL XML objects to Obj()-compatible JSON arrays.

Only XML files directly inside ``Objects/`` are converted. Each output keeps
the source basename and replaces its legacy extension with ``.json``. For
example, ``house.xml`` becomes ``house.json`` and the old double-extension
``house.obj.xml`` becomes the cleaner ``house.json``.

The converter validates every source and every destination before writing any
files.  Case-insensitive destination collisions are rejected so a conversion
cannot silently replace another object's output on case-insensitive servers.
Existing differing JSON files require the explicit ``--overwrite`` option.
"""

from __future__ import annotations

import argparse
from decimal import Decimal, InvalidOperation
import json
from pathlib import Path
import sys
import tempfile
import xml.etree.ElementTree as ET


ROOT = Path(__file__).resolve().parents[1]
DEFAULT_OBJECTS_DIR = ROOT / "Objects"

REQUIRED_ATTRIBUTES = (
    "cubename",
    "x",
    "y",
    "z",
    "r",
    "g",
    "b",
    "alpha",
    "TextureName",
    "WrapOnSides",
)
NUMERIC_ATTRIBUTES = ("x", "y", "z", "r", "g", "b", "alpha", "WrapOnSides")


class ConversionError(ValueError):
    """Raised when a legacy object cannot be converted without data loss."""


def local_name(tag: str) -> str:
    """Return an ElementTree tag without an optional XML namespace."""

    return tag.rsplit("}", 1)[-1]


def json_number(text: str, *, source: Path, index: int, attribute: str) -> int | float:
    """Parse an XML number while keeping integral values as JSON integers."""

    try:
        value = Decimal(text)
    except InvalidOperation as error:
        raise ConversionError(
            f'{source.name}: Coordinates #{index} has invalid {attribute}={text!r}'
        ) from error

    if not value.is_finite():
        raise ConversionError(
            f'{source.name}: Coordinates #{index} has non-finite {attribute}={text!r}'
        )

    if value == value.to_integral_value():
        return int(value)
    return float(value)


def convert_xml(source: Path) -> tuple[list[dict[str, object]], int]:
    """Parse one XML file and return its voxels and omitted AxisPoint count."""

    try:
        root = ET.parse(source).getroot()
    except (ET.ParseError, OSError) as error:
        raise ConversionError(f"{source.name}: could not parse XML: {error}") from error

    if local_name(root.tag) != "ArrayOfCoordinates":
        raise ConversionError(
            f"{source.name}: expected ArrayOfCoordinates root, found {local_name(root.tag)}"
        )

    voxels: list[dict[str, object]] = []
    axis_points = 0

    for index, element in enumerate(root, start=1):
        if local_name(element.tag) != "Coordinates":
            raise ConversionError(
                f"{source.name}: unexpected {local_name(element.tag)} element at item #{index}"
            )

        missing = [key for key in REQUIRED_ATTRIBUTES if key not in element.attrib]
        if missing:
            raise ConversionError(
                f"{source.name}: Coordinates #{index} is missing {', '.join(missing)}"
            )

        if element.attrib["cubename"] == "AxisPoint":
            axis_points += 1
            continue

        numbers = {
            key: json_number(
                element.attrib[key], source=source, index=index, attribute=key
            )
            for key in NUMERIC_ATTRIBUTES
        }
        voxels.append(
            {
                "cubename": element.attrib["cubename"],
                "x": numbers["x"],
                "y": numbers["y"],
                "z": numbers["z"],
                "r": numbers["r"],
                "g": numbers["g"],
                "b": numbers["b"],
                "alpha": numbers["alpha"],
                "TextureName": element.attrib["TextureName"],
                "WrapOnSides": numbers["WrapOnSides"],
            }
        )

    return voxels, axis_points


def output_path(source: Path, output_dir: Path) -> Path:
    """Map .xml and legacy .obj.xml sources to a clean JSON filename."""

    name = source.name
    if name.casefold().endswith(".obj.xml"):
        name = name[: -len(".obj.xml")]
    else:
        name = name[: -len(".xml")]
    return output_dir / f"{name}.json"


def encode_json(voxels: list[dict[str, object]]) -> str:
    """Produce stable, human-readable JSON accepted by Obj()."""

    return json.dumps(voxels, indent=2, ensure_ascii=False, allow_nan=False) + "\n"


def atomic_write(destination: Path, content: str) -> None:
    """Replace one destination only after its complete content is on disk."""

    destination.parent.mkdir(parents=True, exist_ok=True)
    with tempfile.NamedTemporaryFile(
        "w", encoding="utf-8", dir=destination.parent, delete=False
    ) as temporary:
        temporary.write(content)
        temporary_path = Path(temporary.name)

    try:
        temporary_path.replace(destination)
    except BaseException:
        temporary_path.unlink(missing_ok=True)
        raise


def find_sources(objects_dir: Path) -> list[Path]:
    """Find root-level XML inputs in deterministic, case-insensitive order."""

    return sorted(
        (
            path
            for path in objects_dir.iterdir()
            if path.is_file() and path.suffix.casefold() == ".xml"
        ),
        key=lambda path: (path.name.casefold(), path.name),
    )


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument(
        "--objects-dir",
        type=Path,
        default=DEFAULT_OBJECTS_DIR,
        help="directory containing root-level XML objects (default: %(default)s)",
    )
    parser.add_argument(
        "--output-dir",
        type=Path,
        help="JSON destination directory (default: the objects directory)",
    )
    parser.add_argument(
        "--overwrite",
        action="store_true",
        help="replace existing JSON when its content differs",
    )
    return parser.parse_args()


def main() -> int:
    args = parse_args()
    objects_dir = args.objects_dir.resolve()
    output_dir = (args.output_dir or objects_dir).resolve()

    if not objects_dir.is_dir():
        print(f"error: objects directory not found: {objects_dir}", file=sys.stderr)
        return 1

    sources = find_sources(objects_dir)
    if not sources:
        print(f"error: no root-level XML files found in {objects_dir}", file=sys.stderr)
        return 1

    destinations: dict[str, list[tuple[Path, Path]]] = {}
    for source in sources:
        destination = output_path(source, output_dir)
        destinations.setdefault(destination.name.casefold(), []).append(
            (source, destination)
        )

    collisions = [pairs for pairs in destinations.values() if len(pairs) > 1]
    if collisions:
        print("error: XML files map to conflicting JSON destinations:", file=sys.stderr)
        for pairs in collisions:
            names = ", ".join(source.name for source, _ in pairs)
            print(f"  {pairs[0][1].name}: {names}", file=sys.stderr)
        return 1

    # Convert every source before writing anything. This makes schema or parse
    # failures all-or-nothing rather than leaving a partially converted set.
    planned: list[tuple[Path, Path, str, int, int]] = []
    try:
        for source in sources:
            destination = output_path(source, output_dir)
            voxels, axis_points = convert_xml(source)
            planned.append(
                (source, destination, encode_json(voxels), len(voxels), axis_points)
            )
    except ConversionError as error:
        print(f"error: {error}", file=sys.stderr)
        return 1

    differing_existing = [
        destination
        for _, destination, content, _, _ in planned
        if destination.exists()
        and destination.read_text(encoding="utf-8") != content
    ]
    if differing_existing and not args.overwrite:
        print(
            "error: these destinations already exist with different content; "
            "rerun with --overwrite:",
            file=sys.stderr,
        )
        for destination in differing_existing:
            print(f"  {destination}", file=sys.stderr)
        return 1

    written = 0
    unchanged = 0
    voxel_total = 0
    axis_total = 0
    for source, destination, content, voxel_count, axis_points in planned:
        voxel_total += voxel_count
        axis_total += axis_points
        if destination.exists() and destination.read_text(encoding="utf-8") == content:
            unchanged += 1
            state = "unchanged"
        else:
            atomic_write(destination, content)
            written += 1
            state = "written"
        print(
            f"{state:9} {source.name} -> {destination.name} "
            f"({voxel_count} voxels, {axis_points} AxisPoint omitted)"
        )

    print(
        f"Converted {len(planned)} XML objects: {written} written, "
        f"{unchanged} unchanged, {voxel_total} voxels, "
        f"{axis_total} AxisPoint records omitted."
    )
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
