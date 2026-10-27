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
