"""Run the HTTP smoke script against an in-memory MCP transport, never a site."""
import base64
import contextlib
import hashlib
import io
import json
import os
from pathlib import Path
import runpy
import tempfile
import unittest
from unittest import mock
import urllib.error


ROOT = Path(__file__).resolve().parents[1]
BUILTINS = [
    'gutenberg', 'generateblocks', 'elementor', 'bricks', 'divi', 'mailerhive',
    'core-support', 'fluentcart', 'gt-extensions-fluentcart', 'stackless', 'page-blocks-builder',
]
TOOLS = [
    'site-context', 'list-content', 'get-content', 'list-terms', 'list-media', 'list-skills',
    'get-skill', 'save-content', 'upload-media', 'update-media', 'list-files', 'read-file',
    'write-file', 'create-directory', 'delete-file', 'move-file', 'execute-php', 'run-wp-cli',
]
CLI_UNAVAILABLE = 'WP-CLI and process execution must be available on the server. Set SITE_AGENT_WP_CLI to the wp executable if it is installed elsewhere.'


class Response:
    status = 200
    headers = {'Mcp-Session-Id': 'isolated-smoke-test'}

    def __init__(self, body):
        self.body = body

    def __enter__(self):
        return self

    def __exit__(self, *args):
        return False

    def read(self):
        return json.dumps(self.body).encode()


class SmokeTransport:
    def __init__(self, wordpress, marker):
        self.proof = {
            'wordpress_directory': str(wordpress.resolve()),
            'test_install': True,
            'marker_sha256': hashlib.sha256(marker.read_bytes()).hexdigest(),
        }
        self.skills = BUILTINS + ['registered-plugin']
        self.calls = []
        self.guards = []
        self.mutations = []
        self.read_skills = []
        self.bad_skill = None
        self.guard_error = False
        self.cli_runtime = {'process_available': True, 'cli_available': True, 'unavailable_message': CLI_UNAVAILABLE}
        self.cli_error_message = None
        self.cli_exit_code = 0

    def __call__(self, request, timeout):
        call = json.loads(request.data)
        self.calls.append(call)
        authorization = request.get_header('Authorization', '')
        admin = 'Basic ' + base64.b64encode(b'admin:admin-password').decode()
        if authorization != admin:
            status = 403 if authorization else 401
            raise urllib.error.HTTPError(
                request.full_url, status, 'Denied', {'X-Site-Agent-Auth': 'unauthenticated'},
                io.BytesIO(json.dumps({'error': 'Denied'}).encode()),
            )
        method, params = call['method'], call['params']
        if method == 'initialize':
            return Response({'result': {}})
        if method == 'tools/list':
            return Response({'result': {'tools': [
                {'name': 'site-agent-' + name, 'inputSchema': {'type': 'object'}} for name in TOOLS
            ]}})
        assert method == 'tools/call', method
        name, args = params['name'].removeprefix('site-agent-'), params['arguments']
        result = {'content': [{'type': 'text', 'text': 'ok'}]}
        if name == 'execute-php':
            if '.site-agent-test-install' in args['code']:
                self.guards.append(call)
                result['isError'] = self.guard_error
                result['structuredContent'] = {'return_value': self.proof}
            elif 'process_available' in args['code']:
                result['structuredContent'] = {'return_value': self.cli_runtime}
            elif 'post_id' in args:
                result['isError'] = True
        elif name == 'run-wp-cli':
            if self.cli_error_message or not all(self.cli_runtime[key] for key in ('process_available', 'cli_available')):
                result['isError'] = True
                result['content'][0]['text'] = self.cli_error_message or CLI_UNAVAILABLE
            else:
                result['structuredContent'] = {'exit_code': self.cli_exit_code, 'stdout': '6.9\n'}
        elif name == 'list-skills':
            result['structuredContent'] = {'skills': [
                {
                    'name': skill, 'source': 'site-agent' if skill in BUILTINS else 'Fixture Plugin',
                    'description': 'A useful guide', 'files': ['SKILL.md'],
                } for skill in self.skills
            ]}
        elif name == 'get-skill':
            if args.get('path', '').startswith('../'):
                result['isError'] = True
            else:
                self.read_skills.append(args['skill'])
                content = f"---\nname: {args['skill']}\ndescription: A useful guide\n---\n\n# Guide\n"
                if args['skill'] == self.bad_skill:
                    content = '---\nname: wrong-guide\n---\n'
                result['structuredContent'] = {
                    'skill': args['skill'], 'path': 'SKILL.md', 'content': content, 'files': ['SKILL.md'],
                }
        elif name in ('create-directory', 'write-file', 'delete-file'):
            self.mutations.append(call)
            if name == 'write-file':
                if 'undefined_function' in args['content']:
                    result['isError'] = True
                    result['content'][0]['text'] = 'Fatal change reverted'
                else:
                    result['structuredContent'] = {'checks': {'health': 'ok'}, 'sha256': 'fixture-hash'}
        return Response({'result': result})


