const { test } = require('node:test');
const assert = require('node:assert/strict');
const { readFileSync } = require('node:fs');
const vm = require('node:vm');

function fixture() {
	const elements = new Map();
	function element(id) {
		const listeners = {};
		const node = {
			value: '', type: 'password', hidden: true, textContent: '', dataset: {},
			addEventListener(name, handler) { listeners[name] = handler; },
			setAttribute(name, value) { this[name] = value; },
			focus() {},
			fire(name, event = {}) { return listeners[name]?.(event); },
		};
		elements.set(id, node);
		return node;
	}
	['converter', 'username', 'password', 'token', 'result', 'status', 'show', 'clear'].forEach((key) => element('site-agent-auth-' + key));
	const config = element('site-agent-connection-config');
	config.textContent = JSON.stringify({ mcpServers: { 'site-agent': { url: 'https://example.test/wp-json/site-agent/v1/mcp', headers: { Authorization: 'Basic PLACEHOLDER' } } } });
	const buttons = ['token', 'authorization', 'configuration', 'endpoint'].map((kind) => {
		const button = element(kind); button.dataset.siteAgentCopy = kind; return button;
	});
	elements.get('site-agent-auth-converter').querySelectorAll = () => buttons;
	const events = {};
	const clipboard = [];
	let clipboardWrite = async (value) => clipboard.push(value);
	const window = {
		SiteAgentAuthConverter: { generated: 'Generated', required: 'Required', username: 'Invalid username', copied: 'Copied', copy_failed: 'Manual copy', cleared: 'Cleared', show: 'Show', hide: 'Hide' },
		addEventListener(name, handler) { events[name] = handler; },
	};
	vm.runInNewContext(readFileSync('assets/admin-auth-converter.js', 'utf8'), {
		window, document: { getElementById: (id) => elements.get(id) }, TextEncoder, URL,
		btoa: (value) => Buffer.from(value, 'binary').toString('base64'),
		navigator: { clipboard: { writeText: (value) => clipboardWrite(value) } },
	});
	const get = (key) => elements.get('site-agent-auth-' + key);
	return {
		get, buttons, config, clipboard, events,
		setClipboard(callback) { clipboardWrite = callback; },
		generate(username, password) {
			get('username').value = username; get('password').value = password;
			let prevented = false;
			get('converter').fire('submit', { preventDefault() { prevented = true; }, stopPropagation() {} });
			assert.equal(prevented, true);
		},
	};
}

test('UTF-8 usernames and grouped Application Passwords produce exact Basic credentials', () => {
	const ui = fixture();
	ui.generate(' 用户 ', 'abcd efgh\tijkl mnop qrst uvwx');
	assert.equal(ui.get('token').value, Buffer.from('用户:abcdefghijklmnopqrstuvwx', 'utf8').toString('base64'));
	assert.equal(ui.get('token').type, 'password');
	assert.equal(ui.get('result').hidden, false);
});

test('copy actions produce a token, complete header and site-specific MCP configuration', async () => {
	const ui = fixture(); ui.generate('demo', 'abcd efgh');
	const token = Buffer.from('demo:abcdefgh').toString('base64');
	for (const button of ui.buttons) await button.fire('click');
	assert.equal(ui.clipboard[0], token);
	assert.equal(ui.clipboard[1], 'Basic ' + token);
	const configuration = JSON.parse(ui.clipboard[2]).mcpServers['site-agent'];
	assert.equal(configuration.url, 'https://example.test/wp-json/site-agent/v1/mcp');
	assert.equal(configuration.headers.Authorization, 'Basic ' + token);
	const endpoint = new URL(ui.clipboard[3]);
	assert.equal(endpoint.searchParams.get('auth'), token);
	assert.equal(endpoint.pathname, '/wp-json/site-agent/v1/mcp');
	assert.equal(JSON.parse(ui.config.textContent).mcpServers['site-agent'].headers.Authorization, 'Basic PLACEHOLDER');
});

test('authenticated endpoint preserves plain-permalink query parameters', async () => {
	const ui = fixture();
	ui.config.textContent = JSON.stringify({mcpServers:{'site-agent':{url:'https://example.test/index.php?rest_route=/site-agent/v1/mcp',headers:{}}}});
	ui.generate('demo', 'abcd'); await ui.buttons[3].fire('click');
	const endpoint = new URL(ui.clipboard[0]);
	assert.equal(endpoint.searchParams.get('rest_route'), '/site-agent/v1/mcp');
	assert.equal(endpoint.searchParams.get('auth'), Buffer.from('demo:abcd').toString('base64'));
});

test('edited or cleared credentials invalidate previous tokens and reset visible secrets', () => {
	const ui = fixture(); ui.generate('demo', 'abcd'); ui.get('show').fire('click');
	assert.equal(ui.get('token').type, 'text');
	ui.get('username').fire('input');
	assert.equal(ui.get('token').value, ''); assert.equal(ui.get('result').hidden, true);
	assert.equal(ui.get('token').type, 'password');
	ui.generate('demo', 'abcd'); ui.get('clear').fire('click');
	assert.equal(ui.get('username').value, ''); assert.equal(ui.get('password').value, ''); assert.equal(ui.get('token').value, '');
});

test('missing credentials and ambiguous usernames never generate a token', () => {
	const ui = fixture();
	for (const pair of [['', 'abcd'], ['demo', ' \t '], ['demo:other', 'abcd']]) {
		ui.generate(...pair); assert.equal(ui.get('token').value, ''); assert.equal(ui.get('result').hidden, true);
	}
});

test('clipboard denial offers manual copying and does not expose credentials in status text', async () => {
	const ui = fixture(); ui.generate('demo', 'abcd');
	ui.setClipboard(async () => { throw new Error('Denied'); });
	await ui.buttons[0].fire('click'); assert.equal(ui.get('status').textContent, 'Manual copy');
});

test('navigation clears credentials and stale clipboard promises cannot restore status', async () => {
	const ui = fixture(); ui.generate('demo', 'abcd');
	let complete;
	ui.setClipboard(() => new Promise((resolve) => { complete = resolve; }));
	const pending = ui.buttons[0].fire('click');
	ui.events.pagehide(); complete(); await pending;
	assert.equal(ui.get('password').value, ''); assert.equal(ui.get('token').value, ''); assert.equal(ui.get('status').textContent, '');
	ui.generate('demo', 'abcd'); ui.events.pageshow({ persisted: true });
	assert.equal(ui.get('username').value, ''); assert.equal(ui.get('password').value, '');
});
