(() => {
	'use strict';
	const form = document.getElementById('site-agent-auth-converter');
	if (!form) return;
	const messages = window.SiteAgentAuthConverter;
	const username = document.getElementById('site-agent-auth-username');
	const password = document.getElementById('site-agent-auth-password');
	const token = document.getElementById('site-agent-auth-token');
	const result = document.getElementById('site-agent-auth-result');
	const status = document.getElementById('site-agent-auth-status');
	const show = document.getElementById('site-agent-auth-show');
	const test = document.getElementById('site-agent-auth-test');
	const configuration = document.getElementById('site-agent-connection-config');
	let revision = 0;
	const reset = () => {
		revision++;
		token.value = '';
		token.type = 'password';
		result.hidden = true;
		show.textContent = messages.show;
		show.setAttribute('aria-pressed', 'false');
		status.textContent = '';
	};
	const endpoint = () => JSON.parse(configuration.textContent).mcpServers['site-agent'].url;
	username.addEventListener('input', reset);
	password.addEventListener('input', reset);
	form.addEventListener('submit', (event) => {
		event.preventDefault();
		event.stopPropagation();
		reset();
		const login = username.value.trim();
		const applicationPassword = password.value.replace(/\s+/g, '');
		if (!login || !applicationPassword) {
			status.textContent = messages.required;
			(!login ? username : password).focus();
			return;
		}
		if (login.includes(':')) {
			status.textContent = messages.username;
			username.focus();
			return;
		}
		const bytes = new TextEncoder().encode(`${login}:${applicationPassword}`);
		token.value = btoa(Array.from(bytes, (byte) => String.fromCharCode(byte)).join(''));
		result.hidden = false;
		status.textContent = messages.generated;
	});
	show.addEventListener('click', () => {
		const visible = token.type === 'password';
		token.type = visible ? 'text' : 'password';
		show.textContent = visible ? messages.hide : messages.show;
		show.setAttribute('aria-pressed', String(visible));
	});
	form.querySelectorAll('[data-site-agent-copy]').forEach((button) => {
		button.addEventListener('click', async () => {
			if (!token.value) return;
			const current = revision;
			let value = token.value;
			if (['authorization', 'configuration'].includes(button.dataset.siteAgentCopy)) value = `Basic ${value}`;
			try {
				if (button.dataset.siteAgentCopy === 'endpoint') {
					const url = new URL(endpoint());
					url.searchParams.set('auth', value);
					value = url.href;
				}
				if (button.dataset.siteAgentCopy === 'configuration') {
					const config = JSON.parse(configuration.textContent);
					config.mcpServers['site-agent'].headers.Authorization = value;
					value = JSON.stringify(config, null, 2);
				}
				await navigator.clipboard.writeText(value);
				if (current === revision) status.textContent = messages.copied;
			} catch {
				if (current === revision) status.textContent = messages.copy_failed;
			}
		});
	});

	// Streamable HTTP replies with JSON or a server-sent event stream.
	const parse = (text) => {
		const data = text.split(/\r?\n/).filter((line) => line.startsWith('data:')).pop();
		try {
			return JSON.parse(data ? data.slice(5) : text);
		} catch {
			return null;
		}
	};
	const rpc = async (url, headers, method, params, session) => {
		const response = await fetch(url, {
			method: 'POST',
			credentials: 'omit',
			cache: 'no-store',
			headers: {
				...headers,
				'Content-Type': 'application/json',
				Accept: 'application/json, text/event-stream',
				...(session ? { 'Mcp-Session-Id': session, 'MCP-Protocol-Version': '2025-11-25' } : {}),
			},
			body: JSON.stringify({ jsonrpc: '2.0', id: method, method, params }),
		});
		return { response, body: parse(await response.text()) };
	};
	// Initialize, count tools, then end the session so tests do not pile up.
	const probe = async (url, headers) => {
		const init = await rpc(url, headers, 'initialize', {
			protocolVersion: '2025-11-25',
			capabilities: {},
			clientInfo: { name: 'site-agent-connection-test', version: '1' },
		});
		if (!init.response.ok) {
			return { ok: false, status: init.response.status, code: init.body?.code ?? '', reason: init.response.headers.get('X-Site-Agent-Auth') ?? '' };
		}
		const session = init.response.headers.get('Mcp-Session-Id');
		const list = await rpc(url, headers, 'tools/list', {}, session);
		if (session) {
			fetch(url, { method: 'DELETE', credentials: 'omit', headers: { ...headers, 'Mcp-Session-Id': session } }).catch(() => {});
		}
		return { ok: list.response.ok, status: list.response.status, tools: list.body?.result?.tools?.length ?? 0 };
	};
	const explain = (outcome) => {
		if (outcome.ok) return messages.test_ok.replace('%d', outcome.tools);
		if (['incorrect_password', 'invalid_username', 'invalid_email'].includes(outcome.code)) return messages.test_credentials;
		if (outcome.code === 'application_passwords_disabled' || outcome.code === 'application_passwords_disabled_for_user') return messages.test_disabled;
		if (outcome.reason === 'unauthenticated') return messages.app_passwords === 'no' ? messages.test_disabled : messages.test_stripped;
		if (outcome.reason === 'not_administrator') return messages.test_administrator;
		if (outcome.reason === 'https_required') return messages.test_https;
		if (outcome.status === 404) return messages.test_missing;
		return messages.test_failed.replace('%d', outcome.status);
	};
	test?.addEventListener('click', async () => {
		if (!token.value || typeof fetch !== 'function') return;
		const current = revision;
		const value = token.value;
		status.textContent = messages.testing;
		let text;
		try {
			const url = endpoint();
			text = explain(await probe(url, { Authorization: `Basic ${value}` }));
			if (messages.url_auth) {
				const authenticated = new URL(url);
				authenticated.searchParams.set('auth', value);
				const outcome = await probe(authenticated.href, {});
				text += ' ' + (outcome.ok ? messages.test_url_ok : messages.test_url_failed + ' ' + explain(outcome));
			}
		} catch {
			text = messages.test_network;
		}
		if (current === revision) status.textContent = text;
	});

	const clear = () => {
		username.value = '';
		password.value = '';
		reset();
	};
	document.getElementById('site-agent-auth-clear').addEventListener('click', () => {
		clear();
		status.textContent = messages.cleared;
		password.focus();
	});
	window.addEventListener('pagehide', clear);
	window.addEventListener('pageshow', (event) => {
		if (event.persisted) clear();
	});
})();