class HttpSmokeTest(unittest.TestCase):
    def setUp(self):
        self.directory = tempfile.TemporaryDirectory(prefix='site-agent-http-test-')
        self.addCleanup(self.directory.cleanup)
        self.wordpress = Path(self.directory.name) / 'wordpress'
        self.wordpress.mkdir()
        self.marker = self.wordpress / '.site-agent-test-install'
        self.marker.write_text('isolated disposable HTTP smoke fixture\n')
        credentials = Path(self.directory.name) / 'credentials.json'
        credentials.write_text(json.dumps({
            'admin': {'username': 'admin', 'password': 'admin-password'},
            'contributor': {'username': 'contributor', 'password': 'contributor-password'},
        }))
        self.environment = {
            'SITE_AGENT_HTTP_URL': 'http://127.0.0.1:8943/wp-json/site-agent/v1/mcp',
            'SITE_AGENT_WP_DIR': str(self.wordpress),
            'SITE_AGENT_TEST_CREDENTIALS': str(credentials),
        }
        self.transport = SmokeTransport(self.wordpress, self.marker)

    def run_smoke(self, optimized=False):
        with mock.patch.dict(os.environ, self.environment), mock.patch(
            'urllib.request.urlopen', self.transport,
        ), contextlib.redirect_stdout(io.StringIO()) as output:
            script = ROOT / 'tests/http-smoke.py'
            if optimized:
                exec(compile(script.read_text(), str(script), 'exec', optimize=2), {
                    '__name__': '__main__', '__file__': str(script),
                })
            else:
                runpy.run_path(str(script), run_name='__main__')
        return output.getvalue()

    def test_missing_fixture_directory_refuses_all_http_requests(self):
        self.environment['SITE_AGENT_WP_DIR'] = ''
        with self.assertRaisesRegex(RuntimeError, 'SITE_AGENT_WP_DIR'):
            self.run_smoke()
        self.assertEqual(self.transport.calls, [])

    def test_unmarked_local_fixture_refuses_all_http_requests(self):
        self.marker.unlink()
        with self.assertRaisesRegex(RuntimeError, 'marked with .site-agent-test-install'):
            self.run_smoke()
        self.assertEqual(self.transport.calls, [])

    def test_nonlocal_endpoint_refuses_all_http_requests(self):
        self.environment['SITE_AGENT_HTTP_URL'] = 'https://example.test/wp-json/site-agent/v1/mcp'
        with self.assertRaisesRegex(RuntimeError, 'require localhost'):
            self.run_smoke()
        self.assertEqual(self.transport.calls, [])

    def assert_target_rejected(self):
        with self.assertRaisesRegex(RuntimeError, 'HTTP target'):
            self.run_smoke()
        self.assertEqual(len(self.transport.guards), 1)
        self.assertEqual(self.transport.mutations, [])
        tools = [call['params']['name'] for call in self.transport.calls if call['method'] == 'tools/call']
        self.assertEqual(tools, ['site-agent-execute-php'])

    def test_unmarked_target_refuses_mutations_even_at_localhost(self):
        self.transport.proof['test_install'] = False
        self.assert_target_rejected()

    def test_different_wordpress_target_refuses_mutations(self):
        self.transport.proof['wordpress_directory'] = '/another/wordpress'
        self.assert_target_rejected()

    def test_different_marker_refuses_mutations(self):
        self.transport.proof['marker_sha256'] = '0' * 64
        self.assert_target_rejected()

    def test_failed_marker_probe_refuses_mutations(self):
        self.transport.guard_error = True
        with self.assertRaisesRegex(RuntimeError, 'Could not verify the disposable HTTP target'):
            self.run_smoke()
        self.assertEqual(self.transport.mutations, [])

    def test_optimized_python_still_requires_local_marker(self):
        self.marker.unlink()
        with self.assertRaisesRegex(RuntimeError, 'marked with .site-agent-test-install'):
            self.run_smoke(optimized=True)
        self.assertEqual(self.transport.calls, [])

    def test_optimized_python_still_requires_target_marker(self):
        self.transport.proof['test_install'] = False
        with self.assertRaisesRegex(RuntimeError, 'HTTP target'):
            self.run_smoke(optimized=True)
        self.assertEqual(self.transport.mutations, [])

    def test_all_builtins_and_plugin_skills_pass_and_are_read(self):
        output = self.run_smoke()
        self.assertIn('All HTTP MCP smoke checks passed.', output)
        self.assertIn('11 built-in skills over MCP, with 1 plugin-registered skills', output)
        self.assertEqual(self.transport.read_skills, BUILTINS)
        self.assertEqual(len(self.transport.guards), 1)
        self.assertEqual(len(self.transport.mutations), 4)
        self.assertIn('PASS bounded WP-CLI command as authenticated administrator', output)
        self.assertNotIn('SKIP native WP-CLI execution', output)

    def test_unavailable_cli_binary_checks_documented_error_and_continues(self):
        self.transport.cli_runtime['cli_available'] = False
        output = self.run_smoke()
        self.assertIn('PASS WP-CLI returns its documented cli_unavailable error', output)
        self.assertIn('SKIP native WP-CLI execution: server lacks WP-CLI binary', output)
        self.assertIn('HTTP MCP smoke checks passed with native WP-CLI execution skipped:', output)
        self.assertNotIn('All HTTP MCP smoke checks passed.', output)
        self.assertEqual(self.transport.read_skills, BUILTINS)
        self.assertEqual(len(self.transport.mutations), 4)

    def test_unavailable_process_checks_documented_error_and_continues(self):
        self.transport.cli_runtime['process_available'] = False
        output = self.run_smoke()
        self.assertIn('SKIP native WP-CLI execution: server lacks process execution', output)
        self.assertEqual(self.transport.read_skills, BUILTINS)
        self.assertEqual(len(self.transport.mutations), 4)

    def test_unavailable_cli_does_not_accept_unrelated_tool_errors(self):
        self.transport.cli_runtime['cli_available'] = False
        self.transport.cli_error_message = 'Permission denied'
        with self.assertRaises(AssertionError):
            self.run_smoke()
        self.assertEqual(self.transport.mutations, [])

    def test_available_cli_does_not_skip_command_errors(self):
        self.transport.cli_error_message = 'The WP-CLI process could not start.'
        with self.assertRaises(AssertionError):
            self.run_smoke()
        self.assertEqual(self.transport.mutations, [])

    def test_available_cli_requires_successful_exit(self):
        self.transport.cli_exit_code = 1
        with self.assertRaises(AssertionError):
            self.run_smoke()
        self.assertEqual(self.transport.mutations, [])

    def test_missing_new_builtin_fails_before_mutations(self):
        self.transport.skills.remove('page-blocks-builder')
        with self.assertRaisesRegex(AssertionError, 'Missing built-in skills'):
            self.run_smoke()
        self.assertEqual(self.transport.mutations, [])

    def test_wrong_guide_content_fails_before_mutations(self):
        self.transport.bad_skill = 'core-support'
        with self.assertRaises(AssertionError):
            self.run_smoke()
        self.assertIn('core-support', self.transport.read_skills)
        self.assertEqual(self.transport.mutations, [])


if __name__ == '__main__':
    unittest.main()
