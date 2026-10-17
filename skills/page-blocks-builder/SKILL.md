---
name: page-blocks-builder
description: "Build and edit GT Page Blocks Builder (PBB) pages and reusable HTML, CSS and JavaScript sections through Site Agent. Distinguish page-owned blocks from shared library rows, use gt_pb_agent with content hashes and autosaves, and verify the actual page template."
---

# Page Blocks Builder (PBB)

PBB stores page-owned sections inside WordPress content as `gt-page-block/page-block`.
Shared library blocks live separately in `{prefix}gt_page_blocks`; a block with nonzero
`blockId` renders the library row instead of its own inline code. Editing a shared row
can affect many pages and theme regions.

## Preflight and ownership

1. Call `site-agent-site-context`. Confirm active `page-blocks-builder/page-blocks-builder.php`,
   version, site and enabled tools. This guide was inspected at PBB 4.2.0.
2. Read `list-skills`, then the plugin-provided `gt-page-blocks` skill if present. It holds
   the installed `gt_pb_agent()` commands. Running these commands requires explicitly
   enabled `execute-php`, even for its reads; base content tools remain a separate route.
3. Resolve the page/CPT ID and read raw content, builder, template and status. PBB content
   may be reported as Gutenberg because it is stored in blocks; inspect actual block names.
4. Read `context`, `page.sections` and the specific `page.section`. Determine whether the
   selected section is page-owned, linked, PHP-backed or native WordPress block content.
   Native blocks aren't all editable through PBB's code-section agent operations.

## Agent operations

```php
if (!function_exists('gt_pb_agent')) {
    return new WP_Error('unavailable', 'PBB agent API is unavailable.');
}
return gt_pb_agent('context');
```

Then, for a resolved page ID, run `gt_pb_agent('page.sections', array('post_id' => 42))`.
Check `ok` and the returned content hash. IDs in examples are placeholders.

| Command | Inputs and behavior |
| --- | --- |
| `page.section` | `post_id`, `index`; read full section attributes |
| `page.set_section` | `post_id`, current `expected_sha256`, `section`, and `index` to update or `after` to insert; otherwise append |
| `page.create` | `title`, `sections`, optional `canvas`; always creates a draft page |
| `section.markup` | `section`; returns correctly serialized block markup |
| `blocks.list` | Search/status/pagination; includes usage counts |
| `blocks.get`, `blocks.render` | Library `id`; inspect data and rendering |
| `blocks.create` | `block`; defaults to draft without a theme position |
| `blocks.update` | `id`, targeted `patch`; shared-library change |
| `blocks.duplicate`, `blocks.trash` | Library `id`; inspect usages before trashing |

Sections accept `name`, `content`, `css`, `js`, `jsLocation`, `format` and supported
placement fields. Use `section.markup` or the native block serializer; don't manually
escape HTML into a block-comment JSON string. Send one section at a time to stay under
the PHP tool's 64 KiB code and return caps. Use safely serialized data or a nowdoc for code
fields. PHP execution on a PBB section is not the same as permission to run a Site Agent
PHP command; the agent API refuses enabling PHP and editing existing PHP sections.

## Save and verify

- Prefer page-owned sections when reuse isn't needed. Scope CSS to a section/page root,
  preserve media-library URLs and make JS initialization safe for repeated placement.
- For a linked block, read and edit the library row or deliberately unlink it; changing
  ignored inline attributes does not change the visible section. Search before creating
  a new reusable block and inspect all affected usages before a shared edit.
- `page.set_section` uses `content_sha256` from the working content, including the user's
  newer autosave. On live posts it stages an autosave unless `publish: true`. Follow the
  returned state and hash for the next patch. A stale hash requires a new read.
- Library writes do not gain that page-content hash guard. Snapshot the current row,
  compare before a targeted patch, preserve unrelated fields and read back. Publishing,
  theme placement and trashing require authorization for their shared effects.
- Verify decoded attributes, placement links, working content, saved status and template.
  If rendering can be inspected, check responsive layout and interactions. Stored content
  verification alone isn't a rendered-page test.

## Choose the template deliberately

Keep the current template for section edits. For a complete PBB page, inspect the active
theme's `get_page_templates(null, $post_type)` map. `pbb-template.php` is a theme-specific
PBB Template, not a universal plugin template. On sites providing the isolated PBB shell,
it keeps the site's header/footer and typography but can exclude other theme/plugin
assets; don't assume widgets or checkout modals load there or duplicate its header.

By contrast, `page.create` with `canvas: true` chooses PBB's
`page-blocks-full-builder.php` blank canvas, without the theme header/footer. Don't silently
substitute that for a requested `pbb-template.php`. Site Agent `save-content` has no
`template` input: use the supported native template save route with a snapshot and
read-back if authorized, or leave the selected template for review.

Library operations route through `pbb/v1/blocks` and retain native permission callbacks.
On older versions without `gt_pb_agent`, inspect the installed REST API and block
registration first. Use the block editor for unsupported page operations; never silently
switch to an unguarded database or PHP-section edit.

Source map: `includes/class-agent.php`, `class-rest-api.php`, the block registration and
`agent-skills/page-blocks/SKILL.md` in the installed plugin, plus the active theme template.
