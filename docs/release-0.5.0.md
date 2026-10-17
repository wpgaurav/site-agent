# Site Agent 0.5.0

Adds bundled workflow guidance for MailerHive, Core Support, FluentCart, GT Extensions
for FluentCart, Stackless for WooCommerce and GT Page Blocks Builder (PBB).

The six guides cover record ownership, native API entry points, enabled module checks,
drafts and live changes, content/version conflicts, and verification. Site context now
reports each integration's plugin file, installed version, active/installed/missing state
and associated skills, separately from page builders. Existing plugin-provided skills
continue to supply their version-specific commands. The companion package points clients
to these guides. No execution group is automatically enabled and there is no new general
execution tool.

Local verification: 88 WordPress integration tests and 893 assertions on PHP 8.3 and 8.5,
nine JavaScript credential-converter tests, Composer validation, PHP lint, WordPress coding
standards, scoped runtime freshness, translation freshness, skill/reference validation,
plugin/companion package integrity and version parity. The original 74 pinned skill
imports are unchanged. See [implementation notes](plugin-workflows.md).

## Delivery receipt

Published October 17, 2026 from merge commit `38bf25573f0c992f126b61a1a4a06a8ae1bd077b`
and annotated tag `v0.5.0`, after [pull request #7](https://github.com/wpgaurav/site-agent/pull/7).
The [published GitHub release](https://github.com/wpgaurav/site-agent/releases/tag/v0.5.0)
is stable, not a draft or prerelease. Both the PR and exact merge commit passed the
WordPress 6.9 CI matrix on PHP 8.0, 8.3 and 8.5; merge-commit run `39053751332`.

| Artifact | Bytes | SHA-256 |
| --- | ---: | --- |
| `site-agent-0.5.0.zip` | 750366 | `8e0acca95f2f52e5c88d08fbaf7637e5dd157961ff9e9458cf286bc878bdcffd` |
| `site-agent-companion-0.5.0.zip` | 22515 | `6e0ae5a605f0b636da693ae508dac4f1fd154cdc07d5f927f9b3e0a40d55265a` |

Fresh GitHub downloads matched the canonical packages byte-for-byte. The WordPress
archive contains 387 files under `site-agent/`, including all eleven skills; packaged
PHP lint covered 286 files. The optional companion has ten files and is for compatible
AI clients, not WordPress installation. Its credential-free connection is configured
for gauravtiwari.org; the WordPress plugin serves all six new guides without it.

The live product identity was confirmed as Site Agent, `fluent-products` post 1180328,
slug `site-agent`, with free variation 131. FluentCart and Pro were both 2.2.0. The
verified GitHub WordPress ZIP was uploaded through the installed R2 driver to object
`site-agent-0.5.0.zip` in the existing bucket, then read back for size and SHA-256.

One guarded database transaction created download **428**, updated
`license_settings.version` to `0.5.0` and `global_update_file` to `428`, and prepended the
release changelog while keeping its history. It re-read and locked the old settings and
download before mutation. The preceding 0.4.2 download **367** and object remain available
for rollback; its title no longer carries "Latest". Product content, identity, pricing,
variations, visibility, unrelated metadata and license settings were preserved.

An existing active license was selected internally; no license key, activation hash,
customer URL or signed package URL was printed. The skill's real updater verifier passed
**13/13 checks, zero failures or skips**: valid 0.5.0 metadata, direct off-site R2 URL,
matching downloaded bytes and checksum, valid ZIP/root/version, and invalid activation
receiving no package. A separate request also required `success: true` and the direct R2
service host.

On the explicitly disposable WordPress fixture, `.maintenance` produced an actual HTTP
503 while native `WP_Upgrader::download_package()` downloaded the same licensed R2 ZIP
and verified its size, SHA-256 and embedded version. The maintenance flag was cleared in
`finally`. Production was never put in maintenance mode. Scoped R2 reconciliation listed
244 objects, below the 1000-object ceiling, and found no orphaned Site Agent objects.

The exact server staging ZIP was removed after delivery verification. Earlier release
rows, R2 objects and local ZIPs remain. No customer entitlement was changed; synthetic
verification license 134 remained disabled. The live Site Agent installation was left
at **0.4.2**: this task released downloads and automatic-update delivery without deploying
the plugin to production or updating other plugins.
