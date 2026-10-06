---
name: gutenberg
description: Read, write and repair WordPress core block (Gutenberg) markup on a live site through Site Agent. Use for posts, pages, synced patterns, template parts and templates stored as serialized blocks in post_content, and for block validation or "Attempt Block Recovery" problems.
---

# Gutenberg block markup through Site Agent

Core blocks live in `post_content` as HTML with `<!-- wp:name {json} -->` comment delimiters. The block editor validates each static block by running its JavaScript `save()` and comparing the result with the stored HTML, so a write that looks fine in a diff can still open with "This block contains unexpected or invalid content". Treat every write as byte-sensitive.

Tool names below omit the `site-agent-` prefix your client shows: `get-content` is `site-agent-get-content`.

For the block-by-block syntax (paragraph, heading, list, image, buttons, group, columns, cover, embed, gallery, details and more), read [references/block-markup.md](references/block-markup.md). It also covers nesting rules, dynamic blocks and the validation and deprecation model.

## Confirm this is the right skill

1. Call `site-context`. The `builders` list names every builder active on the site.
2. Call `get-content` for the target and read its `builder` field:
   - `gutenberg`: continue here.
   - `generateblocks`: read the `generateblocks` skill too. GenerateBlocks blocks have stricter serialization rules than core blocks.
   - `elementor`, `bricks` or `divi`: stop. The visible layout is stored elsewhere (post meta, or Divi shortcodes), and writing block markup into `post_content` either does nothing visible or gets overwritten by the builder. Load that builder's skill.
   - `classic`: the post has no blocks. Converting it to blocks is a content change; do it only when asked.

## Workflow

1. **Read raw.** `get-content` returns raw `content` and `content_sha256`. Never write back rendered HTML; it has no block delimiters and destroys the page structure.
2. **Snapshot.** Keep the raw content you read before the first write to a post you did not create. Site Agent creates revisions through core APIs where the post type supports them, but a local copy is the cheapest rollback.
3. **Splice, do not regenerate.** Insert or replace only the blocks the task covers and keep every other byte unchanged: existing attribute order, whitespace between blocks, custom classes, anchors, shortcodes, `[year]` style placeholders and HTML inside Custom HTML blocks.
4. **Write.** `save-content` with the complete `content` and `expected_content_sha256` from your latest read. New posts default to drafts. Edits to a published, private or scheduled post are stored as your autosave for review unless you pass `status`; the result then shows `staged: true` and the live `content` is unchanged. On a hash conflict, re-read and reconcile; never retry blindly.
5. **Read back.** Call `get-content` again and confirm the region you wrote is byte-identical to what you sent: in `content` for a draft or a save with `status`, and for a staged edit in `autosave.content`, which `get-content` returns when you pass `autosave_content: true`. If it is not identical, something on the write path (a filter, a plugin) changed it. Stop and report the difference.
   - **Several staged edits to one live post.** Each staged save replaces your previous autosave. Build the next edit on `autosave.content` while `autosave.newer` is true, and keep passing the live `content_sha256` as `expected_content_sha256`. Building on the live `content` instead discards the earlier staged edits.
6. **Check structure.** With PHP execution enabled, the snippet below finds content that fell outside any block and lists the block tree. Without it, check that every opening delimiter has a matching closer and that nothing but whitespace sits between top-level blocks.
7. **Editor check.** Static checks cannot run block `save()` functions. For new block types or unfamiliar attributes, ask the user to open the post in the editor once, or say that editor validity is unverified.

```php
$content = get_post( 123 )->post_content;
$walk = function ( $blocks, $depth = 0 ) use ( &$walk ) {
	foreach ( $blocks as $block ) {
		if ( null === $block['blockName'] ) {
			if ( '' !== trim( $block['innerHTML'] ) ) {
				echo str_repeat( '  ', $depth ), 'LOOSE: ', substr( trim( $block['innerHTML'] ), 0, 80 ), "\n";
			}
			continue;
		}
		echo str_repeat( '  ', $depth ), $block['blockName'], WP_Block_Type_Registry::get_instance()->is_registered( $block['blockName'] ) ? '' : ' (not registered)', "\n";
		$walk( $block['innerBlocks'], $depth + 1 );
	}
};
$walk( parse_blocks( $content ) );
```

`LOOSE` lines are text outside a block (classic content or a broken delimiter). `not registered` means the block's plugin is inactive, so the editor will show a "missing block" placeholder.

## Rules that prevent broken blocks

- **Names.** Core blocks omit the namespace in markup (`wp:paragraph`, never `wp:core/paragraph`). Every other block needs its namespace (`wp:acf/callout`).
- **Attribute JSON.** WordPress serializes `--`, `<`, `>`, `&` and `\"` inside attribute JSON as `\u002d\u002d`, `\u003c`, `\u003e`, `\u0026` and `\u0022` (verified in `serialize_block_attributes()`, WordPress 6.9). Either form parses, but keep whichever form the post already uses so read-back comparisons stay exact. Site Agent preserves backslashes on write.
- **Comment attributes and HTML must agree.** Classes from `className`, `align`, colors and font sizes appear both in the JSON and as classes in the wrapper element. Change both or neither.
- **Lists** need `wp:list-item` children. **Buttons** need a `wp:buttons` wrapper. **Columns** contain only `wp:column` blocks.
- **Images.** Use `list-media` to find an existing attachment, then set the same ID in the JSON (`"id":123`) and in the class (`wp-image-123`). Import new images with `upload-media`; never hotlink them.
- **Dynamic blocks** (latest posts, query, navigation, site title and similar) are usually self-closing (`<!-- wp:latest-posts {"postsToShow":3} /-->`) because PHP renders them.
- **Custom HTML blocks** (`wp:html`) are not validated against a `save()` function. When the site sets `DISALLOW_UNFILTERED_HTML`, even administrators lose `unfiltered_html`, and scripts and some markup are stripped on save. (Site Agent requires a super administrator on multisite, who keeps it otherwise.)

## Patterns, template parts and templates

- A synced pattern appears as `<!-- wp:block {"ref":123} /-->`. Its content is post 123 of type `wp_block`; editing that post changes every page that uses it. Edit the referencing page only when the change should be local, by replacing the reference with the pattern's blocks.
- Block themes store customized templates and template parts as `wp_template` and `wp_template_part` posts, linked to the theme by a `wp_theme` term. Use `list-content` with that `post_type` to find them, and edit one with `save-content` plus `status: "publish"`: templates cannot be staged as autosaves, so keep the content you read as the rollback copy.
- A template that has no post yet is still the theme's file (`templates/*.html`, `parts/*.html`); with source inspection enabled, read it with `read-file`. `save-content` cannot create the override, because the `wp_theme` term it needs is not REST-enabled and a post without it is ignored. Ask the user to customize the template once in the Site Editor (which creates the post), then edit that post.
- Navigation menus in block themes are `wp_navigation` posts referenced by `{"ref":ID}` from the navigation block.

## Completion report

State the post ID, whether the change is a draft, a staged autosave or live, what you verified (byte-identical read-back, structure check) and what remains unverified (editor validation, front-end rendering, caching).
