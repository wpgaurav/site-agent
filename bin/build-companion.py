"""Validate and package the credential-free companion separately from WordPress."""
from pathlib import Path
import hashlib
import json
import re
import shutil
import signal
import struct
import tempfile
import zipfile

root = Path(__file__).resolve().parents[1]
source = root / 'companion/site-agent'
manifest = json.loads((source / 'plugin.json').read_text())
compatibility = json.loads((source / '.codex-plugin/plugin.json').read_text())
version = re.search(r'^ \* Version: (.+)$', (root / 'site-agent.php').read_text(), re.M)[1]
assert manifest['name'] == compatibility['name'] == source.name == 'site-agent'
assert manifest['version'] == compatibility['version'] == version
assert re.fullmatch(r'\d+\.\d+\.\d+', version)
interface = manifest['extensions']['com.openai']['interface']
assert {**interface, 'capabilities': interface.get('capabilities', [])} == {
    **compatibility['interface'],
    'capabilities': compatibility['interface'].get('capabilities', []),
}
assert len(interface['shortDescription']) <= 30
assert set(manifest).isdisjoint({'skills', 'mcpServers', 'apps', 'interface'})

def contained(reference):
    path = (source / reference).resolve()
    assert path.is_relative_to(source.resolve()) and path.exists(), reference
    return path

for key in ['logo', 'composerIcon']:
    icon = contained(interface[key])
    data = icon.read_bytes()
    assert data[:8] == b'\x89PNG\r\n\x1a\n' and len(data) <= 5 * 1024 * 1024
    width, height = struct.unpack('>II', data[16:24])
    assert width == height and 48 <= width <= 4096
contained(compatibility['skills'])
mcp = json.loads(contained(compatibility['mcpServers']).read_text())
assert mcp == json.loads((source / 'mcp.json').read_text())
assert mcp['mcpServers'] == {'site-agent': {
    'type': 'streamable-http',
    'url': 'https://gauravtiwari.org/wp-json/site-agent/v1/mcp',
}}
skills = list((source / 'skills').glob('*/SKILL.md'))
assert skills
for skill in skills:
    text = skill.read_text()
    assert re.search(r'^name: ' + re.escape(skill.parent.name) + r'$', text, re.M)
    for reference in re.findall(r'\]\((references/[^)]+)\)', text):
        assert (skill.parent / reference).resolve().is_relative_to(source.resolve())
        assert (skill.parent / reference).is_file()

destination = root / 'dist' / f'site-agent-companion-{version}.zip'
destination.parent.mkdir(exist_ok=True)
checksum_destination = destination.with_suffix('.zip.sha256')
with tempfile.TemporaryDirectory(prefix='.site-agent-companion-', dir=destination.parent) as directory:
    temporary = Path(directory)
    candidate = temporary / destination.name
    with zipfile.ZipFile(candidate, 'w', zipfile.ZIP_DEFLATED) as archive:
        for path in sorted(source.rglob('*')):
            assert not path.is_symlink()
            if path.is_file():
                assert path.suffix not in {'.env', '.log', '.zip'}
                archive.write(path, 'site-agent/' + path.relative_to(source).as_posix())
        archive.write(root / 'LICENSE', 'site-agent/LICENSE')
    with zipfile.ZipFile(candidate) as archive:
        assert archive.testzip() is None
        assert json.loads(archive.read('site-agent/plugin.json')) == manifest
        file_count = len(archive.namelist())
    checksum = hashlib.sha256(candidate.read_bytes()).hexdigest()
    checksum_candidate = candidate.with_suffix('.zip.sha256')
    checksum_candidate.write_text(f'{checksum}  {destination}\n')
    backups = {}
    for artifact in (destination, checksum_destination):
        backup = temporary / ('previous-' + artifact.name)
        if artifact.exists():
            shutil.copy2(artifact, backup)
            backups[artifact] = backup
    publication_signals = (signal.SIGHUP, signal.SIGINT, signal.SIGTERM)
    previous_handlers = {number: signal.getsignal(number) for number in publication_signals}

    def stop_publication(number, frame):
        # Repeated ordinary signals must not interrupt restoration of the pair.
        for pending in publication_signals:
            signal.signal(pending, signal.SIG_IGN)
        raise SystemExit(128 + number)

    try:
        for number in publication_signals:
            signal.signal(number, stop_publication)
        try:
            candidate.replace(destination)
            checksum_candidate.replace(checksum_destination)
        except BaseException:
            for number in publication_signals:
                signal.signal(number, signal.SIG_IGN)
            for artifact in (destination, checksum_destination):
                if artifact in backups:
                    backups[artifact].replace(artifact)
                else:
                    artifact.unlink(missing_ok=True)
            raise
    finally:
        for number, handler in previous_handlers.items():
            signal.signal(number, handler)
print(f'Validated companion {version}: {len(skills)} skill, {file_count} files.')
print(f'Built {destination.name}, {destination.stat().st_size} bytes, SHA-256 {checksum}')
