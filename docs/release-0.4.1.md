# Site Agent 0.4.1

Makes OAuth the default connection and warns about Application Passwords.

- OAuth connections are on by default. The setting defaults to true only where it was never saved; a site that turned it off keeps its choice.
- Tools → Site Agent leads with OAuth and moves Application Passwords to a "not recommended" section. It warns when OAuth is off, when URL authentication is on, and when Application Passwords made tool calls in the last 30 days.
- `site-context` returns `connection` (`method`, `client`, `warning`). Calls made with an Application Password or the authenticated URL carry a warning that agents pass on.
- A Plugin skills panel lists GT Extensions for FluentCart, GT Page Blocks Builder and GT Link Manager, which register skills through `site_agent_skills`, with their state on the site.
- Fixed: plugin skill descriptions written as quoted YAML kept their quotes in `list-skills`.

Live testing on gauravtiwari.org (OAuth on, the existing authenticated-URL connection in use) showed the connection warning in `site-context`, all four plugin skills in `list-skills`, the OAuth 401 `WWW-Authenticate` pointer and issuer metadata with S256, and the Plugin skills panel rendering with every plugin active. It found the quoted-description bug above and a GT Link Manager result key, both fixed before release. Media upload by base64 and URL import, alt text and caption updates, and placing an image in a draft as a block and featured image were also checked; the URL import was byte-identical to its source.

Verification: PHPUnit on the CI matrix (PHP 8.0, 8.3, 8.5) on the pull request head, coding standards, runtime, skill and translation freshness, and plugin and companion builds. Locally, 84 tests passed apart from the known WP-CLI deprecation output under PHP 8.5.

Published code commit/tag: `dffd357` / `v0.4.1`, the merge of pull request #5. The WordPress ZIP is 727306 bytes, SHA-256 `e0f32cc20acfc8edd80e4918c0521ca4a5eef8e8ea6971e2b17467086b67628d`, with 378 files that match the merge commit and the live install, delivered through FluentCart download 365 as R2 object `site-agent-0.4.1.zip`. The companion ZIP is 21906 bytes, SHA-256 `c0bb43253f987bb649c8a8f3000d92199330969bcb0e4058bbff0a3c6e7ee2e3`. Release 0.4.0 remains in download 363, retitled without "Latest".

The R2 object was read back before the download row and licensing settings changed in one transaction. Synthetic license 134 was enabled and activated for a synthetic site: 13 of 13 updater checks passed, including an invalid activation receiving no package. The activation and site were removed and the license restored to disabled. Product content, pricing, other metadata, earlier download rows and changelog history were unchanged.

The installs used Plugin_Upgrader with GT Performance's upgrade purge hook removed in-process; no purge hook fired. Full Cloudflare purges at 02:52:47 and 03:05:19 UTC followed edits to the About page made by the site owner during the release.

The product page still says Site Agent does not offer OAuth.
