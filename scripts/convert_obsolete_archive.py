#!/usr/bin/env python3
"""Create a 3DPLv3-compatible mirror of the legacy ``obsolete`` archive.

Legacy ``.obj`` files in this project are trusted BinaryFormatter voxel data,
not Wavefront models. Build ``scripts/legacy_object_converter`` first and pass
its DLL with ``--legacy-converter``. Other assets are copied byte-for-byte.
"""

from __future__ import annotations

import argparse
import hashlib
import json
from pathlib import Path
import re
import shutil
import subprocess
import sys

from convert_xml_objects import convert_xml, encode_json


ROOT = Path(__file__).resolve().parents[1]
DEFAULT_SOURCE = ROOT / "obsolete"
DEFAULT_DESTINATION = ROOT / "updated"

COPY_SUFFIX = " のコピー"
SCRIPT_SUFFIXES = {".declarations", ".update"}

# These names occur in the latest game scripts but were renamed in the asset
# snapshots that survived in the archive. The mappings follow the immediately
# preceding sstmapedit revisions, which use the same placements and models.
LEGACY_OBJECT_ALIASES = {
    "sstmixground.obj": "sstmixbiggrass1.json",
    "sstground.obj": "sstroad1.json",
    "sstcrossground.obj": "sstcrossroad1.json",
    "sstdground.obj": "sstdgrass1.json",
    "sstbground.obj": "sstbgrass1.json",
    "Senouziv.obj": "Senouzi.json",
}


class ArchiveConversionError(RuntimeError):
    """Raised when an archive item cannot be converted safely."""


def sha256(path: Path) -> str:
    digest = hashlib.sha256()
    with path.open("rb") as stream:
        for chunk in iter(lambda: stream.read(1024 * 1024), b""):
            digest.update(chunk)
    return digest.hexdigest()


def legacy_kind(path: Path) -> str | None:
    folded = path.name.casefold()
    if folded.endswith(f".obj{COPY_SUFFIX}".casefold()):
        return "obj-copy"
    if folded.endswith(".obj"):
        return "obj"
    if folded.endswith(f".xml{COPY_SUFFIX}".casefold()):
        return "xml-copy"
    if folded.endswith(".xml"):
        return "xml"
    return None


def converted_relative_path(source: Path, source_root: Path) -> Path:
    relative = source.relative_to(source_root)
    name = relative.name
    kind = legacy_kind(source)
    if kind == "obj-copy":
        output_name = name[: -len(f".obj{COPY_SUFFIX}")] + ".copy.json"
    elif kind == "obj":
        output_name = name[:-4] + ".json"
    elif kind == "xml-copy":
        output_name = name[: -len(f".xml{COPY_SUFFIX}")] + ".xml-copy.json"
    elif kind == "xml":
        stem = name[:-4]
        # Keep both serializations when XML and BinaryFormatter versions sit
        # together. The .obj-derived JSON remains the canonical script target.
        sibling_obj = source.with_name(stem + ".obj")
        output_name = stem + (".xml.json" if sibling_obj.exists() else ".json")
    else:
        output_name = name
    return relative.with_name(output_name)


def replace_random_range(match: re.Match[str]) -> str:
    minimum = match.group(1).strip()
    maximum = match.group(2).strip()
    return f"({minimum} + Math.random() * ({maximum} - {minimum}))"


def replace_color32(match: re.Match[str]) -> str:
    red, green, blue = (int(match.group(index)) for index in range(1, 4))
    return f"0x{red:02x}{green:02x}{blue:02x}"


def strip_function_types(match: re.Match[str]) -> str:
    parameters = re.sub(
        r"\s*:\s*(?:String|float|int|boolean|bool|double)\b",
        "",
        match.group(2),
        flags=re.IGNORECASE,
    )
    return "function" + match.group(1) + parameters + match.group(3)


