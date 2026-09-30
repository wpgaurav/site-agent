# Contributing

Open an issue describing the problem and the intended behavior before sending a substantial change. Keep changes focused, preserve WordPress compatibility, and include tests for authorization, data boundaries and update delivery when those surfaces change.

Install development dependencies with `composer install`. Build the scoped official runtime with `composer runtime`; its source pin is recorded in `composer.lock`. Run `composer lint`, `vendor/bin/phpcs` and the WordPress integration suite. Tests refuse an installation without a `.site-agent-test-install` marker. Use only a disposable WordPress installation for that marker and suite.

Do not weaken default-off access or imply developer tools are sandboxed. Preserve encrypted update credentials, per-site activation and the separation between licensing and functionality. Never include Novamira application code, assets, scripts or dependencies. Keep bundled official WordPress code reproducible with its original notices and licenses.

New strings must use the `site-agent` text domain. Generated runtime and translations must agree with their sources. Public release assets are built using `bash bin/build.sh`; Composer dependencies, tests, source tooling, product-page drafts and local fonts must not enter the installable plugin ZIP.
