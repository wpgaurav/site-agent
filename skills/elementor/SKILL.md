---
name: elementor
description: Read, build and edit Elementor pages, templates and site settings on a live WordPress site through Site Agent, using Elementor's own document save so CSS, caches, revisions and validation stay correct. Use for Elementor containers, sections, widgets, Editor V4 atomic elements, templates, global colors and fonts, and Elementor cache problems.
---

# Elementor through Site Agent

Elementor keeps each layout as a JSON element tree in the `_elementor_data` post meta. `post_content` is only a plain-text fallback that Elementor regenerates on every save, so `save-content` cannot change what visitors see on a page whose `get-content` result shows `builder: elementor`.

Facts in this skill were verified against Elementor's public source (`release/stable` at commit `3301e5cd`, the 4.3 line, October 2026). Elementor Pro is not public; Pro details are marked unverified. Compare the site's version from `site-context` and say so when it differs a lot.

Read [references/data-format.md](references/data-format.md) before writing element JSON. Read [references/site-and-templates.md](references/site-and-templates.md) for templates, the kit (global colors and fonts), caches and WP-CLI commands.

## Choose the route

1. **Advice or JSON for the user (no write tools).** Describe the change, or produce an Elementor template export file (`{content, page_settings, version, title, type}`) the user imports under Templates > Saved Templates > Import. Elementor regenerates element IDs on import.
2. **Direct change (needs PHP execution).** Save through Elementor's `Document::save()` with `execute-php`, using the procedure below. This is the only route that validates elements, regenerates `post_content`, records a revision, and clears the page's CSS and element cache.
3. **Never write `_elementor_data` as raw meta.** Site Agent's `save-content` refuses Elementor, Bricks and Divi layout meta with a `builder_meta` error for this reason; do not work around it with `update_post_meta()`. A raw write skips validation and kses, leaves the old CSS file in place, and the element cache keeps serving the old HTML for up to 24 hours by default. If something already wrote raw data, clear caches as described in the site reference.

## Workflow

1. **Confirm the target.** `site-context` lists Elementor in `builders` with `version` and `pro_version`. `get-content` must show `builder: elementor`. The posts page and the WooCommerce shop page can never be edited with Elementor.
2. **Read and snapshot.** Run the read snippet. Keep the returned `elements` and `settings` as the rollback copy, and note `data_sha256`.
3. **Check what the site can render.** Every `elType` and `widgetType` you plan to write must be registered. `Document::save()` silently deletes unknown elements, which covers Pro widgets without Pro, widgets from inactive plugins, containers when the container experiment is off, and V4 atomic elements when that experiment is off. The read snippet returns the registered types and experiment states.
4. **Edit the tree.** Splice changes into the elements you read. Keep existing element IDs, settings and `__globals__` references unchanged unless the task covers them. New IDs must be unique lowercase hex within the document (the editor generates 7 characters).
5. **Save.** Run the write snippet with the hash from step 2. For published or private pages, the default writes to an Elementor autosave for review; pass `$stage = false` only when the user asked for a live change.
6. **Verify.** Compare `elements_sent` with `elements_saved` from the write. Then re-run the read snippet (for a staged save, read the autosave ID the write returned) and check the IDs you added or changed and their settings values. Then load the front end with a cache-busting query string; outside caches (page cache plugins, hosts, CDNs) are not cleared by Elementor.

## Snippets for `execute-php`

**Read.**

```php
$id  = 123;
$doc = \Elementor\Plugin::$instance->documents->get( $id, false );
if ( ! $doc ) {
	return 'Not an Elementor document.';
}
$plugin = \Elementor\Plugin::$instance;
return array(
	'built_with_elementor' => $doc->is_built_with_elementor(),
	'document_type'        => $doc->get_name(),
	'status'               => get_post_status( $id ),
	'elementor_version'    => get_post_meta( $id, '_elementor_version', true ),
	'data_sha256'          => hash( 'sha256', (string) get_post_meta( $id, '_elementor_data', true ) ),
	'elements'             => $doc->get_elements_data(),
	'settings'             => $doc->get_db_document_settings(),
	'element_types'        => array_keys( $plugin->elements_manager->get_element_types() ),
	'widget_types'         => array_keys( $plugin->widgets_manager->get_widget_types() ),
	'experiments'          => array(
		'container'         => $plugin->experiments->is_feature_active( 'container' ),
		'e_atomic_elements' => $plugin->experiments->is_feature_active( 'e_atomic_elements' ),
	),
);
```

