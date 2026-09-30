# Site Agent

![Site Agent for WordPress](site/assets/site-agent-social-centered-1200x630.png)

[Get Site Agent](https://gauravtiwari.org/product/site-agent/) · [Releases](https://github.com/wpgaurav/site-agent/releases) · [Security](SECURITY.md)

Site Agent connects an MCP client directly to WordPress. It provides site context, content and media discovery, content writes, plugin and theme source inspection, source editing, PHP execution, and foreground WP-CLI commands.

Site Agent is free and open source. The complete release ZIP and a free automatic-update license are available from [gauravtiwari.org](https://gauravtiwari.org/product/site-agent/). It runs independently of Functionalities. This repository is the public source; install the release ZIP, which includes the official scoped MCP runtime.

## Requirements and Installation

- WordPress 6.9 or newer; PHP 8.0 or newer.
- HTTPS for remote connections; HTTP is accepted only with `WP_ENVIRONMENT_TYPE=local`.
- Administrator access, or super administrator access on multisite.
- An MCP client with Streamable HTTP and an HTTP Basic Authorization header, or a compatible Application Password bridge.
- WP-CLI in `/usr/local/bin/wp`, `/usr/bin/wp`, or `/opt/homebrew/bin/wp`, and `proc_open`, for the WP-CLI tool only.

Upload the complete ZIP through Plugins → Add New → Upload Plugin. Open Tools → Site Agent. Enable access and select each tool group. Create a dedicated WordPress Application Password in the administrator's profile. Use the endpoint and connection template shown on the settings page.

The settings page includes a browser-only credential converter. Enter your WordPress username and a dedicated Application Password, then copy the Base64 token, complete Authorization value or MCP configuration. Spaced passwords and UTF-8 usernames are supported. Inputs have no form names and are not submitted or stored by Site Agent. Clear credentials after copying; generated values are also cleared when you edit the inputs or navigate away. Your system clipboard retains what you copy until you replace it.

The usual endpoint is `/wp-json/site-agent/v1/mcp`. Sites with plain permalinks use the equivalent `?rest_route=/site-agent/v1/mcp` URL shown in the settings. Encode `username:application-password` as Base64 and pass it in `Authorization: Basic …`. Base64 is not encryption; remote transport requires HTTPS.

All access and write/execution tool groups are off on installation. Deactivation also disables access. Revoke access by disabling Site Agent or deleting the dedicated Application Password. An emergency stop is available in `wp-config.php`:

```php
define( 'SITE_AGENT_DISABLED', true );
```

## Tools

| Tool | Opt-in group | Behavior |
| --- | --- | --- |
| `site-agent-site-context` | Enable Site Agent | WordPress/PHP versions, plugins, theme, REST post types |
| `site-agent-list-content` | Enable Site Agent | Search and paginate accessible content |
| `site-agent-get-content` | Enable Site Agent | Raw content and content hash |
| `site-agent-list-media` | Enable Site Agent | Existing media URLs, MIME types, and alt text |
| `site-agent-save-content` | Content writes | Create drafts or update posts through core APIs |
| `site-agent-list-files` | Source inspection | Browse plugin and theme directories |
| `site-agent-read-file` | Source inspection | Read UTF-8 source with a SHA-256 hash |
| `site-agent-write-file` | Source editing | Compare current hash, check PHP syntax, lock and write |
| `site-agent-execute-php` | PHP execution | Run PHP inside the loaded WordPress request |
| `site-agent-run-wp-cli` | WP-CLI execution | Run argument-array commands on this installation |

Content creates default to drafts. Updates require `expected_content_sha256`; omitted fields are preserved. Core save hooks, revisions, and the author's HTML filtering apply. Content hashes detect intervening changes before a save; they are not a database transaction or a replacement for backups.

File tools operate only under `wp-content/plugins` and `wp-content/themes`. Hidden, configuration, credential, and symlink paths are blocked. Source files may still contain sensitive data, so source inspection is an explicit opt-in. Source editing cannot rewrite Site Agent's own files. File content is bounded to 256 KiB. Writes require `expected_sha256` from a read, or `new` for a new file. The file's current hash is checked under an exclusive lock. PHP syntax is checked before replacing the content. Write failures attempt to restore the previous content. A lock/write is not a transactional deployment, and syntax checking does not prove a change will work.

PHP code is limited to 64 KiB. Output and JSON return values are each limited to 64 KiB. PHP runs in the WordPress process and is **not sandboxed**. Infinite loops, `exit`, memory exhaustion, runtime errors, or changes to output buffers can terminate or disrupt the request. Use it on backed-up development or staging sites. PHP execution, executable file editing, and WP-CLI can each alter other settings or files; the switches control entry points, not isolation boundaries.

WP-CLI runs without a shell, on the current WordPress installation, and as the authenticated user. Identity, connection, and bootstrap override arguments are rejected. Commands have a 20-second foreground limit; stdout/stderr are capped at 64 KiB each. Server-disabled functions and missing WP-CLI return a clear availability error. Detached jobs, descendants created by a command, and arbitrary PHP are not contained by this foreground timeout.

`DISALLOW_FILE_EDIT` and `DISALLOW_FILE_MODS` block source inspection, source editing, PHP execution, and WP-CLI entry points. Every ability checks authenticated administration rights and its group setting again at execution time. Abilities remain private to the Site Agent server, with no public exposure through the default adapter or core REST ability routes.

## Runtime Provenance

All Site Agent application code was written independently. No Novamira source, assets, build scripts, or dependencies are included.

The only bundled third-party runtime packages are the official [WordPress MCP Adapter](https://github.com/WordPress/mcp-adapter) and [WordPress PHP MCP Schema](https://github.com/WordPress/php-mcp-schema), both GPL-2.0-or-later. The adapter is the upcoming **0.7.0 prerelease**, pinned by `composer.lock` to commit `ef6492880f0be9ec501881700a25a18f11953758`. PHP MCP Schema is version 0.2.0. These are not claims that adapter 0.7.0 is released.

`bin/build-runtime.php` copies only those packages' runtime PHP and licenses. It changes their namespaces, adapter-owned hooks/constants, and CLI command name so the bundled adapter can coexist with the canonical plugin. It preserves native WordPress functions/classes and upstream copyright notices. `runtime/manifest.json` records package sources, commits, versions, and licenses. The scoped adapter disables its default discovery server; Site Agent registers a dedicated server with explicitly selected abilities. Future upstream changes may require changes to the scoping procedure.

## Privacy and Updates

Site Agent has no hosted MCP proxy, telemetry or AI provider SDK. Requests go between the configured client and the site. What the client does with tool results is governed by that client/provider's policy. Developer tools can intentionally make outbound requests when instructed to do so.

The optional audit history stores the last 100 calls in a non-autoloaded WordPress option: UTC time, user ID, tool name, success/failure, and elapsed milliseconds. It does not store arguments, output, source content, IP addresses, or credentials. Concurrent calls may overwrite audit rows; this history is an operational aid, not a tamper-proof security log. Clearing data on uninstall is opt-in.

The `Update URI` header prevents an unrelated WordPress.org plugin with a matching slug from replacing Site Agent. Version 0.1.1 adds native WordPress updates through FluentCart product 1180328 on gauravtiwari.org. Complete the free checkout, then activate its update license under Tools → Site Agent. The license controls automatic update delivery only; every tool remains available without activation.

License activation explicitly sends the key, site URL, plugin version, WordPress version and PHP version to the store. Activated licenses contact the store when WordPress checks updates. License keys and activation hashes are encrypted with Sodium, stored without autoload and bound to the site's home URL. Cloned sites and salt changes require their own activation. Failed connection/deactivation requests preserve credentials. The store's [privacy policy](https://gauravtiwari.org/privacy-policy/) applies to that service.

Update metadata is cached for three hours and partitioned by site, credentials and plugin version. A fresh protected URL is fetched before both single and bulk downloads. Packages require HTTPS on the store or its FluentCart R2 host, and their extracted name/version must match the offered Site Agent release. Invalid licenses never receive a package, and older versions are not offered as updates. Manual release ZIP updates remain available. See [Security and Access](#security-and-access) for the trust model.

OAuth, background WP-CLI jobs, an AI chat UI and a recoverable PHP sandbox are outside this version.

## Security and Access

All access starts disabled. Developer tools run with server privileges, so use trusted clients and backups on development or staging sites. Read [SECURITY.md](SECURITY.md) for permission checks, emergency revocation, update trust and private vulnerability reporting.

## Development

```sh
composer install
composer runtime
composer lint
vendor/bin/phpcs
SITE_AGENT_WP_DIR=/path/to/disposable/wordpress vendor/bin/phpunit
bash bin/build.sh
```

Tests refuse to load an installation without a `.site-agent-test-install` marker. Never place that marker on a real site. Integration tests modify disposable options, users, posts, and fixture files. The build uses a runtime allowlist and excludes tests, development dependencies, Composer metadata, docs, and screenshots. Ship `dist/site-agent-0.1.2.zip`, not a GitHub source archive.

## License and Contributions

Site Agent is GPL-2.0-or-later. Original upstream runtime notices and complete licenses are included. See [LICENSE](LICENSE), [runtime provenance](docs/PROVENANCE.md) and [CONTRIBUTING.md](CONTRIBUTING.md). No Novamira application code, assets, scripts or dependencies are included.
