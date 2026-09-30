# Source inventory

Site Agent application files (`site-agent.php`, `uninstall.php`, `includes/`, `bin/`, and `tests/`) were authored independently for this plugin. No Novamira code, asset, build script, or dependency was copied or adapted into these files.

Runtime dependencies:

| Package | Version | Source | License |
| --- | --- | --- | --- |
| WordPress MCP Adapter | 0.7.0 prerelease, `dev-trunk` locked at `ef6492880f0be9ec501881700a25a18f11953758` | https://github.com/WordPress/mcp-adapter | GPL-2.0-or-later |
| WordPress PHP MCP Schema | 0.2.0, commit recorded in manifest | https://github.com/WordPress/php-mcp-schema | GPL-2.0-or-later |

The build inputs are exactly these two packages' PHP runtime trees and their LICENSE files. Composer's development packages and Jetpack autoloader are not shipped. Original copyright notices and complete runtime licenses are included. Namespace, adapter hook, constant, and CLI-command changes are visible in `bin/build-runtime.php`. The generated runtime can be reproduced with `composer install && composer runtime` using the committed lock file.

The lock file is the source pin. A deliberate `composer update` may move the prerelease branch; review the resulting commit and regenerate/verify the runtime before distribution. This build does not treat 0.7.0 as a released upstream version.

Website assets are separate from the plugin runtime. The product-page interface uses 9 Tabler outline icons from the owner's local icon library. Their original SVGs and the complete MIT notice are in `site/assets/icons/`; the official source is [Tabler Icons](https://github.com/tabler/tabler-icons). These assets are excluded from the installable plugin ZIP.

The approved Site Agent icon was supplied by the owner. `site-agent-icon-approved-512.png` preserves that original file. The centered banner and social artwork embed it unchanged and use original compositions with a `#1b2733` accent. The uploaded icon has only generic EXIF metadata removed; its decoded pixels match the approved icon exactly. Editable SVGs retain native text, and outlined SVGs plus PNG exports are reproduced by `bin/render-brand.py`. Local font files are not distributed.
