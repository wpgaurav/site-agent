---
name: bricks
description: Author and edit Bricks Builder layouts, templates and full designs on a live WordPress site through Site Agent, preferring Bricks' own abilities (Bricks 2.4+) and otherwise producing paste-ready JSON or hash-checked element patches. Use for Bricks sections, pages, headers and footers, query loops, ACF and dynamic data, filters, conditions, interactions, popups, WooCommerce templates, custom elements and hooks.
---

# Bricks Builder through Site Agent

Bricks stores each layout as a flat element array in post meta, not in `post_content`. A post whose `get-content` result shows `builder: bricks` renders from `_bricks_page_content_2` (or the header and footer keys for those templates), so `save-content` cannot change its layout.

Tool names below omit the `site-agent-` prefix your client shows: `get-content` is `site-agent-get-content`. Bricks' own tools keep their `bricks-` names.

The references and patterns are adapted from the author's `bricks-skills` repository, verified there against the Bricks 2.3.6 source, and bundled verbatim. The Bricks abilities route was checked against Bricks 2.4.2. Check the site's Bricks version in `site-context` and say so when it differs.

## Choose the route

`site-context` lists Bricks in `builders` with its `version` and `abilities`, which is true when Bricks 2.4+ has its AI/MCP abilities switched on.

1. **Bricks abilities (preferred).** Bricks 2.4+ ships its own abilities with its own save pipeline: summary reads for large pages, revisions, CSS regeneration, code signatures, ownership checks on global classes and variables, and resumable commits. When the site owner enables **Bricks tools** in Tools > Site Agent, this connection gets:
   - Bricks' fast-path tools under their own names: `bricks-start-here`, `bricks-get-design-context`, `bricks-checkout-site-repository`, `bricks-resolve-agent-file`, `bricks-commit-exact-site-edits`, `bricks-checkout-site-edit-map`, `bricks-commit-site-edit-plan`, `bricks-commit-agent-file`, `bricks-create-post`, `bricks-commit-site-foundation`, `bricks-commit-html-css-page-import` and `bricks-apply-html-css-page-import` (Bricks can change this list);
   - `bricks-abilities`, which lists every other Bricks ability, with `ability_name` for its full description and schemas;
   - `run-bricks-ability`, which runs any of them with `ability_name` and `parameters`.

   Call `bricks-start-here` first and follow it. Where it names `mcp-adapter-execute-ability`, `mcp-adapter-discover-abilities` or `mcp-adapter-get-ability-info`, use `run-bricks-ability` or `bricks-abilities` with the same arguments. Bricks checks its own builder permissions: a `bricks_forbidden_builder_permission` error names the permission the account lacks, which the site owner grants in Bricks > Settings. `bricks/execute-php` also needs Site Agent's PHP execution group and Bricks' own `BRICKS_ENABLE_PHP_ABILITIES`.

   When `abilities` is true but no `bricks-` tools are listed, Bricks tools are off in Site Agent or limited for this password. Ask the user, or use a route below.
2. **Paste-ready JSON (no write tools).** Return clipboard JSON the user pastes into the Bricks structure panel. Bricks regenerates IDs, merges global classes by name and builds CSS on save. This is the safest route for new sections without Bricks abilities. Template exports (header, footer, popup) import through Bricks > Templates > Import.
3. **Element patch (fallback, on request).** For Bricks before 2.4, or with Bricks abilities off, write changed elements to post meta with `execute-php`, using the procedure below. It needs the PHP execution group, which runs unsandboxed PHP; prefer a staging site.

## Workflow

