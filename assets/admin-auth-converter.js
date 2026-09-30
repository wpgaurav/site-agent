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
			if (button.dataset.siteAgentCopy !== 'token') value = `Basic ${value}`;
			try {
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
