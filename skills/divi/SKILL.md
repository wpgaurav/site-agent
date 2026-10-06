---
name: divi
description: Read and edit Divi (and Extra) layouts on a live WordPress site through Site Agent, for both Divi 4 shortcodes and Divi 5 block-format content, including library global modules, Theme Builder templates, presets, design variables and static CSS. Use for Divi sections, rows, columns, modules, migrations between formats and Divi cache problems.
---

# Divi through Site Agent

Divi stores page layouts in `post_content`, in one of two formats:

- **Divi 4:** shortcodes, `[et_pb_section][et_pb_row][et_pb_column type="4_4"][et_pb_text]...`. See [references/divi-4.md](references/divi-4.md).
- **Divi 5:** WordPress block comments wrapped in one `<!-- wp:divi/placeholder -->` block, with every setting and all text in the comment JSON. See [references/divi-5.md](references/divi-5.md).

Divi 5.0 became stable on 2026-02-26 and has shipped frequent minor releases since; Divi 4.27.x is still maintained for sites that have not moved. One Divi 5 site can hold both formats, because unmigrated Divi 4 posts keep rendering. **Detect the format per post, never per site.**

Evidence note: Elegant Themes' documentation site was not reachable while this skill was written. The format details come from Divi's own PHP source (5.8.1) and Elegant Themes' official Divi 5 example repositories on GitHub. Divi is proprietary, so with source inspection enabled, prefer the installed theme's code (`themes/Divi/includes/builder-5/` and `themes/Divi/includes/builder/`) when it disagrees with this skill, and say which you used.

## Confirm the target

1. `site-context` lists Divi in `builders` with the theme name (`Divi` or `Extra`, or `Divi Builder` for the plugin) and the product version. A version below 5.0.0 cannot render Divi 5 blocks.
2. `get-content` shows `builder: divi` when the post has the Divi builder flag or Divi 5 blocks. Then check the raw content:
   - starts with `<!-- wp:divi/placeholder`: Divi 5 format;
   - contains `[et_pb_section`: Divi 4 format;
   - contains `<!-- wp:divi/shortcode-module`: Divi 5 with an embedded Divi 4 module from a third-party plugin; leave that block alone.
3. Never write Divi 5 blocks into a site running Divi 4, and do not paste Divi 4 shortcodes into a Divi 5 layout. Converting a post between formats is Divi's job (Divi > Divi 5 Migrator, or opening the page in the Divi 5 Visual Builder); do it only when asked.

## Where each kind of change goes

| Change | Route |
|---|---|
| A page or post layout (`page`, `post`, other REST-enabled types) | `get-content` and `save-content`, as below |
| A synced global module, row or section | The library post (`et_pb_layout`), through `execute-php`. Editing the page's copy has no visible effect, because the library post's settings override it. |
| Theme Builder headers, bodies, footers and template assignments | `et_template`, `et_header_layout`, `et_body_layout` and `et_footer_layout` posts through `execute-php`. These post types are not REST-enabled, so `get-content` cannot read them. |
| Presets, design variables, global colors | Options, through `execute-php`; see the Divi 5 reference |
| Theme Options (logo, header settings, integrations) | The `et_divi` option; change only on explicit request, and snapshot first |

## Page workflow

1. **Read raw** with `get-content` and keep the `content` as your snapshot. Note `autosave`: a newer autosave means someone has unsaved Visual Builder or editor work.
2. **Check for an open Visual Builder.** The Visual Builder keeps the whole layout in the browser and writes all of `post_content` when it saves, so a tab that was open before your edit silently overwrites it. With PHP execution, `return wp_check_post_lock( 123 );` returns the ID of a user currently editing (false when free). Ask the user to close or reload the builder before you write.
3. **Splice** your change into the existing content. Keep every other byte unchanged.
   - Divi 5: keep the single outer `divi/placeholder` block and put nothing outside it. Encode attribute JSON the way WordPress does (see the Divi 5 reference) and keep text inside the JSON, never between the comment delimiters.
   - Divi 4: never put a literal `"`, `[` or `]` inside an attribute value; Divi encodes them as `%22`, `%91` and `%93`. Text for modules such as `et_pb_text` sits between the opening and closing shortcode; many other modules keep text in attributes such as `title` or `button_text`.