0. **Clarify inputs first.**
   - **rem base.** Confirm what `1rem` equals on the target site. Default to 16px when the user does not say; a `html { font-size: 62.5% }` reset makes it 10px. See [style-settings.md](references/style-settings.md).
   - **Reference URL.** If the user gives one, reference its images, video, SVG and icons by absolute URL. See [external-assets.md](references/external-assets.md). For assets that belong on the site, import them with `upload-media` (or Bricks' `bricks/upload-media`) and use the media library URL.
   - **Site conventions.** Reuse existing global classes, variables and colors instead of inventing parallel ones. With Bricks abilities, `bricks-get-design-context` with `responseFormat: "summary"` returns them; otherwise read the `bricks_global_classes`, `bricks_global_variables` and `bricks_color_palette` options with `execute-php`.
1. **Pick the format:** clipboard, template import or programmatic insert. See [json-formats.md](references/json-formats.md).
2. **Plan structure:** semantic section, container, then blocks; pick elements from [elements.md](references/elements.md).
3. **Style with settings:** every `_` style key and its exact value shape is in [style-settings.md](references/style-settings.md).
4. **Wire data:** dynamic tags, query loops and filters, using the routing table below.
5. **Validate** with the checklist, then deliver or write.

## Task routing

| Task | Reference |
|------|-----------|
| Format selection, clipboard, template and meta storage, programmatic insert | [json-formats.md](references/json-formats.md) |
| Element catalog, per-element settings, nestables | [elements.md](references/elements.md) |
| Style keys and value shapes, responsive and hover grammar | [style-settings.md](references/style-settings.md) |
| Section, grid and flex recipes, cards, overlays, sticky, full designs | [layout-recipes.md](references/layout-recipes.md) |
| Dynamic data tags, arguments, `{echo:}` | [dynamic-data.md](references/dynamic-data.md) |
| ACF (repeaters, groups, flexible content), Meta Box, JetEngine, Pods, CMB2 | [acf-providers.md](references/acf-providers.md) |
| Query loops: post, term and user queries | [query-loops.md](references/query-loops.md) |
| Faceted filters, AJAX pagination, load more, infinite scroll, live search | [query-filters.md](references/query-filters.md) |
| Show and hide conditions (`_conditions`) | [conditions.md](references/conditions.md) |
| Interactions, animations, scroll triggers (`_interactions`) | [interactions.md](references/interactions.md) |
| Popups: templates, triggers, limits, AJAX | [popups.md](references/popups.md) |
| Template types and conditions, header, footer, archive and 404 templates | [templates.md](references/templates.md) |
| Components, global classes, global variables, color palette | [components-classes.md](references/components-classes.md) |
| Theme styles, breakpoints, CSS generation order | [theme-styles.md](references/theme-styles.md) |
| Form element, actions, validation | [forms.md](references/forms.md) |
| WooCommerce templates and elements | [woocommerce.md](references/woocommerce.md) |
| PHP custom elements with controls | [custom-elements.md](references/custom-elements.md) |
| PHP filters and actions | [hooks.md](references/hooks.md) |
| External assets from a reference URL | [external-assets.md](references/external-assets.md) |
| Asset loading, CSS files, builder permissions | [assets-permissions.md](references/assets-permissions.md) |

Ready-to-paste examples (heroes, grids, pricing, ACF loops, filtered archive, header, footer, popup and BEM class-first patterns) are listed in [patterns/INDEX.md](patterns/INDEX.md). Read a pattern with `get-skill` and the path `patterns/<file>.json`.

## Non-negotiable rules

1. **Flat tree integrity.** Every `children` ID exists as a node whose `parent` points back. No orphans, no duplicates. IDs are unique 6-character alphanumerics; when splicing into an existing page, they must not collide with IDs already there.
2. **Verified value shapes only.** Colors are objects (`{"hex": "#222"}` or `{"raw": "var(--x)"}`), typography keys are CSS property names (`"font-size"`), box-shadow offsets nest under `values`, gradients use `colors: [{color, stop}]`. When unsure, check style-settings.md instead of guessing.
3. **Responsive grammar.** `setting:breakpoint:pseudo`, for example `_padding:tablet_portrait`, `_background:hover`, `_margin:mobile_portrait:hover`. Default breakpoints are `tablet_portrait` (991), `mobile_landscape` (767) and `mobile_portrait` (478). Desktop is the bare key. Sites can define custom breakpoints in `bricks_breakpoints`.
4. **Units are strings:** `"3rem"`, `"100%"`, `"24px"`. Bare numbers get `px` appended by Bricks.
5. **Spacing scale discipline.** Use one scale, or the site's global variables, across the design.
6. **Real structure.** Semantic `tag` settings on sections and blocks (`header`, `nav`, `article`, `aside`, `footer`), one `h1` per page and descending heading levels.
7. **Global classes ride along.** Every ID in an element's `_cssGlobalClasses` needs its full class object in the top-level `globalClasses` array (clipboard) or in the `bricks_global_classes` option (direct write).
8. **Do not invent settings keys.** Only keys documented in the references, returned by Bricks' element schema abilities, or found in the installed Bricks source. Bricks silently ignores unknown keys.
9. **Confirm the rem base before using rem**, and convert px values from a reference with it.
10. **Reference external assets by absolute `url`, never by attachment `id`** from another site, and respect asset licensing.

## Element patch procedure (fallback)

A post meta write takes effect immediately; there is no autosave staging as with `save-content`. For a published page, patch a draft copy unless the user asked for a live change.

`execute-php` accepts at most 65,536 characters of code and returns at most 64 KiB of JSON. Large Bricks pages exceed that (on one production site, 7 of 61 Bricks pages were over 64 KiB, the largest 565 KB), so never read or send the whole array. Read an outline, read only the elements you change, and send a patch.

**1. Outline and hash.** Page through long layouts with `$offset`.

```php
$post_id = 123;
$key     = '_bricks_page_content_2'; // _bricks_page_header_2 or _bricks_page_footer_2 for those templates
$offset  = 0;
$current = get_post_meta( $post_id, $key, true );
$current = is_array( $current ) ? $current : array();
$outline = array();
foreach ( array_slice( $current, $offset, 150 ) as $element ) {
	$outline[] = array(
		'id'       => $element['id'],
		'name'     => $element['name'],
		'parent'   => $element['parent'] ?? 0,
		'children' => $element['children'] ?? array(),
		'label'    => $element['label'] ?? '',
		'text'     => mb_substr( wp_strip_all_tags( (string) ( $element['settings']['text'] ?? '' ) ), 0, 60 ),
		'code'     => isset( $element['settings']['code'] ),
	);
}
return array(
	'editor_mode' => get_post_meta( $post_id, '_bricks_editor_mode', true ),
	'sha256'      => hash( 'sha256', wp_json_encode( $current ) ),
	'count'       => count( $current ),
	'outline'     => $outline,
);
```

**2. Read what you will change.** Return the complete objects of every element you will replace or remove, plus the parent of every element you insert or remove (its `children` list changes). These copies, with the step 1 hash, are your rollback.

```php
$post_id = 123;
$ids     = array( 'abc123', 'def456' );
$current = get_post_meta( $post_id, '_bricks_page_content_2', true );
return array_values( array_filter( (array) $current, function ( $element ) use ( $ids ) {
	return in_array( $element['id'], $ids, true );
} ) );
```

**3. Validate the patch** against the checklist below. `upsert` holds complete element objects to add or replace (including each changed parent with its new `children`), `remove` lists IDs to delete, and `after` places a new element right after an existing one in the array (array order is the order of root sections). Keep untouched elements, IDs and settings exactly as read.

**4. Apply with a hash check.** `update_post_meta()` unslashes its value, which strips backslashes from settings such as custom CSS (`content:"\f101"` becomes `content:"f101"`), so the array always goes through `wp_slash()`.

```php
$post_id  = 123;
$key      = '_bricks_page_content_2';
$expected = 'sha256 from step 1';
$json     = <<<'JSON'
{ "upsert": [], "remove": [], "after": {} }
JSON;
$patch   = json_decode( $json, true, 512, JSON_THROW_ON_ERROR );
$current = get_post_meta( $post_id, $key, true );
$current = is_array( $current ) ? $current : array();
if ( hash( 'sha256', wp_json_encode( $current ) ) !== $expected ) {
	throw new RuntimeException( 'The Bricks layout changed since it was read. Read it again.' );
}
$next = array();
foreach ( $current as $element ) {
	if ( ! in_array( $element['id'], $patch['remove'] ?? array(), true ) ) {
		$next[ $element['id'] ] = $element;
	}
}
foreach ( $patch['upsert'] ?? array() as $element ) {
	$anchor = $patch['after'][ $element['id'] ] ?? null;
	if ( isset( $next[ $element['id'] ] ) || null === $anchor || ! isset( $next[ $anchor ] ) ) {
		$next[ $element['id'] ] = $element;
		continue;
	}
	$placed = array();
	foreach ( $next as $id => $existing ) {
		$placed[ $id ] = $existing;
		if ( $id === $anchor ) {
			$placed[ $element['id'] ] = $element;
		}
	}
	$next = $placed;
}
foreach ( $next as $id => $element ) {
	foreach ( $element['children'] ?? array() as $child ) {
		if ( ! isset( $next[ $child ] ) || (string) ( $next[ $child ]['parent'] ?? '' ) !== (string) $id ) {
			throw new RuntimeException( "Child $child of $id is missing or does not point back." );
		}
	}
	$parent = (string) ( $element['parent'] ?? 0 );
	if ( '0' !== $parent && ( ! isset( $next[ $parent ] ) || ! in_array( $id, $next[ $parent ]['children'] ?? array(), true ) ) ) {
		throw new RuntimeException( "Element $id is not listed by its parent $parent." );
	}
}
$next = array_values( $next );
update_post_meta( $post_id, $key, wp_slash( $next ) );
update_post_meta( $post_id, '_bricks_editor_mode', 'bricks' );
$saved = get_post_meta( $post_id, $key, true );
return array( 'identical' => $saved === $next, 'sha256' => hash( 'sha256', wp_json_encode( $saved ) ), 'elements' => count( $saved ) );
```

Use a nowdoc (`<<<'JSON'`) so PHP leaves `$` and backslashes in the JSON alone. `identical: false` means something changed the data on save: apply the inverse patch from step 2 and report it. An exception means nothing was written.

**5. Executable code.** Bricks runs PHP from Code elements, query editor PHP and `{echo:}` tags only with a signature that Bricks creates when an account with its Execute code capability saves in the builder. A meta write cannot create one, so new or changed executable code renders nothing or an error placeholder. Leave signed elements (`code: true` in the outline) unchanged, and add executable code through Bricks abilities or ask the user to add it in the builder.

**6. Global classes.** A meta write does not merge classes. Add only missing class objects to the `bricks_global_classes` option, and when a class with the same name already exists, reference its existing ID instead of adding a duplicate. Snapshot the option before changing it. Unlike post meta, `update_option()` does not unslash, so pass the plain array and never `wp_slash()` it.

**7. Regenerate CSS** when `bricks_global_settings` has `cssLoading` set to `file`. Rebuild only this post's file with `execute-php`: `return \Bricks\Assets_Files::generate_post_css_file( 123, 'content', get_post_meta( 123, '_bricks_page_content_2', true ) );` (area `header` or `footer` with those keys). `wp bricks regenerate_assets` takes no arguments and rewrites every CSS file on the site; keep it for site-wide changes such as global classes or theme styles. Then check the front end with a cache-busting query string.

## Validation checklist

- [ ] JSON parses.
- [ ] Every `parent` and `children` reference resolves; IDs are unique 6-character alphanumerics.
- [ ] Clipboard format has `source: "bricksCopiedElements"` and `version`.
- [ ] Every `_cssGlobalClasses` ID has a matching class object.
- [ ] Value shapes match style-settings.md (spot-check colors, typography, spacing).
- [ ] Responsive keys use breakpoint names that exist on the site.
- [ ] Query loops have `hasLoop: true` and `query.objectType`; filters point at a real query element ID through `filterQueryId`.
- [ ] Dynamic data tags exist for the stated field provider (for example, `{acf_*}` names match the site's field names).

The references include a Python integrity check for local use. Over Site Agent, the patch snippet checks tree integrity on the server; for clipboard JSON, reason over the array or run `json_decode` plus a loop over `parent` and `children` with `execute-php`.

## Completion report

Say which route you used, the post ID and meta key or Bricks abilities called, whether the post is a draft or live, the checks that passed, whether CSS was regenerated, and what is unverified (builder rendering, front-end caching).
