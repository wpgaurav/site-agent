=== Site Agent ===
Contributors: wpgaurav
Tags: mcp, developer-tools, ai, automation
Requires at least: 6.9
Requires PHP: 8.0
Stable tag: 0.4.2
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Connect an MCP client directly to WordPress with independently enabled developer tools.

== Description ==

Site Agent provides WordPress context, content, term and media discovery, content and media writes, source inspection, source editing, PHP execution, and foreground WP-CLI commands. Bundled builder skills explain to the connected client how to work with Gutenberg, GenerateBlocks, Elementor, Bricks and Divi layouts, and an opt-in Bricks tools group serves Bricks Builder's own abilities (Bricks 2.4+) on the same connection. It is distributed independently through gauravtiwari.org.

Access starts disabled. Open Tools > Site Agent to choose the tool groups, then connect with a dedicated WordPress Application Password. Each password can be limited to some of the enabled groups. Remote requests require HTTPS. Administrators can connect; multisite requires a super administrator. Source/developer tools also respect WordPress's file modification restrictions. The settings page includes connection diagnostics and a Test connection button.

Edits to live posts are staged as autosaves unless a status is passed explicitly. PHP file changes are syntax- and compile-checked, and a change that makes the site fail with a fatal error is reverted.

PHP, executable file editing, and WP-CLI provide full developer access and are not sandboxed. Use developer tools on backed-up development or staging sites. They can change other settings and files even if another tool entry point is disabled.

The upcoming official WordPress MCP Adapter 0.7.0 prerelease is bundled at a reproducible source commit together with PHP MCP Schema 0.2.0. Runtime package versions, source commits, and GPL-compatible licenses are recorded in runtime/manifest.json. Their namespaces and adapter hooks are isolated to avoid conflicts. No Novamira code or dependencies are included.

== Privacy ==

Site Agent has no hosted MCP proxy, telemetry or external AI provider SDK. Your chosen client connects directly to WordPress. Its handling of returned data depends on the client's privacy policy. Developer code/commands can make outbound requests if instructed.

Optional update activation sends the license key, site URL, plugin version, WordPress version and PHP version to the FluentCart store at https://gauravtiwari.org/. Credentials are encrypted with Sodium, stored without autoload and bound to this site. Activated licenses contact the store when WordPress checks for updates. Plugin functionality remains available without activation. Privacy policy: https://gauravtiwari.org/privacy-policy/.

An optional history keeps the last 100 tool calls with the time, user ID, tool name, target (post or attachment ID, file path, WP-CLI command name or imported URL host), Application Password name, result status, and duration. It does not store content, code, other arguments, results, IP addresses, or credential secrets. This history is not a tamper-proof security log. Data removal on uninstall is optional.

== Installation ==

1. Upload the complete release ZIP through Plugins > Add New > Upload Plugin.
2. Activate Site Agent. Access remains disabled.
3. Open Tools > Site Agent, choose tools, and save.
4. Create a dedicated WordPress Application Password in your administrator profile.
5. Configure a Streamable HTTP MCP client with the endpoint shown on the settings page and HTTP Basic authentication.

== Frequently Asked Questions ==

= Is Site Agent a Functionalities module? =
No. It is an independent plugin.

= Does Site Agent support OAuth? =
Yes. From 0.4.1 OAuth connections are on by default whenever Site Agent is enabled. Add the endpoint to an OAuth-capable MCP client without credentials. The client opens a WordPress sign-in and consent page, where an administrator approves it and picks its tool groups. Application Passwords in a header or URL keep working.

= Does this version run background commands? =
No. WP-CLI commands run in the foreground with a 20-second limit by default (SITE_AGENT_WP_CLI_TIMEOUT, up to 300 seconds). PHP execution is not sandboxed.

= WP-CLI is installed but reported as unavailable. =
Define SITE_AGENT_WP_CLI in wp-config.php with the full path to the wp executable. Process execution (proc_open) must also be enabled.

= My client cannot connect. =
Open Tools > Site Agent, check Diagnostics, then generate a token and use Test connection. It reports rejected credentials, a server that strips the Authorization header, missing administrator rights and HTTPS detection problems.

= How do I stop access? =
Disable Site Agent or revoke its Application Password. An emergency SITE_AGENT_DISABLED constant in wp-config.php blocks all access, and SITE_AGENT_ALLOW_EXECUTION set to false blocks only source editing, PHP and WP-CLI. Deactivation also disables access until it is explicitly re-enabled, including on every site of a network.

= How do updates work? =
Get the free checkout license from https://gauravtiwari.org/product/site-agent/ and activate it under Tools > Site Agent. FluentCart supplies automatic updates through protected HTTPS packages. The Update URI protects against unrelated WordPress.org slug matches. Manual release ZIP updates remain available. Licensing never disables the developer tools.

== Changelog ==

= 0.4.2 =
* Fixed: OAuth sign-in failed with "Sorry, you are not allowed to access this page" in clients that add their parameters to the authorization address with a second "?", such as Grok. The advertised authorization endpoint is now `/wp-json/site-agent/v1/oauth/authorize`, which has no query string and forwards to the consent screen, and the old consent address repairs such requests.