4. **Server check (Divi 5, needs PHP execution).** Run the snippet below on the new content before writing.
5. **Write** with `save-content`, passing `content` and `expected_content_sha256`. A draft is updated directly. A published, private or scheduled post is staged as your autosave unless you pass `status`; Divi's CSS refresh only happens on a real update, so the live page does not change until the user publishes.
6. **Read back.** Divi filters content on save (shortcode paragraph cleanup, attribute sanitizing and, for users without `unfiltered_html`, HTML filtering), so the stored content can differ slightly from what you sent. Compare the region you changed; explain any difference instead of retrying, and use the returned `content_sha256` for the next write.
7. **CSS.** A real update through `save-content` fires `save_post`, and Divi clears that post's static CSS. After any write through `execute-php`, or after changing a global module, preset, variable or color, clear it yourself (below). Then load the page with a cache-busting query string.

## Snippets for `execute-php`

**Divi 5 structure check.** Paste the complete new content into the nowdoc.

```php
$content = <<<'DIVI'
...complete post content...
DIVI;
$blocks = array_values( array_filter( parse_blocks( $content ), function ( $block ) {
	return null !== $block['blockName'] || '' !== trim( $block['innerHTML'] );
} ) );
$names = array();
$walk  = function ( $list, $depth ) use ( &$walk, &$names ) {
	foreach ( $list as $block ) {
		if ( null === $block['blockName'] ) {
			if ( '' !== trim( $block['innerHTML'] ) ) {
				$names[] = str_repeat( '  ', $depth ) . 'LOOSE: ' . substr( trim( $block['innerHTML'] ), 0, 60 );
			}
			continue;
		}
		$names[] = str_repeat( '  ', $depth ) . $block['blockName'];
		$walk( $block['innerBlocks'], $depth + 1 );
	}
};
$walk( $blocks, 0 );
return array(
	'single_placeholder' => 1 === count( $blocks ) && 'divi/placeholder' === $blocks[0]['blockName'],
	'canonical'          => serialize_blocks( parse_blocks( $content ) ) === $content,
	'tree'               => $names,
);
```

`single_placeholder: false` means content sits outside the wrapper, which can lose everything after the first row. `canonical: false` means some attribute JSON is not in the form WordPress's PHP serializer writes. Run the same check on the original content too: if it was already non-canonical, only differences you introduced matter. Escape what you write as described in the Divi 5 reference. `LOOSE` lines are text outside any block.

**Write a post that `save-content` cannot reach** (library and Theme Builder posts). Read it first with `return get_post( 123 )->post_content;` and keep that snapshot.

```php
$id       = 123;
$expected = 'sha256 of the content you read';
if ( ! hash_equals( $expected, hash( 'sha256', get_post( $id )->post_content ) ) ) {
	throw new RuntimeException( 'The content changed since it was read. Read it again.' );
}
$content = <<<'DIVI'
...complete new content...
DIVI;
$result = wp_update_post( wp_slash( array( 'ID' => $id, 'post_content' => $content ) ), true );
if ( is_wp_error( $result ) ) {
	throw new RuntimeException( $result->get_error_message() );
}
// Library layouts are used across the site, so clear all static CSS.
ET_Core_PageResource::remove_static_resources( 'all', 'all' );
$stored = get_post( $id )->post_content;
return array( 'identical' => $stored === $content, 'sha256' => hash( 'sha256', $stored ) );
```

Always pass the post array through `wp_slash()`; without it WordPress strips the backslashes from escaped attribute JSON and corrupts Divi 5 content.

**Clear static CSS** (cache files live in `wp-content/et-cache`): `ET_Core_PageResource::remove_static_resources( 123, 'all' );` for one post, or `( 'all', 'all' )` for the whole site. The call also asks common page cache plugins to purge. It does nothing during an autosave or for a user without `edit_posts`; Site Agent runs PHP and WP-CLI as the authenticated administrator, so it applies. Divi registers no WP-CLI commands of its own.

**Builder flag.** When adding Divi content to a post that was not built with Divi, also run `et_builder_enable_for_post( 123, false );` so the front end renders the layout instead of raw shortcodes or blocks.

## Completion report

State the post ID and format (Divi 4 or 5), whether the change is a draft, a staged autosave or live, the checks that passed, whether static CSS was cleared, and what is unverified (Visual Builder appearance, front-end caching, behavior on Divi versions newer than the evidence above).
