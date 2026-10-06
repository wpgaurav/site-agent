---
name: bricks
description: Author and edit Bricks Builder layouts, templates and full designs on a live WordPress site through Site Agent, as paste-ready JSON or hash-checked post meta writes. Use for Bricks sections, pages, headers and footers, query loops, ACF and dynamic data, filters, conditions, interactions, popups, WooCommerce templates, custom elements and hooks.
---

# Bricks Builder through Site Agent

Bricks stores each layout as a flat element array in post meta, not in `post_content`. A post whose `get-content` result shows `builder: bricks` renders from `_bricks_page_content_2` (or the header and footer keys for those templates), so `save-content` cannot change its layout.

This skill is adapted from the author's `bricks-skills` repository, verified there against the Bricks 2.3.6 source. Its references and patterns are bundled verbatim; this file is written for Site Agent. Check the site's Bricks version in `site-context` and say so when it differs from 2.3.6.

## Choose the deliverable

1. **Paste-ready JSON (default).** Return clipboard JSON the user pastes into the Bricks structure panel. Bricks regenerates IDs, merges global classes by name and builds CSS on save. This needs no write tools and is the safest route for new sections. Template exports (header, footer, popup) import through Bricks > Templates > Import.
2. **Direct write (on request).** Write the element array to post meta with `execute-php`, using the hash-checked procedure below. Use this when the user asks for the change to be applied on the site. It needs the PHP execution tool group, which runs unsandboxed PHP; prefer a staging site.

## Workflow

0. **Clarify inputs first.**
   - **rem base.** Confirm what `1rem` equals on the target site. Default to 16px when the user does not say; a `html { font-size: 62.5% }` reset makes it 10px. See [style-settings.md](references/style-settings.md).
   - **Reference URL.** If the user gives one, reference its images, video, SVG and icons by absolute URL. See [external-assets.md](references/external-assets.md). For assets that belong on the site, import them with `upload-media` and use the media library URL.
   - **Site conventions.** With PHP execution enabled, read `bricks_global_classes`, `bricks_global_variables` and `bricks_color_palette` and reuse existing classes and variables instead of inventing parallel ones.
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
8. **Do not invent settings keys.** Only keys documented in the references or found in the installed Bricks source. Bricks silently ignores unknown keys.
9. **Confirm the rem base before using rem**, and convert px values from a reference with it.
10. **Reference external assets by absolute `url`, never by attachment `id`** from another site, and respect asset licensing.

## Direct write procedure

A post meta write takes effect immediately; there is no autosave staging as with `save-content`. For a published page, write to a draft copy unless the user asked for a live change.

**1. Read and snapshot.** Return the current array and its hash. Keep the returned JSON as the rollback copy.

```php
$post_id = 123;
$key     = '_bricks_page_content_2'; // _bricks_page_header_2 or _bricks_page_footer_2 for those templates
$current = get_post_meta( $post_id, $key, true );
return array(
	'editor_mode' => get_post_meta( $post_id, '_bricks_editor_mode', true ),
	'sha256'      => hash( 'sha256', wp_json_encode( $current ) ),
	'elements'    => $current,
);
```

**2. Validate the new array** against the checklist below. Splice new elements into the existing array; keep untouched elements, their IDs and settings exactly as read.

**3. Write with a hash check.** `update_post_meta()` unslashes its value, which strips backslashes from settings such as custom CSS (`content:"\f101"` becomes `content:"f101"`), so always pass the array through `wp_slash()`.

```php
$post_id  = 123;
$key      = '_bricks_page_content_2';
$expected = 'sha256 from step 1';
if ( hash( 'sha256', wp_json_encode( get_post_meta( $post_id, $key, true ) ) ) !== $expected ) {
	throw new RuntimeException( 'The Bricks layout changed since it was read. Read it again.' );
}
$json = <<<'JSON'
[ ...the complete element array... ]
JSON;
$elements = json_decode( $json, true, 512, JSON_THROW_ON_ERROR );
update_post_meta( $post_id, $key, wp_slash( $elements ) );
update_post_meta( $post_id, '_bricks_editor_mode', 'bricks' );
$saved = get_post_meta( $post_id, $key, true );
return array( 'identical' => $saved === $elements, 'sha256' => hash( 'sha256', wp_json_encode( $saved ) ), 'elements' => count( $saved ) );
```

Use a nowdoc (`<<<'JSON'`) so PHP leaves `$` and backslashes in the JSON alone. `identical: false` means something changed the data on save; restore the snapshot and report it.

**4. Global classes.** A meta write does not merge classes. Add only missing class objects to the `bricks_global_classes` option, and when a class with the same name already exists, reference its existing ID instead of adding a duplicate. Snapshot the option before changing it. Unlike post meta, `update_option()` does not unslash, so pass the plain array and never `wp_slash()` it.

**5. Regenerate CSS** when the site's CSS loading method is external files (`bricks_global_settings` `cssLoading` set to `file`): `run-wp-cli` with `["bricks", "regenerate_assets", "--post_id=123"]`, or ask the user to use Bricks > Settings > Performance. Then check the front end with a cache-busting query string.

## Validation checklist

- [ ] JSON parses.
- [ ] Every `parent` and `children` reference resolves; IDs are unique 6-character alphanumerics.
- [ ] Clipboard format has `source: "bricksCopiedElements"` and `version`.
- [ ] Every `_cssGlobalClasses` ID has a matching class object.
- [ ] Value shapes match style-settings.md (spot-check colors, typography, spacing).
- [ ] Responsive keys use breakpoint names that exist on the site.
- [ ] Query loops have `hasLoop: true` and `query.objectType`; filters point at a real query element ID through `filterQueryId`.
- [ ] Dynamic data tags exist for the stated field provider (for example, `{acf_*}` names match the site's field names).

The references include a Python integrity check for local use. Over Site Agent, run the same checks by reasoning over the array, or with `execute-php` (`json_decode` plus a loop over `parent` and `children`).

## Completion report

Say which deliverable you produced, the post ID and meta key written (if any), whether the post is a draft or live, the checks that passed, whether CSS was regenerated, and what is unverified (builder rendering, front-end caching).
