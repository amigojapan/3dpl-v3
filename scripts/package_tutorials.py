"""Build an update ZIP containing tutorials 33/34/35/36 and their public assets."""
import json
import re
from pathlib import Path
from zipfile import ZipFile, ZIP_DEFLATED

ROOT = Path(__file__).resolve().parent.parent
PROGRAMS = ('Tutorial 33 Car Simulator', 'finalx3', 'DUNGEON 6', 'Helicopet Flight Simulator 4')
files = {'main.js', '3dplv3.html', 'Skyboxes/sunflowers_puresky_2k.jpg'}
queue = []


def include(path):
    path = path.replace('\\', '/')
    if path in files:
        return
    if not (ROOT / path).is_file():
        raise FileNotFoundError('Required tutorial asset: ' + path)
    files.add(path)
    if path.lower().endswith('.json'):
        queue.append(path)


# These programs use literal filenames, including calls through their object
# helpers. Skip comments so old commented-out assets do not enter the package.
tokens = re.compile(r'//[^\n]*|/\*.*?\*/|"(?:\\.|[^"\\])*"', re.S)
for program in PROGRAMS:
    for part in ('declarations', 'update'):
        path = 'Programs/' + program + '.' + part
        include(path)
        for token in tokens.findall((ROOT / path).read_text()):
            if not token.startswith('"'):
                continue
            value = json.loads(token)
            if value.lower().endswith('.json'):
                matches = [library + '/' + value for library in ('Objects', 'Maps')
                           if (ROOT / library / value).is_file()]
                if not matches:
                    raise FileNotFoundError('Required JSON asset: ' + value)
                for match in matches:
                    include(match)
            elif value.lower().endswith(('.mp3', '.wav', '.ogg')):
                include('Audio/' + value)

while queue:
    path = queue.pop()
    document = json.loads((ROOT / path).read_text())
    entries = document if isinstance(document, list) else document.get('objects', document.get('voxels', []))
    for entry in entries:
        if path.startswith('Maps/') and entry.get('file'):
            include('Objects/' + entry['file'].removeprefix('Objects/'))
        texture = entry.get('TextureName', '').replace('\\', '/').split('/')[-1]
        if texture.lower().endswith(('.png', '.jpg', '.jpeg', '.webp', '.gif')):
            include('Textures/' + texture)

output = ROOT / 'dist' / 'tutorials-33-36.zip'
output.parent.mkdir(exist_ok=True)
instructions = '''3DPL tutorials 33 through 36 update

Extract this archive into your existing 3DPL installation folder, preserving
the folder structure. Replace matching files when prompted.

The updated main.js and 3dplv3.html include tutorial 33 (Car Simulator with
the original Simulator 5 spawn, camera, and audio), tutorial 34 (Supersmash Tokyo),
tutorial 35 (DUNGEON 6), tutorial 36 (Helicopter Flight Simulator 4), and larger
tutorial buttons in a single horizontally scrolling row with space below
the buttons for the scrollbar. DUNGEON 6 alerts "game over" on boarding.
The program files, maps, objects, textures, music, and sky used by these four
games are included. Existing server files and other tutorials stay installed.

Reload the page after copying. If an old page is cached, use Ctrl+Shift+R.

Included files:
''' + '\n'.join(sorted(files)) + '\n'
with ZipFile(output, 'w', ZIP_DEFLATED) as archive:
    for path in sorted(files):
        archive.write(ROOT / path, path)
    archive.writestr('INSTALL-TUTORIALS-33-36.txt', instructions)
with ZipFile(output) as archive:
    assert archive.testzip() is None, 'Archive integrity failed'
print(f'{output}: {len(files)} application/assets files, {output.stat().st_size:,} bytes; ZIP integrity passed.')
