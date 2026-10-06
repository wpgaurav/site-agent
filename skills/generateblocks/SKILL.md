---
name: generateblocks
description: Build, edit and audit GenerateBlocks V2 layouts on a live WordPress site through Site Agent, including CSS Mode styles, responsive rules, dynamic data, query loops and Pro blocks, with recovery-safe serialization. Use for new GenerateBlocks sections, repairs, conversions and "Attempt Block Recovery" problems.
---

# GenerateBlocks through Site Agent

GenerateBlocks V2 layouts are block markup in `post_content`, so they are read and written like any Gutenberg content. The difference is strictness: GenerateBlocks stores editable `styles` and compiled `css` in the comment JSON, and the editor rejects a block when that JSON or the saved HTML differs from what its `save()` would produce. Small serialization mistakes become "Attempt Block Recovery".

This skill is adapted from the author's `generateblocks-skills` repository. Its references and examples are bundled verbatim; this file and [references/mcp-publishing.md](references/mcp-publishing.md) are written for Site Agent.

Tool names below omit the `site-agent-` prefix your client shows: `get-content` is `site-agent-get-content`.

**Use GenerateBlocks and core blocks only.** Keep styling in native block `styles` and `css`, and use native Pro blocks for interactions. Do not add custom scripts, Custom HTML/CSS/JS blocks, another builder, or theme or plugin code to finish a GenerateBlocks design unless the user asks for it. Simplify effects that native blocks cannot express.

## Before writing markup

1. Call `site-context`. Confirm the `generateblocks` builder entry and note `version` and `pro_version`. The references record behavior for free 2.4.1 with Pro 2.7.1, and a beta workflow for free 2.5.0-beta.1 with Pro 2.8.0-beta.1. Say so when the site runs something else.
2. Read [references/authoring-contract.md](references/authoring-contract.md) once. It owns the scope prompt, block IDs, block selection, serialization, CSS parity, responsive basics and validation rules.
3. Use [references/_index.md](references/_index.md) to pick further references for the task. Do not load the whole library for a routine static layout.
4. **Local styles are the default.** Ask once about shared Global Styles and design tokens unless the user already chose. Add shared records only after explicit opt-in, and preserve existing references.

## Workflow

1. **Resolve the target.** `get-content` by ID, URL or slug; check `builder` is `generateblocks` or `gutenberg`. If it is `elementor`, `bricks` or `divi`, stop: that layout is not in `post_content`. Use the real post ID as the block ID scope when one exists.
2. **Inspect conventions.** Read the existing raw content and match its block versions, class naming and ID style. `references/field-notes.md` section 7 covers what to measure.
3. **Author.** Select semantic blocks, keep `styles` and `css` aligned, and follow the six comment-JSON substitutions from the contract. For new designs, read `references/design-quality.md`; for dynamic data, `references/dynamic-tags.md` and the matching task guide.
4. **Publish** with the read, snapshot, splice, check, write, read back and verify loop in [references/mcp-publishing.md](references/mcp-publishing.md). One section per write; on a published post, build each section on the staged autosave as that guide describes.
5. **Report** briefly: what changed, the post ID and status, required Pro features, the checks that passed and any behavior left unverified.

Converting an Elementor, Bricks or HTML source into GenerateBlocks does not authorize publishing it. Create a draft unless the user asked for a live change.

## Scripts mentioned in the references

The references name Python helpers (`scripts/gb_serialize.py`, `scripts/preflight.py`, `scripts/verify_roundtrip.py`). They are not bundled with Site Agent and do not run on the WordPress site. If your client has a local shell and a clone of `github.com/wpgaurav/generateblocks-skills`, use them. Otherwise use the server-side checks in [references/mcp-publishing.md](references/mcp-publishing.md), which run through `execute-php` against the site's own WordPress serializer, and state which checks you could not run.

## Upstream references that are not bundled

The imported references were written for the upstream repository and sometimes point at things Site Agent does not ship:

- `content.raw` means the `content` field of `get-content`, which is already the raw `post_content`.
- "SKILL.md" and its numbered rules mean the upstream skill file. The rules that matter here are in [references/authoring-contract.md](references/authoring-contract.md) and [references/recovery-rules.md](references/recovery-rules.md).
- `examples/from-gauravtiwari-org/`, `examples/beta-design-system/`, the `scripts/` helpers and the `gt-design` skill live only upstream or in the author's own setup.

## Examples and failures

The `examples/` files are validated starting points: [basic buttons](examples/basic/buttons.html), [containers](examples/basic/containers.html), [text with icons](examples/basic/text-icons.html), [cards](examples/compound/cards.html), [hero section](examples/layouts/hero-section.html), [query blog grid](examples/layouts/query-blog-grid.html) and [SVG icons](examples/svg/icons.html). Replace their IDs, URLs and content. The beta design-system example lives only in the upstream repository.

For a recovery or check failure, open the matching topic in `references/recovery-rules.md`, then `references/troubleshooting.md`. For an unfamiliar attribute or tag, use `references/block-types.md`; with source inspection enabled, the installed `block.json` files under `plugins/generateblocks/dist/blocks/` are the final word.
