=== Site Agent ===
Contributors: wpgaurav
Tags: mcp, developer-tools, ai, automation
Requires at least: 6.9
Requires PHP: 8.0
Stable tag: 0.1.5
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Connect an MCP client directly to WordPress with independently enabled developer tools.

== Description ==

Site Agent provides WordPress context, content and media discovery, content writes, source inspection, source editing, PHP execution, and foreground WP-CLI commands. It is distributed independently through gauravtiwari.org.

Access starts disabled. Open Tools > Site Agent to choose the tool groups, then connect with a dedicated WordPress Application Password. Remote requests require HTTPS. Administrators can connect; multisite requires a super administrator. Source/developer tools also respect WordPress's file modification restrictions.

PHP, executable file editing, and WP-CLI provide full developer access and are not sandboxed. Use developer tools on backed-up development or staging sites. They can change other settings and files even if another tool entry point is disabled.

The upcoming official WordPress MCP Adapter 0.7.0 prerelease is bundled at a reproducible source commit together with PHP MCP Schema 0.2.0. Runtime package versions, source commits, and GPL-compatible licenses are recorded in runtime/manifest.json. Their namespaces and adapter hooks are isolated to avoid conflicts. No Novamira code or dependencies are included.

== Privacy ==

Site Agent has no hosted MCP proxy, telemetry or external AI provider SDK. Your chosen client connects directly to WordPress. Its handling of returned data depends on the client's privacy policy. Developer code/commands can make outbound requests if instructed.

Optional update activation sends the license key, site URL, plugin version, WordPress version and PHP version to the FluentCart store at https://gauravtiwari.org/. Credentials are encrypted with Sodium, stored without autoload and bound to this site. Activated licenses contact the store when WordPress checks for updates. Plugin functionality remains available without activation. Privacy policy: https://gauravtiwari.org/privacy-policy/.

An optional history keeps the last 100 tool calls with the time, user ID, tool name, result status, and duration. It does not store code, arguments, results, IP addresses, or credentials. This history is not a tamper-proof security log. Data removal on uninstall is optional.

== Installation ==

1. Upload the complete release ZIP through Plugins > Add New > Upload Plugin.
2. Activate Site Agent. Access remains disabled.
3. Open Tools > Site Agent, choose tools, and save.
4. Create a dedicated WordPress Application Password in your administrator profile.
5. Configure a Streamable HTTP MCP client with the endpoint shown on the settings page and HTTP Basic authentication.

== Frequently Asked Questions ==

= Is Site Agent a Functionalities module? =
No. It is an independent plugin.

= Does this version provide OAuth or background commands? =
No. Authentication uses WordPress Application Passwords. WP-CLI commands run in the foreground with a 20-second limit. PHP execution is not sandboxed.

= How do I stop access? =
Disable Site Agent or revoke its Application Password. An emergency SITE_AGENT_DISABLED constant in wp-config.php blocks all access. Deactivation also disables access until it is explicitly re-enabled.

= How do updates work? =
Get the free checkout license from https://gauravtiwari.org/product/site-agent/ and activate it under Tools > Site Agent. FluentCart supplies automatic updates through protected HTTPS packages. The Update URI protects against unrelated WordPress.org slug matches. Manual release ZIP updates remain available. Licensing never disables the developer tools.

== Changelog ==

= 0.1.5 =
* Add inline setup instructions for authenticated MCP URLs, including a site-specific example, credential privacy, revocation and client compatibility.

= 0.1.4 =
* Add opt-in MCP endpoint authentication through the auth query parameter using native Application Password validation.
* Add a browser-only copy action for the authenticated endpoint, including safe URL encoding.
* Keep route, administrator, HTTPS and tool permission checks; reject invalid tokens and mark MCP responses private/no-store.

= 0.1.3 =
* Release an optional companion package with WordPress workflow skills and a credential-free MCP connection.
* Correct the companion compatibility manifest to use its included MCP configuration.
* Preserve the existing WordPress runtime and authentication model; OAuth is not added.

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