The widget list is long; drop `widget_types` from the return value after the first read if you do not need it.

**Write (hash-checked).**

```php
$id       = 123;
$expected = 'data_sha256 from the read';
$stage    = true; // false only when the user asked to change the live page
$json     = <<<'JSON'
[ ...the complete element array... ]
JSON;
$elements = json_decode( $json, true, 512, JSON_THROW_ON_ERROR );
$doc      = \Elementor\Plugin::$instance->documents->get( $id, false );
if ( ! $doc || ! $doc->is_editable_by_current_user() ) {
	throw new RuntimeException( 'This document is not editable by the current user.' );
}
if ( ! hash_equals( $expected, hash( 'sha256', (string) get_post_meta( $id, '_elementor_data', true ) ) ) ) {
	throw new RuntimeException( 'The Elementor data changed since it was read. Read it again.' );
}
$live   = in_array( get_post_status( $id ), array( 'publish', 'private', 'future' ), true );
$target = $live && $stage ? $doc->get_autosave( 0, true ) : $doc;
$saved  = $target->save( array( 'elements' => $elements ) );
if ( ! $doc->is_built_with_elementor() ) {
	$doc->set_is_built_with_elementor( true );
}
$written = $target->get_post()->ID;
$stored  = (string) get_metadata( 'post', $written, '_elementor_data', true );
$count   = function ( $list ) use ( &$count ) {
	$total = 0;
	foreach ( (array) $list as $element ) {
		$total += 1 + $count( $element['elements'] ?? array() );
	}
	return $total;
};
return array(
	'saved'           => $saved,
	'written_post_id' => $written,
	'staged'          => $written !== $id,
	'data_sha256'     => hash( 'sha256', $stored ),
	'elements_sent'   => $count( $elements ),
	'elements_saved'  => $count( json_decode( $stored, true ) ?: array() ),
);
```

Use a nowdoc (`<<<'JSON'`) so PHP leaves `$` and backslashes in the JSON alone. `saved: false` means the current user cannot edit the document. `elements_saved` lower than `elements_sent` means Elementor dropped unregistered element types; restore the snapshot or remove those elements, and tell the user which ones. A staged save is stored on the autosave revision; the user reviews it in the Elementor editor and publishes it there.

**Page settings.** Passing `settings` to `save()` replaces all page settings. Merge first: `'settings' => array_replace_recursive( $doc->get_db_document_settings(), $changes )`. Settings are written before elements, so a later element validation error (V4) can leave new settings saved; snapshot both.

**Converting a classic page.** `save()` does not set `_elementor_edit_mode`; the write snippet sets it when missing. Converting replaces what visitors see with the Elementor tree, so do it only when asked.

## Rules

- Classic and V4 data differ. Classic widgets use plain settings (`"title": "Hello"`), V4 atomic elements use typed props (`{"$$type": "string", "value": "h1"}`) and a `styles` map. Match what the page already uses; see the data-format reference.
- Prefer the kit's global colors and fonts through `__globals__` over literal values, so site-wide changes still apply.
- Users without `unfiltered_html` (any role on multisite except super administrators) get their element data passed through kses on save.
- Elementor ships its own MCP abilities in 4.x (`elementor/get-page-structure`, `elementor/manage-elements` and others). They are separate from Site Agent and only active when the site owner enabled them; do not assume they exist.

## Completion report

State the post ID, whether the save went to the live document or an autosave (and its ID), the element count before and after, the checks that passed, which caches were cleared, and what is unverified (editor appearance, Pro-only behavior, outside caches).