= 0.4.1 =
* OAuth connections are on by default. Sites that never changed the setting get them as soon as Site Agent is enabled; a site that turned them off keeps its choice.
* Tools → Site Agent leads with connecting through OAuth and moves Application Passwords to a "not recommended" section with a warning. It also warns when OAuth is off, when URL authentication is on, and when Application Passwords made tool calls in the last 30 days.
* site-context reports how the connection authenticated. For an Application Password or an authenticated URL it carries a warning that agents pass on, asking the user to reconnect with OAuth.
* Tools → Site Agent lists the plugins that give agents their own skill (GT Extensions for FluentCart, GT Page Blocks Builder and GT Link Manager) and shows which are active here.
* Fixed: list-skills showed plugin skill descriptions with their YAML quotes.

= 0.4.0 =
* Add opt-in OAuth 2.1 connections for MCP clients that support the MCP authorization specification: protected resource and authorization server metadata under Site Agent's own issuer path, which leaves the site-root discovery documents to other plugins such as Rank Math, dynamic client registration for public clients, PKCE (S256) authorization codes, and a WordPress consent screen where an administrator approves each client and picks its tool groups.
* OAuth clients get one-hour bearer tokens and rotating refresh tokens that end after 30 days without use. Only token hashes are stored, tokens work on the MCP endpoint only, reusing a rotated refresh token ends the connection, and a demoted administrator's connections stop working. Revoke connections in Tools → Site Agent or through the revocation endpoint.
* Unauthenticated MCP requests now answer with a WWW-Authenticate header that points OAuth clients to discovery when OAuth is on.
* Audit history records OAuth calls with the client name.
* Let other plugins add read-only skills to list-skills and get-skill through the site_agent_skills filter. Bundled builder skills cannot be replaced, and only Markdown, JSON and HTML files are served.

= 0.3.0 =
* Bundle read-only builder skills for Gutenberg, GenerateBlocks, Elementor, Bricks and Divi, with list-skills and get-skill tools, active builders in site-context and the storing builder in get-content.
* Add an opt-in Bricks tools group that serves Bricks 2.4+ abilities on Site Agent's endpoint: Bricks' fast-path tools under their own names, bricks-abilities and run-bricks-ability, with per-password limits, audit history and a PHP execution requirement for Bricks' PHP ability.
* Refuse Elementor, Bricks and Divi layout meta in content writes, because raw writes skip each builder's validation and cache refresh.
* Return the text of the user's autosave from get-content on request, and whether it is newer than the post, so staged edits can be verified and chained.
* Refuse to stage edits to templates and template parts, which core cannot autosave by post ID, with a clear error instead of a REST failure.
* Detect Bricks layouts without an editor mode, GenerateBlocks Pro blocks and GenerateBlocks global styles and conditions.
* Fix the documented default page size of list-terms.

= 0.2.0 =
* Revert PHP file writes, moves and deletes that make the site fail with a fatal error, using WordPress's edit-scrape check; compile-check PHP with a matching PHP binary before writing.
* Report PHP and tool errors with their message and line. Reject exit and die, and report wp_die(), indirect exits and fatal errors during PHP execution as tool errors.
* Stage edits to live posts as autosaves unless a status is passed. Require a future date for scheduling. Keep tag-like text and percent sequences in titles.
* Add terms, featured image, slug, publish date and SEO meta to content writes; read posts by URL or slug; list terms; sort listings by modification date.
* Add media import from a URL or base64 data with alt text, and media metadata updates.
* Add create-directory, delete-file and move-file tools and must-use plugin access. Block credential files by name instead of blocking any path containing "config".
* Limit tools per Application Password, record the target and password in the audit history, and audit denied calls.
* Add diagnostics, a browser connection test and an X-Site-Agent-Auth reason header on refused requests.
* Remove URL credentials from the request before dispatch. Reject WP-CLI @aliases. Add SITE_AGENT_WP_CLI, SITE_AGENT_WP_CLI_TIMEOUT, SITE_AGENT_PHP_BINARY and SITE_AGENT_ALLOW_EXECUTION.
* Describe every tool argument and declare output schemas.
* Use WordPress's Update URI hook for updates, cache failed update checks for 15 minutes, keep validated update metadata when the store is unreachable during an upgrade (so gauravtiwari.org can update itself in maintenance mode), and add signed package verification for use once a release key is configured.
* Disable every site on network deactivation and remove MCP sessions and caches on uninstall.

= 0.1.5 =
* Add inline setup instructions for authenticated MCP URLs, including a site-specific example, credential privacy, revocation and client compatibility.

= 0.1.4 =
* Add opt-in MCP endpoint authentication through the auth query parameter using native Application Password validation.
* Add a browser-only copy action for the authenticated endpoint, including safe URL encoding.
* Keep route, administrator, HTTPS and tool permission checks; reject invalid tokens and mark MCP responses private/no-store.

= 0.1.3 =
* Release an optional companion package with WordPress workflow skills and a credential-free MCP connection.
* Correct the companion compatibility manifest to use its included MCP configuration.
* Preserve the existing WordPress runtime and authentication model.

= 0.1.2 =
* Add a browser-only username and Application Password converter to the connection instructions.
* Copy the Base64 token, complete Authorization value or ready-to-use MCP configuration.
* Keep credentials out of submitted settings and clear generated values on edits or navigation.

= 0.1.1 =
* Add free FluentCart license activation, encrypted site-bound credentials, and native automatic updates.
* Refresh protected package URLs before single/bulk upgrades and validate extracted plugin identity.
* Keep all plugin functionality available without an update license.

= 0.1.0 =
* Initial independently implemented WordPress MCP developer plugin.
* Separate opt-ins for content writes, source inspection, source editing, PHP, and WP-CLI.
* Native WordPress authentication, private abilities, strict schemas, and scoped official MCP runtime.
* File conflict and PHP syntax checks, metadata-only audit history, and emergency disable.
