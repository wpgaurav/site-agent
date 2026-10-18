"""Packaging regressions run only against isolated temporary repository copies."""
import contextlib
import hashlib
import io
import json
import os
from pathlib import Path
import runpy
import shutil
import signal
import subprocess
import sys
import tempfile
import unittest
from unittest import mock
import zipfile


ROOT = Path(__file__).resolve().parents[1]
VERSION = '0.5.0'


class BuildArtifactsTest(unittest.TestCase):
    def setUp(self):
        self.directory = tempfile.TemporaryDirectory(prefix='site-agent-build-test-')
        self.addCleanup(self.directory.cleanup)
        self.root = Path(self.directory.name).resolve()
        (self.root / 'bin').mkdir()
        (self.root / 'dist').mkdir()
        for name in ('build.sh', 'build-companion.py'):
            shutil.copy2(ROOT / 'bin' / name, self.root / 'bin' / name)
        (self.root / 'site-agent.php').write_text(f'<?php\n/**\n * Version: {VERSION}\n */\n')
        for name in ('uninstall.php', 'readme.txt', 'LICENSE'):
            (self.root / name).write_text('Fixture ' + name)
        for name in ('assets', 'includes', 'languages', 'runtime', 'skills'):
            (self.root / name).mkdir()
            (self.root / name / 'fixture.txt').write_text(name)
        (self.root / 'includes/class-updater.php').write_text("<?php\nconst PUBLIC_KEY = '';\n")
        (self.root / '.env').write_text('must not be packaged')
        self.commands = self.root / 'commands'
        self.commands.mkdir()
        self.stub('composer', '#!/usr/bin/env bash\nexit 0\n')
        self.environment = {**os.environ, 'PATH': str(self.commands) + os.pathsep + os.environ['PATH']}
        self.environment.pop('SITE_AGENT_SIGNING_KEY', None)
        self.previous_release = self.root / 'dist/site-agent-0.4.2.zip'
        self.previous_release.write_bytes(b'previous release must remain')

    def stub(self, name, content):
        path = self.commands / name
        path.write_text(content)
        path.chmod(0o755)

    def existing(self, companion=False):
        name = 'site-agent-companion' if companion else 'site-agent'
        destination = self.root / 'dist' / f'{name}-{VERSION}.zip'
        destination.write_bytes(b'published same-version ZIP')
        checksum = destination.with_suffix('.zip.sha256')
        checksum.write_bytes(b'published same-version checksum\n')
        return {destination: destination.read_bytes(), checksum: checksum.read_bytes()}

    def assert_preserved(self, originals):
        for path, data in originals.items():
            self.assertEqual(path.read_bytes(), data, str(path))
        self.assertEqual(self.previous_release.read_bytes(), b'previous release must remain')
        self.assertFalse(list((self.root / 'dist').glob('.site-agent-*')))

    def plugin_build(self):
        return subprocess.run(
            ['bash', str(self.root / 'bin/build.sh')], env=self.environment,
            text=True, capture_output=True, check=False, timeout=30,
        )

    def companion_fixture(self):
        shutil.copytree(ROOT / 'companion', self.root / 'companion')
        source = self.root / 'companion/site-agent'
        for name in ('plugin.json', '.codex-plugin/plugin.json'):
            path = source / name
            manifest = json.loads(path.read_text())
            manifest['version'] = VERSION
            path.write_text(json.dumps(manifest))
        return source

    def companion_build(self):
        with contextlib.redirect_stdout(io.StringIO()):
            runpy.run_path(str(self.root / 'bin/build-companion.py'), run_name='__main__')

    def assert_checksum(self, destination):
        expected = hashlib.sha256(destination.read_bytes()).hexdigest()
        self.assertEqual(destination.with_suffix('.zip.sha256').read_text(), f'{expected}  {destination}\n')

    def test_failed_zip_preserves_published_archive_and_checksum(self):
        originals = self.existing()
        self.stub('zip', '#!/usr/bin/env bash\nprintf broken > "$2"\nexit 1\n')
        result = self.plugin_build()
        self.assertNotEqual(result.returncode, 0)
        self.assert_preserved(originals)

    def test_failed_validation_preserves_published_archive_and_checksum(self):
        originals = self.existing()
        self.stub('unzip', '#!/usr/bin/env bash\nexit 1\n')
        result = self.plugin_build()
        self.assertNotEqual(result.returncode, 0)
        self.assert_preserved(originals)

    def test_failed_checksum_preserves_published_archive_and_checksum(self):
        originals = self.existing()
        self.stub('shasum', '#!/usr/bin/env bash\nexit 1\n')
        result = self.plugin_build()
        self.assertNotEqual(result.returncode, 0)
        self.assert_preserved(originals)

    def test_failed_checksum_promotion_restores_both_plugin_artifacts(self):
        originals = self.existing()
        real_mv = shutil.which('mv')
        failure_marker = self.root / 'rename-failed'
        self.stub('mv', f'''#!{sys.executable}
import os
from pathlib import Path
import sys
marker = Path({str(failure_marker)!r})
if sys.argv[-1].endswith('.sha256') and not marker.exists():
    marker.touch()
    sys.exit(1)
os.execv({real_mv!r}, [{real_mv!r}] + sys.argv[1:])
''')
        result = self.plugin_build()
        self.assertNotEqual(result.returncode, 0)
        self.assertTrue(failure_marker.exists())
        self.assert_preserved(originals)

    def test_successful_plugin_build_replaces_pair_and_keeps_allowlist(self):
        self.existing()
        result = self.plugin_build()
        self.assertEqual(result.returncode, 0, result.stderr + result.stdout)
        destination = self.root / 'dist' / f'site-agent-{VERSION}.zip'
        self.assert_checksum(destination)
        with zipfile.ZipFile(destination) as archive:
            self.assertIsNone(archive.testzip())
            self.assertIn('site-agent/site-agent.php', archive.namelist())
            self.assertIn('site-agent/skills/fixture.txt', archive.namelist())
            self.assertNotIn('site-agent/.env', archive.namelist())
            self.assertNotIn('site-agent/bin/build.sh', archive.namelist())
            self.assertTrue(all(name.startswith('site-agent/') for name in archive.namelist()))
        self.assertEqual(self.previous_release.read_bytes(), b'previous release must remain')
        self.assertFalse(list((self.root / 'dist').glob('.site-agent-*')))

    def test_signals_during_plugin_promotion_restore_both_artifacts(self):
        real_mv = shutil.which('mv')
        for number in (signal.SIGTERM, signal.SIGINT, signal.SIGHUP):
            with self.subTest(signal=number.name):
                originals = self.existing()
                failure_marker = self.root / ('signal-after-mv-' + number.name)
                rollback_marker = self.root / ('signal-during-rollback-' + number.name)
                self.stub('mv', f'''#!{sys.executable}
import os
from pathlib import Path
import signal
import subprocess
import sys
subprocess.run([{real_mv!r}] + sys.argv[1:], check=True)
marker = Path({str(failure_marker)!r})
if Path(sys.argv[-2]).name.startswith('previous-'):
    Path({str(rollback_marker)!r}).touch()
    os.kill(os.getppid(), {int(number)})
elif sys.argv[-1].endswith('.zip') and not marker.exists():
    marker.touch()
    os.kill(os.getppid(), {int(number)})
''')
                result = self.plugin_build()
                self.assertEqual(result.returncode, 128 + number, result.stderr + result.stdout)
                self.assertTrue(failure_marker.exists(), 'Signal must follow the first ZIP rename')
                self.assertTrue(rollback_marker.exists(), 'Rollback must tolerate another signal')
                self.assert_preserved(originals)

    def test_invalid_companion_file_preserves_published_archive_and_checksum(self):
        source = self.companion_fixture()
        originals = self.existing(companion=True)
        (source / 'zz-forbidden.log').write_text('must fail while packaging')
        with self.assertRaises(AssertionError):
            self.companion_build()
        self.assert_preserved(originals)

    def test_companion_symlink_preserves_published_archive_and_checksum(self):
        source = self.companion_fixture()
        originals = self.existing(companion=True)
        (source / 'zz-symlink.md').symlink_to(source / 'plugin.json')
        with self.assertRaises(AssertionError):
            self.companion_build()
        self.assert_preserved(originals)

    def test_failed_checksum_promotion_restores_both_companion_artifacts(self):
        self.companion_fixture()
        originals = self.existing(companion=True)
        real_replace = Path.replace
        failed = False

        def replace(path, target):
            nonlocal failed
            if str(target).endswith('.sha256') and not failed:
                failed = True
                raise OSError('simulated checksum promotion failure')
            return real_replace(path, target)

        with mock.patch.object(Path, 'replace', replace), self.assertRaises(OSError):
            self.companion_build()
        self.assertTrue(failed)
        self.assert_preserved(originals)

    def test_successful_companion_build_replaces_archive_and_checksum(self):
        self.companion_fixture()
        self.existing(companion=True)
        self.companion_build()
        destination = self.root / 'dist' / f'site-agent-companion-{VERSION}.zip'
        self.assert_checksum(destination)
        with zipfile.ZipFile(destination) as archive:
            self.assertIsNone(archive.testzip())
            self.assertEqual(json.loads(archive.read('site-agent/plugin.json'))['version'], VERSION)
            self.assertIn('site-agent/LICENSE', archive.namelist())
        self.assertEqual(self.previous_release.read_bytes(), b'previous release must remain')
        self.assertFalse(list((self.root / 'dist').glob('.site-agent-*')))

    def test_signals_during_companion_promotion_restore_both_artifacts(self):
        self.companion_fixture()
        for number in (signal.SIGTERM, signal.SIGINT, signal.SIGHUP):
            with self.subTest(signal=number.name):
                originals = self.existing(companion=True)
                failure_marker = self.root / ('signal-after-replace-' + number.name)
                rollback_marker = self.root / ('signal-during-rollback-' + number.name)
                runner = f'''
import os
from pathlib import Path
import runpy
import signal
replace = Path.replace
def interrupted_replace(path, target):
    result = replace(path, target)
    marker = Path({str(failure_marker)!r})
    if path.name.startswith('previous-'):
        Path({str(rollback_marker)!r}).touch()
        os.kill(os.getpid(), {int(number)})
    elif str(target).endswith('.zip') and not marker.exists():
        marker.touch()
        os.kill(os.getpid(), {int(number)})
    return result
Path.replace = interrupted_replace
runpy.run_path({str(self.root / 'bin/build-companion.py')!r}, run_name='__main__')
'''
                result = subprocess.run(
                    [sys.executable, '-c', runner], text=True, capture_output=True, check=False, timeout=30,
                )
                self.assertEqual(result.returncode, 128 + number, result.stderr + result.stdout)
                self.assertTrue(failure_marker.exists(), 'Signal must follow the first ZIP replacement')
                self.assertTrue(rollback_marker.exists(), 'Rollback must tolerate another signal')
                self.assert_preserved(originals)


if __name__ == '__main__':
    unittest.main()
