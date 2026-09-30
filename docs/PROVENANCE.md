# Source inventory

Site Agent application files (`site-agent.php`, `uninstall.php`, `includes/`, `bin/`, and `tests/`) were authored independently for this plugin. No Novamira code, asset, build script, or dependency was copied or adapted into these files.

Runtime dependencies:

| Package | Version | Source | License |
| --- | --- | --- | --- |
| WordPress MCP Adapter | 0.7.0 prerelease, `dev-trunk` locked at `ef6492880f0be9ec501881700a25a18f11953758` | https://github.com/WordPress/mcp-adapter | GPL-2.0-or-later |
| WordPress PHP MCP Schema | 0.2.0, commit recorded in manifest | https://github.com/WordPress/php-mcp-schema | GPL-2.0-or-later |

The build inputs are exactly these two packages' PHP runtime trees and their LICENSE files. Composer's development packages and Jetpack autoloader are not shipped. Original copyright notices and complete runtime licenses are included. Namespace, adapter hook, constant, and CLI-command changes are visible in `bin/build-runtime.php`. The generated runtime can be reproduced with `composer install && composer runtime` using the committed lock file.

The lock file is the source pin. A deliberate `composer update` may move the prerelease branch; review the resulting commit and regenerate/verify the runtime before distribution. This build does not treat 0.7.0 as a released upstream version.
