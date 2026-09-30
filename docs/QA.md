# Site Agent 0.1.0 local verification

Verified on September 30, 2026. This is local package validation, not a production deployment or public release.

- 14 WordPress integration tests, 63 assertions passed against native WordPress 6.9 and PHP 8.3. The final suite was run against the extracted release ZIP, without Composer's development dependency tree inside the installed plugin.
- The preceding 13-test suite and HTTP checks also passed on WordPress 7.1.2 with PHP 8.3.
- Real HTTP MCP requests verified initialization, ten enabled tools, authenticated PHP execution, WordPress context, strict argument schemas, foreground WP-CLI, and blocked contributor/anonymous session reuse.
- Anonymous callers received HTTP 401; contributor Application Passwords received HTTP 403. Administrator Application Passwords connected successfully.
- The scoped upcoming adapter 0.7.0 runtime coexisted with the canonical MCP Adapter 0.6.1 plugin. Site Agent abilities were not public to canonical default discovery.
- The settings page was opened and visually inspected in a browser. A local screenshot is saved as `admin-preview.jpg`.
- Emergency `SITE_AGENT_DISABLED`, `DISALLOW_FILE_EDIT`, and `DISALLOW_FILE_MODS` guards were tested in separate PHP processes.
- Deactivation revoked the endpoint. Reactivation left Site Agent access disabled.
- PHP 8.0 syntax checks passed for all application and bundled runtime PHP files. PHP 8.0.30 on Alpine with WordPress 6.9 also passed native authorization, input schema validation, and PHP execution checks.
- WordPress coding standards, PHP syntax checks, Composer validation, and dependency security advisory checks passed.
- The translation template was generated. Package inventory and ZIP integrity passed; only the two official WordPress runtime packages and their licenses are bundled. The adapter source pin matches the lock file and runtime manifest.
- Functionalities' uncommitted diff was byte-for-byte identical before and after this work.

The disposable environment used SQLite integration for local testing. A PHP 8.0 Debian image's older SQLite could not read the fixture database's STRICT tables; the minimum-PHP runtime smoke check used Alpine's compatible SQLite. This was a test-environment database limitation, not a Site Agent runtime error.

The installed WP-CLI 2.12 emitted a dependency deprecation notice when launched with the host's PHP 8.5, while its command completed successfully with exit code 0. Site Agent preserves stdout/stderr rather than hiding host diagnostics.

Multisite guards are implemented but a full multisite installation was not exercised. External MCP desktop clients and provider-specific bridges were not tested. OAuth, background jobs, a chat UI, a recoverable PHP sandbox, and an automatic update service are not implemented in this version. PHP execution cannot contain exit, resource exhaustion, or changes to the process; opt-in developer tools require trusted clients and site backups.

No changes were deployed to gauravtiwari.org, no WordPress.org submission occurred, and no upstream release or GitHub repository was published.