def convert_script(text: str) -> tuple[str, list[str]]:
    """Translate the UnityScript constructs used by this archive to JS/3DPLv3."""

    changes: list[str] = []
    original = text

    def substitute(pattern: str, replacement: str | object, label: str, flags: int = 0) -> None:
        nonlocal text
        text, count = re.subn(pattern, replacement, text, flags=flags)
        if count:
            changes.append(f"{label}: {count}")

    # Update object filenames first, including names whose source was renamed.
    for legacy_name, current_name in LEGACY_OBJECT_ALIASES.items():
        pattern = rf'(["\']){re.escape(legacy_name)}\1'
        substitute(pattern, lambda match, name=current_name: f'{match.group(1)}{name}{match.group(1)}',
                   f"asset alias {legacy_name} -> {current_name}")
    substitute(
        r'(["\'])([^"\']+)\.obj\1',
        lambda match: f'{match.group(1)}{match.group(2)}.json{match.group(1)}',
        "object extension .obj -> .json",
    )

    # Convert the one UnityScript class used by the dragon programs.
    breath_class = re.compile(
        r"class\s+breath\s*\{.*?function\s+breath\s*\([^)]*name:String[^)]*\)\s*\{.*?\n\s*\}\s*\n\}",
        re.DOTALL,
    )
    replacement_class = """class breath {
    constructor(name, x, y, z) {
        this.x = x || 0;
        this.y = y || 0;
        this.z = z || 0;
        if (name) {
            vars[name] = qb(
                name + "_",
                vars["BreathOrigine"].position.x,
                vars["BreathOrigine"].position.y,
                vars["BreathOrigine"].position.z
            );
        }
    }
}"""
    text, count = breath_class.subn(replacement_class, text)
    if count:
        changes.append(f"UnityScript class -> JavaScript class: {count}")

    # The old scale helper iterated Unity's collection semantics. 3DPLv3 has
    # a direct scale function with the same public arguments.
    scale_helper = re.compile(
        r"function\s+sc_\s*\([^)]*\)\s*\{.*?\n\s*\}\s*\n\}", re.DOTALL
    )
    text, count = scale_helper.subn(
        "function sc_(name, x, y, z) {\n    sc(name, x, y, z);\n}", text
    )
    if count:
        changes.append(f"legacy scale helper -> sc(): {count}")

    substitute(
        r"function(\s+[A-Za-z_$][\w$]*\s*\()([^)]*)(\))",
        strip_function_types,
        "remove UnityScript parameter types",
    )
    substitute(r"(?<![\w.])(\d+(?:\.\d*)?|\.\d+)[fF]\b", r"\1", "remove float suffix")

    # Parent conversion is done before removing .transform so expressions such
    # as vars["dragon_body" + i] are handled more broadly than PreProcessor().
    substitute(
        r'(vars\[([^\]\r\n]+)\])(?:\.gameObject)?\.transform\.parent\s*=\s*'
        r'(vars\[([^\]\r\n]+)\])(?:\.gameObject)?\.transform\s*;',
        lambda match: f"{match.group(3)}.attach({match.group(1)});",
        "parent assignment -> attach()",
    )
    substitute(
        r'(vars\[([^\]\r\n]+)\])(?:\.gameObject)?\.transform\.Rotate\(([^;]+)\);',
        lambda match: (
            "((object, x, y, z) => { "
            "object.rotateX(-x * Math.PI / 180); "
            "object.rotateY(-y * Math.PI / 180); "
            "object.rotateZ(-z * Math.PI / 180); "
            f"}})({match.group(1)}, {match.group(3)});"
        ),
        "Transform.Rotate -> Three.js object rotation",
    )
    substitute(
        r'(vars\[([^\]\r\n]+)\])(?:\.gameObject)?\.transform\.Translate\(([^;]+)\);',
        lambda match: (
            "((object, x, y, z) => { "
            "object.translateX(x); object.translateY(y); object.translateZ(-z); "
            f"}})({match.group(1)}, {match.group(3)});"
        ),
        "Transform.Translate -> Three.js object translation",
    )

    substitute(r"\.gameObject\b", "", "remove .gameObject")
    substitute(r"\.transform\b", "", "remove .transform")
    substitute(r"\.childCount\b", ".children.length", "childCount -> children.length")
    substitute(r"\.GetChild\(([^)]+)\)", r".children[\1]", "GetChild -> children[]")
    substitute(r"\.ToString\s*\(", ".toString(", "ToString -> toString")
    substitute(
        r"UnityEngine\.Object\.Destroy\(([^)]+)\);",
        r"\1.removeFromParent();",
        "Object.Destroy -> removeFromParent()",
    )

    substitute(
        r'Input\.GetAxisRaw\(\s*["\']Vertical["\']\s*\)',
        "(Input.GetKey(KeyCode.UpArrow) ? 1 : Input.GetKey(KeyCode.DownArrow) ? -1 : 0)",
        "Vertical axis -> arrow keys",
    )
    substitute(
        r'Input\.GetAxisRaw\(\s*["\']Horizontal["\']\s*\)',
        "(Input.GetKey(KeyCode.RightArrow) ? 1 : Input.GetKey(KeyCode.LeftArrow) ? -1 : 0)",
        "Horizontal axis -> arrow keys",
    )
    substitute(r"KeyCode\.LeftShift\b", "KeyCode.S", "Left Shift -> onscreen S")
    substitute(r"KeyCode\.P\b", "KeyCode.RightControl", "P action -> onscreen Right Control")
    substitute(r"for\s*\(\s*xx\s*=", "for (let xx =", "declare legacy loop variable")
    substitute(r"(?<![.\w])debugString\b", "window.debugString", "debugString -> window.debugString")

    substitute(r"Mathf\.Deg2Rad\b", "(Math.PI / 180)", "Mathf.Deg2Rad -> radians")
    for legacy, current in (("Sin", "sin"), ("Cos", "cos"), ("Round", "round"), ("PI", "PI")):
        substitute(rf"Mathf\.{legacy}\b", f"Math.{current}", f"Mathf.{legacy} -> Math.{current}")
    substitute(
        r"UnityEngine\.Random\.Range\(\s*([^,()]+),\s*([^()]+)\)",
        replace_random_range,
        "Random.Range -> Math.random",
    )
    substitute(
        r"new\s+Color32\(\s*(\d+)\s*,\s*(\d+)\s*,\s*(\d+)\s*,\s*\d+\s*\)",
        replace_color32,
        "Color32 -> RGB hex",
    )
    substitute(
        r'''parseInt\(System\.DateTime\.Now\.(?:ToString|toString)\(["']hh["']\)\)''',
        "new Date().getHours()",
        "System.DateTime -> Date",
    )
    substitute(
        r'cl\(\s*(["\']BreathOrigine["\'])\s*,\s*new\s+Color\(\s*0\s*,\s*0\s*,\s*0\s*,\s*0\s*\)\s*\);',
        r"cl(\1, Color.black);\nalpha(\1, 0);",
        "transparent Unity Color -> cl()/alpha()",
    )

    # Keep generated files pleasant to diff and accepted by browser editors.
    text = text.replace("\r\n", "\n").replace("\r", "\n")
    if text and not text.endswith("\n"):
        text += "\n"
    if text != original and not changes:
        changes.append("normalized line endings")
    return text, changes


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--source", type=Path, default=DEFAULT_SOURCE)
    parser.add_argument("--destination", type=Path, default=DEFAULT_DESTINATION)
    parser.add_argument(
        "--legacy-converter",
        type=Path,
        required=True,
        help="built LegacyObjectConverter.dll",
    )
    parser.add_argument(
        "--refresh",
        action="store_true",
        help="refresh an existing generated destination in place",
    )
    return parser.parse_args()


