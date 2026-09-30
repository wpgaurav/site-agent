# Site Agent 0.1.3

Includes merged PR #1's companion package, versioned with the WordPress plugin. The companion supplies a WordPress workflow skill, approved icon and credential-free MCP connection. Its compatibility manifest now points to the included `mcp.json`. The archive includes the complete GPL license and is validated by `bin/build-companion.py` in CI.

GitHub publishes two distinct artifacts. `site-agent-0.1.3.zip` is the WordPress installer, 376356 bytes, SHA-256 `b39e2ded697ab480f5f82a8fdd5279e7135408dcdc5b0f83d1d7efff0bd64018`. `site-agent-companion-0.1.3.zip` is the companion archive, 19405 bytes, SHA-256 `cac5d87f6d8c2bc5be173d1e12a80427801ae34da6c6581028d2886dfbaf6e6e`. The companion is excluded from the WordPress installer and contains 10 files.

FluentCart product 1180328 supplies the WordPress update through protected R2 download 326. Previous version 0.1.2 remains as rollback row 325. GitHub, R2 readback and the licensed download matched in bytes and checksum. Invalid activation received no package; WordPress downloaded the valid off-site package while maintenance mode was active. Maintenance was cleared, the synthetic activation disconnected and its verification license disabled. All 3 Site Agent R2 packages have corresponding download rows.

WordPress 0.1.3 is active on gauravtiwari.org. All 282 installed files match the release ZIP. Existing access settings, update credentials, product content, commerce data and unrelated plugins were preserved. A private rollback archive of installed 0.1.2 was retained. Twenty-one public HTTPS endpoint checks passed, including initialization, configured tool discovery, reads, protocol/session handling and refusal of disabled PHP/CLI tools. Temporary credentials were revoked and removed. Public site health and the WordPress installed-plugin version were verified.

Local validation passed 24 integration tests / 106 assertions, 6 converter tests, PHP/JavaScript syntax, coding standards, translation freshness, generated-runtime freshness and both ZIP checks. CI passed PHP 8.0, 8.3 and 8.5.

The authentication model is unchanged. This release does not implement OAuth or prove an authenticated ChatGPT web companion connection. No companion account upload or OpenAI directory submission was performed.
