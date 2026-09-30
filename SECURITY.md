# Security and Access

Site Agent is a privileged WordPress developer tool. Keep access disabled until an authorized administrator selects tool groups and connects a trusted MCP client. Use a dedicated WordPress Application Password that you can revoke independently.

PHP execution, executable file editing and WP-CLI can alter the database, files and other settings. These are full-privilege entry points and are not sandboxed. A syntax check cannot establish that code is correct or safe. Use a development or staging site with backups for developer tools. Plain HTTP is permitted only when WordPress identifies the environment as local.

Every ability checks authenticated administration rights and its current group setting. Multisite requires a super administrator. File/developer tools additionally respect `DISALLOW_FILE_EDIT`, `DISALLOW_FILE_MODS` and `edit_plugins`. Strict schemas reject unrecognized arguments. Native WordPress auth, nonce and capability checks protect administrator settings and license actions.

Disable MCP access with the settings switch or `define( 'SITE_AGENT_DISABLED', true );` in `wp-config.php`. Revoke the dedicated Application Password in the WordPress profile. Deactivation disables access until it is explicitly re-enabled. The emergency constant applies to MCP access; it does not uninstall the plugin or cancel update licensing.

Source inspection is opt-in and can reveal sensitive data embedded in source. File tools reject hidden, configuration, credential and symlink paths, but this is not a universal secret detector. File writes require the current hash, check PHP syntax and lock the target. Source editing cannot rewrite Site Agent itself; raw PHP and WP-CLI can.

License keys and activation hashes are encrypted with Sodium using WordPress salts, stored without autoload and bound to the site's home URL. Cloned sites and changed salts require separate activation. Update packages are limited to HTTPS on the configured store or its protected FluentCart R2 host. Metadata is refreshed before single/bulk downloads, and the extracted plugin name and version are verified. The store is a trusted update authority; signed release-manifest verification is not implemented.

The optional audit history records call metadata only. It is neither immutable nor tamper-proof, and concurrent calls may overwrite rows. Site Agent does not contain PHP `exit`, infinite loops, resource exhaustion, output-buffer changes or detached subprocesses.

Report potential vulnerabilities privately through GitHub's private vulnerability reporting for this repository. Do not post credentials, exploit payloads, signed URLs or private site data in public issues. If private reporting is unavailable, use the contact route on the product website before sharing details.
