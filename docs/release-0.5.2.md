# Site Agent 0.5.2

Bundled-guide update, released October 27, 2026 through GitHub and FluentCart.
Production installation is a separate action.

- The GenerateBlocks guide now tracks
  [generateblocks-skills v2026.10.27](https://github.com/wpgaurav/generateblocks-skills/releases/tag/v2026.10.27),
  verified on GenerateBlocks 2.5.0 / Pro 2.8.0 through Site Agent on a live WordPress
  7.1.3 site: inline-only block CSS, the `generateblocks_default_at_rules` breakpoint
  presets, the editor Query Loop preview cap, the fixed compiled rule order and the
  dynamic-tag save rules for the connected account.
- Five bundled GenerateBlocks examples carry CSS in the order GenerateBlocks 2.5
  compiles. The Site Agent-authored guide gates on the installed GenerateBlocks and
  Pro versions from `site-context`.
- No PHP, tool, schema, OAuth or licensing behavior changed.

## Local validation

- **134 PHPUnit tests / 1,396 assertions** passed on PHP 8.5.10 and PHP 8.3.35 against
  the marked disposable WordPress fixture.
- Nine JavaScript tests and the artifact-preservation and HTTP-fixture guard tests passed.
- Composer strict validation, all PHP syntax checks, WordPress coding standards, skill
  validation (11 skills, 93 files, 74 pinned imports), translation template and
  whitespace checks passed. The regenerated official runtime matched the committed copy.
- The WordPress archive has 387 files with a single `site-agent` root, every packaged
  PHP file lints, and its bundled skills match the source tree. Neither package contains
  GenerateBlocks or GenerateBlocks Pro source.

| Local artifact | Bytes | SHA-256 |
| --- | ---: | --- |
| `site-agent-0.5.2.zip` | 759907 | `49051b633d6994a72cc07007c66ab88b23884817f3f1e67f68111b4234bbdfdc` |
| `site-agent-companion-0.5.2.zip` | 22515 | `b227c019166c9689d07e8694854ffb62b1f2419dd40b70e2d7bf595d19f0d21f` |

## Delivery receipt

Published from merge commit `89f56089e340da5e67d8fef3eb4acdd43c12928e` and annotated
tag `v0.5.2`, after [pull request #10](https://github.com/wpgaurav/site-agent/pull/10)
(the skill sync landed in [pull request #9](https://github.com/wpgaurav/site-agent/pull/9)).
The [GitHub release](https://github.com/wpgaurav/site-agent/releases/tag/v0.5.2) is
published and stable. The PR run `40530371682` and the merge-commit run `40530763899`
passed all three WordPress jobs on PHP 8.0, 8.3 and 8.5. Fresh downloads of both
published assets matched the sizes and SHA-256 values above byte for byte.

Live identity was confirmed as Site Agent, published `fluent-products` post 1180328,
slug `site-agent`, free variation 131, with FluentCart and Pro 2.2.0 active. The verified
GitHub ZIP was uploaded through the installed R2 driver to the previously absent root key
`site-agent-0.5.2.zip` in the existing bucket and read back for byte-size and SHA-256
comparison before the updater metadata changed.

A guarded transaction locked and re-checked the previous metadata (0.5.1, download 431),
snapshotted it privately on the server, created download **469**, set
`license_settings.version = 0.5.2` and `global_update_file = 469`, and prepended the
release changelog. Unrelated license settings, product content and commerce fields were
preserved. Download **431** and `site-agent-0.5.1.zip` remain for rollback.

The real licensed updater passed **13/13 checks, zero failures or skips**, and a separate
request confirmed `success: true` with the package on the bucket's R2 S3 service host. No
license key, activation hash, customer URL or signed package URL was printed. On the marked
disposable fixture, `.maintenance` produced HTTP 503 while native
`WP_Upgrader::download_package()` fetched the licensed package and verified its size,
SHA-256, single root and 0.5.2 header; the flag was cleared in `finally` and production
was never put in maintenance mode. Scoped R2 reconciliation found no orphaned Site Agent
objects. The remote staging ZIP and temporary scripts were removed and the fixture was
stopped. This release did not install 0.5.2 on any production site.
