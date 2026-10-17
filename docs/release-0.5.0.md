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

This release authorizes GitHub and FluentCart/R2 distribution. It does not install the
plugin on a live site, update other plugins, or change product pricing, variations,
visibility or license limits. Delivery receipts are added after both channels pass
verification.