def main() -> int:
    args = parse_args()
    source_root = args.source.resolve()
    destination_root = args.destination.resolve()
    converter = args.legacy_converter.resolve()

    if not source_root.is_dir():
        print(f"error: source directory not found: {source_root}", file=sys.stderr)
        return 1
    if destination_root.exists() and not args.refresh:
        print(f"error: destination already exists: {destination_root}", file=sys.stderr)
        return 1
    if not converter.is_file():
        print(f"error: legacy converter not found: {converter}", file=sys.stderr)
        return 1

    sources = sorted((path for path in source_root.rglob("*") if path.is_file()),
                     key=lambda path: (str(path.relative_to(source_root)).casefold(), str(path)))
    entries: list[dict[str, object]] = []
    counts: dict[str, int] = {}

    try:
        for source in sources:
            relative = source.relative_to(source_root)
            kind = legacy_kind(source)
            output_relative = converted_relative_path(source, source_root)
            destination = destination_root / output_relative
            destination.parent.mkdir(parents=True, exist_ok=True)
            details: dict[str, object] = {}

            if kind in {"obj", "obj-copy"}:
                subprocess.run(
                    ["dotnet", str(converter), str(source), str(destination)],
                    check=True,
                    stdout=subprocess.DEVNULL,
                )
                voxels = json.loads(destination.read_text(encoding="utf-8"))
                action = "converted-binary-object"
                details["voxel_count"] = len(voxels)
            elif kind in {"xml", "xml-copy"}:
                voxels, axis_points = convert_xml(source)
                destination.write_text(encode_json(voxels), encoding="utf-8")
                action = "converted-xml-object"
                details.update(voxel_count=len(voxels), omitted_axis_points=axis_points)
            elif source.suffix.casefold() in SCRIPT_SUFFIXES:
                raw = source.read_text(encoding="utf-8-sig", errors="strict")
                converted, changes = convert_script(raw)
                destination.write_text(converted, encoding="utf-8")
                action = "converted-program"
                details["transformations"] = changes
            else:
                shutil.copy2(source, destination)
                action = "copied"

            counts[action] = counts.get(action, 0) + 1
            entries.append(
                {
                    "source": relative.as_posix(),
                    "destination": output_relative.as_posix(),
                    "action": action,
                    "source_sha256": sha256(source),
                    "destination_sha256": sha256(destination),
                    **details,
                }
            )
    except (OSError, ValueError, subprocess.CalledProcessError) as error:
        raise ArchiveConversionError(f"failed while converting {source}: {error}") from error

    manifest = {
        "format": "3DPLv3 archive conversion manifest",
        "source_root": source_root.name,
        "destination_root": destination_root.name,
        "source_file_count": len(sources),
        "output_file_count_excluding_manifest_and_report": len(entries),
        "counts": counts,
        "legacy_object_aliases": LEGACY_OBJECT_ALIASES,
        "files": entries,
    }
    manifest_path = destination_root / "conversion-manifest.json"
    manifest_path.write_text(json.dumps(manifest, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")

    report = f"""# 3DPLv3 archive conversion

This directory mirrors `{source_root.name}/` and preserves its subdirectory structure.

- Source files processed: {len(sources)}
- Binary Unity voxel objects converted to JSON: {counts.get('converted-binary-object', 0)}
- XML voxel objects converted to JSON: {counts.get('converted-xml-object', 0)}
- Unity-style program files converted to 3DPLv3 JavaScript: {counts.get('converted-program', 0)}
- Other assets copied byte-for-byte: {counts.get('copied', 0)}

Every original is accounted for in `conversion-manifest.json`, including hashes and its output path. Files ending in `のコピー` receive distinct `.copy.json` names. Where both `.obj` and `.xml` versions existed, the `.obj` conversion is `name.json` and the XML conversion is `name.xml.json`.

The newest game scripts referenced six renamed or misspelled assets absent under those old names. They were linked to the corresponding models present in the immediately preceding archived revisions; the exact aliases are recorded in the manifest.

To run a converted program, make its referenced JSON objects available through the shared `Objects/` directory or the logged-in user's `server_side/Users/<nick>/Objects/` storage, then load its matching `.declarations` and `.update` files in 3DPL programming mode.
"""
    (destination_root / "README.md").write_text(report, encoding="utf-8")

    print(json.dumps({"processed": len(sources), "counts": counts}, indent=2))
    return 0


if __name__ == "__main__":
    try:
        raise SystemExit(main())
    except ArchiveConversionError as error:
        print(f"error: {error}", file=sys.stderr)
        raise SystemExit(1)
