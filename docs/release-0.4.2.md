# Site Agent 0.4.2

Fixes OAuth sign-in from clients that append their parameters to the authorization endpoint with a second `?`.

Grok opened `wp-admin/admin.php?page=site-agent-authorize?response_type=code&...`. PHP read the page as `site-agent-authorize?response_type=code`, an unregistered page, and wp-admin answered "Sorry, you are not allowed to access this page." before `admin_init`.

- The advertised `authorization_endpoint` is now `/wp-json/site-agent/v1/oauth/authorize`, which has no query string. It answers 302 with `Cache-Control: no-store` to the consent screen, carrying the request's parameters. Without pretty permalinks REST URLs carry a query anyway, so the consent screen is still advertised directly.
- `admin_page_access_denied` repairs `page=site-agent-authorize?...` into the consent URL, for clients holding the old metadata.

Before relying on a REST GET redirect, Cloudflare and GT Performance were checked on gauravtiwari.org: `/wp-json/` responses are `cf-cache-status: DYNAMIC` and bypass the page cache.

Verification: OAuthTest covers both paths (11 tests, 117 assertions); CI passed on PHP 8.0, 8.3 and 8.5. An HTTP run on a disposable site reached the consent screen from a Grok-style URL on both the new endpoint and the old address while signed in. On gauravtiwari.org the user's Grok URL redirected to the consent screen, and a signed-out request went to the login screen with the original URL kept for the return.

Published code commit/tag: `e06776a` / `v0.4.2`, the merge of pull request #6. The WordPress ZIP is 728128 bytes, SHA-256 `84692b73187c8a6219a924ecbdc07b80212f2c206503aa9e6d8ae2b6cd49c470`, 378 files matching the merge commit and the live install, delivered through FluentCart download 367 as R2 object `site-agent-0.4.2.zip`. The companion ZIP is 21905 bytes, SHA-256 `990f092cd444ba897790d0e9763059950311bd6d95f0cee7b68c9f791713d5dd`. Release 0.4.1 remains in download 365, retitled without "Latest".

The R2 object was read back before the store changed in one transaction. Synthetic license 134 passed 13 of 13 updater checks and was restored to disabled with its activation and site removed. Product content, pricing, other metadata, earlier download rows and changelog history were unchanged. The live install used Plugin_Upgrader with GT Performance's upgrade purge hooks removed; no purge fired.
