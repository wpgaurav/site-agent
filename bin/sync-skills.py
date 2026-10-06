"""Import pinned builder skill files and validate the bundled skills.

    python3 bin/sync-skills.py          # offline validation (CI)
    python3 bin/sync-skills.py --pull   # re-import upstream files at the commits in skills/sources.json

To move a source forward, change its commit in skills/sources.json, run --pull,
review the diff and adapt the locally authored SKILL.md files where needed.
"""
from pathlib import Path
import fnmatch
import hashlib
import json
import re
import subprocess
import sys
import tempfile

root = Path(__file__).resolve().parents[1]
skills = root / 'skills'
manifest_path = skills / 'sources.json'
manifest = json.loads(manifest_path.read_text())

# Upstream path patterns copied verbatim, with their bundled destination.
IMPORTS = [
    ('bricks-skills', 'references/*.md', 'bricks/references'),
    ('bricks-skills', 'patterns/*.json', 'bricks/patterns'),
    ('bricks-skills', 'patterns/INDEX.md', 'bricks/patterns'),
    ('generateblocks-skills', 'skills/generateblocks-layouts/references/*.md', 'generateblocks/references'),
    ('generateblocks-skills', 'skills/generateblocks-layouts/examples/basic/*.html', 'generateblocks/examples/basic'),
    ('generateblocks-skills', 'skills/generateblocks-layouts/examples/compound/*.html', 'generateblocks/examples/compound'),
    ('generateblocks-skills', 'skills/generateblocks-layouts/examples/layouts/*.html', 'generateblocks/examples/layouts'),
    ('generateblocks-skills', 'skills/generateblocks-layouts/examples/svg/*.html', 'generateblocks/examples/svg'),
    ('WordPress-skills', 'skills/wordpress/wp-block-markup/SKILL.md', 'gutenberg/references/block-markup.md'),
]
# Upstream files replaced by a Site Agent version with the same name, so upstream links still resolve.
LOCAL_OVERRIDES = {'generateblocks/references/mcp-publishing.md'}
# Must match Skills::MAX_BYTES and Skills::EXTENSIONS in includes/class-skills.php.
MAX_BYTES = 65536
EXTENSIONS = {'.md', '.json', '.html'}
CATALOG = ['gutenberg', 'generateblocks', 'elementor', 'bricks', 'divi']
FORBIDDEN = re.compile(r'novamira|wpvibe|respira', re.I)
SEGMENT = re.compile(r'^[a-z0-9_][a-z0-9._-]*$', re.I)  # Same rule as Skills::PATH.


def sha256(path):
    return hashlib.sha256(path.read_bytes()).hexdigest()


def pull():
    files = {}
    with tempfile.TemporaryDirectory() as temporary:
        for name, source in manifest['sources'].items():
            checkout = Path(temporary) / name
            checkout.mkdir()
            run = lambda *args: subprocess.run(['git', '-C', str(checkout), *args], check=True, capture_output=True)
            run('init', '-q')
            run('fetch', '-q', '--depth', '1', source['repository'], source['commit'])
            run('checkout', '-q', 'FETCH_HEAD')
            for source_name, pattern, destination in IMPORTS:
                if source_name != name:
                    continue
                matches = sorted(p for p in checkout.rglob('*') if p.is_file() and fnmatch.fnmatch(p.relative_to(checkout).as_posix(), pattern))
                assert matches, f'{name}: nothing matches {pattern}'
                for match in matches:
                    assert not match.is_symlink()
                    target = destination if destination.endswith(tuple(EXTENSIONS)) else f'{destination}/{match.name}'
                    if target in LOCAL_OVERRIDES:
                        continue
                    (skills / target).parent.mkdir(parents=True, exist_ok=True)
                    (skills / target).write_bytes(match.read_bytes())
                    files[target] = {'source': name, 'path': match.relative_to(checkout).as_posix(), 'sha256': sha256(skills / target)}
    for stale in set(manifest.get('files', {})) - set(files):
        (skills / stale).unlink(missing_ok=True)
    manifest['files'] = dict(sorted(files.items()))
    manifest_path.write_text(json.dumps(manifest, indent=2) + '\n')
    print(f'Imported {len(files)} files.')


def frontmatter(text):
    match = re.match(r'^---\n(.*?)\n---\n', text, re.S)
    assert match, 'missing frontmatter'
    return dict(re.findall(r'^([a-z]+): (.+)$', match[1], re.M))


def bricks_tree(path, data):
    # Clipboard files keep elements in content; template exports under their type (header, footer, content).
    content = data.get('content') or data.get(data.get('type', ''))
    assert isinstance(content, list) and content, f'{path}: no elements'
    nodes = {node['id']: node for node in content}
    assert len(nodes) == len(content), f'{path}: duplicate ids'
    for node in content:
        for child in node.get('children', []):
            assert nodes.get(child, {}).get('parent') == node['id'], f'{path}: {node["id"]} -> {child} is not reciprocal'
        parent = node.get('parent', 0)
        assert parent in (0, '0') or node['id'] in nodes[parent].get('children', []), f'{path}: orphan {node["id"]}'
    classes = {item['id'] for item in data.get('globalClasses', data.get('global_classes', []))}
    for node in content:
        for class_id in node.get('settings', {}).get('_cssGlobalClasses', []):
            assert class_id in classes, f'{path}: missing global class {class_id}'


def check():
    recorded = manifest.get('files', {})
    for target, entry in recorded.items():
        path = skills / target
        assert path.is_file(), f'missing imported file {target}; run --pull'
        assert sha256(path) == entry['sha256'], f'{target} differs from {entry["source"]}@{manifest["sources"][entry["source"]]["commit"][:12]}'
    directories = sorted(p.name for p in skills.iterdir() if p.is_dir())
    assert directories == sorted(CATALOG), f'skill directories {directories} do not match the catalog'
    count = 0
    for path in sorted(skills.rglob('*')):
        relative = path.relative_to(skills).as_posix()
        assert not path.is_symlink(), relative
        if path.is_dir() or path.parent == skills:
            continue
        count += 1
        assert all(SEGMENT.match(part) for part in relative.split('/')), f'unsafe name {relative}'
        assert path.suffix in EXTENSIONS, f'unexpected file type {relative}'
        assert path.stat().st_size <= MAX_BYTES, f'{relative} is over {MAX_BYTES} bytes'
        text = path.read_text(encoding='utf-8')
        assert not FORBIDDEN.search(text), f'{relative} names a product that must not be bundled'
        if path.suffix == '.json':
            data = json.loads(text)
            if relative.startswith('bricks/patterns/'):
                bricks_tree(relative, data)
        local = relative not in recorded
        if path.suffix == '.md' and local:
            # Locally authored files must link only to bundled files.
            for link in re.findall(r'\]\(([^)#\s]+)(?:#[^)]*)?\)', text):
                if re.match(r'^[a-z]+:', link):
                    continue
                target = (path.parent / link).resolve()
                assert target.is_relative_to(skills.resolve()) and target.exists(), f'{relative} links to missing {link}'
            assert '—' not in text, f'{relative} contains an em dash'
    for name in CATALOG:
        meta = frontmatter((skills / name / 'SKILL.md').read_text())
        assert meta.get('name') == name, f'{name}/SKILL.md name must be {name}'
        assert 40 <= len(meta.get('description', '')) <= 1024, f'{name}/SKILL.md needs a description'
    print(f'Validated {len(CATALOG)} skills, {count} files, {len(recorded)} pinned imports.')


if __name__ == '__main__':
    if '--pull' in sys.argv[1:]:
        pull()
    check()
