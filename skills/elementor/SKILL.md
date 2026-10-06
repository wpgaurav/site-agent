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

Tool names below omit the `site-agent-` prefix your client shows: `execute-php` is `site-agent-execute-php`.

1. **Confirm the target.** `site-context` lists Elementor in `builders` with `version` and `pro_version`. `get-content` must show `builder: elementor`. The posts page and the WooCommerce shop page can never be edited with Elementor.
2. **Read the outline.** Run the outline snippet, page through long documents with `$offset`, and note `data_sha256`.
3. **Read what you will change.** Return the complete elements you will replace or remove (each comes with its children, so pick the smallest elements that cover the change) and keep them as the rollback copy.
4. **Check what the site can render.** Every `elType` and `widgetType` you plan to write must be registered. `Document::save()` silently deletes unknown elements, which covers Pro widgets without Pro, widgets from inactive plugins, containers when the container experiment is off, and V4 atomic elements when that experiment is off. The type check snippet lists what is missing.
5. **Patch.** Run the patch snippet with the hash from step 2. Keep existing element IDs, settings and `__globals__` references unchanged unless the task covers them. New IDs must be unique lowercase hex within the document (the editor generates 7 characters). For published or private pages, the default writes to an Elementor autosave for review; pass `$stage = false` only when the user asked for a live change.
6. **Verify.** Compare `elements_sent` with `elements_saved` from the patch, re-run the outline (it reads your newer autosave, so staged edits show up) and check the IDs you added or changed. Then load the front end with a cache-busting query string; outside caches (page cache plugins, hosts, CDNs) are not cleared by Elementor.

## Snippets for `execute-php`

`execute-php` accepts at most 65,536 characters of code and returns at most 64 KiB of JSON. Whole Elementor documents often exceed both, so these snippets read an outline, read single elements, and send only a patch; PHP rebuilds the full tree on the server.

**Outline.** `data_sha256` hashes the live data and guards every patch. The outline comes from your newer autosave when one exists, because staged patches build on it.

```php
$id     = 123;
$offset = 0;
$doc    = \Elementor\Plugin::$instance->documents->get( $id, false );
if ( ! $doc ) {
	return 'Not an Elementor document.';
}
$plugin   = \Elementor\Plugin::$instance;
$autosave = $doc->get_newer_autosave();
$outline  = array();
$walk     = function ( $elements, $parent, $depth ) use ( &$walk, &$outline ) {
	foreach ( $elements as $index => $element ) {
		$text      = $element['settings']['title'] ?? $element['settings']['editor'] ?? '';
		$outline[] = array(
			'id'       => $element['id'],
			'parent'   => $parent,
			'index'    => $index,
			'depth'    => $depth,
			'type'     => $element['widgetType'] ?? $element['elType'],
			'children' => count( $element['elements'] ?? array() ),
			'text'     => is_string( $text ) ? mb_substr( wp_strip_all_tags( $text ), 0, 50 ) : '',
		);
		$walk( $element['elements'] ?? array(), $element['id'], $depth + 1 );
	}
};
$walk( $doc->get_elements_data( 'draft' ), '', 0 );
return array(
	'built_with_elementor' => $doc->is_built_with_elementor(),
	'document_type'        => $doc->get_name(),
	'status'               => get_post_status( $id ),
	'elementor_version'    => get_post_meta( $id, '_elementor_version', true ),
	'newer_autosave_id'    => $autosave ? $autosave->get_post()->ID : 0,
	'data_sha256'          => hash( 'sha256', (string) get_post_meta( $id, '_elementor_data', true ) ),
	'count'                => count( $outline ),
	'outline'              => array_slice( $outline, $offset, 150 ),
	'experiments'          => array(
		'container'         => $plugin->experiments->is_feature_active( 'container' ),
		'e_atomic_elements' => $plugin->experiments->is_feature_active( 'e_atomic_elements' ),
	),
);
```

**Read elements by ID.**

```php
$id    = 123;
$ids   = array( 'a1b2c3d' );
$found = array();
$walk  = function ( $elements ) use ( &$walk, &$found, $ids ) {
	foreach ( $elements as $element ) {
		if ( in_array( $element['id'], $ids, true ) ) {
			$found[] = $element;
		}
		$walk( $element['elements'] ?? array() );
	}
};
$walk( \Elementor\Plugin::$instance->documents->get( $id, false )->get_elements_data( 'draft' ) );
return $found;
```

**Type check.** List the widget types you plan to use.

```php
$plan   = array( 'heading', 'button' );
$plugin = \Elementor\Plugin::$instance;
return array(
	'missing_widgets' => array_values( array_diff( $plan, array_keys( $plugin->widgets_manager->get_widget_types() ) ) ),
	'element_types'   => array_keys( $plugin->elements_manager->get_element_types() ),
);
```

**Patch (hash-checked).** `replace` holds complete elements that replace the element with the same ID, children included. `insert` places a new element under a parent ID (empty for the top level) at a zero-based index. `remove` lists IDs to delete with their children.

```php
$id       = 123;
$expected = 'data_sha256 from the outline';
$stage    = true; // false only when the user asked to change the live page
$json     = <<<'JSON'
{ "replace": [], "insert": [], "remove": [] }
JSON;
$patch = json_decode( $json, true, 512, JSON_THROW_ON_ERROR );
$doc   = \Elementor\Plugin::$instance->documents->get( $id, false );
if ( ! $doc || ! $doc->is_editable_by_current_user() ) {
	throw new RuntimeException( 'This document is not editable by the current user.' );
}
if ( ! hash_equals( $expected, hash( 'sha256', (string) get_post_meta( $id, '_elementor_data', true ) ) ) ) {
	throw new RuntimeException( 'The Elementor data changed since it was read. Read it again.' );
}
$live    = in_array( get_post_status( $id ), array( 'publish', 'private', 'future' ), true );
$staged  = $live && $stage;
$replace = array_column( $patch['replace'] ?? array(), null, 'id' );
$remove  = $patch['remove'] ?? array();
$done    = array();
$apply   = function ( $elements, $parent ) use ( &$apply, &$done, $replace, $remove, $patch ) {
	$out = array();
	foreach ( $elements as $element ) {
		if ( in_array( $element['id'], $remove, true ) ) {
			$done[] = $element['id'];
			continue;
		}
		if ( isset( $replace[ $element['id'] ] ) ) {
			$element = $replace[ $element['id'] ];
			$done[]  = $element['id'];
		}
		$element['elements'] = $apply( $element['elements'] ?? array(), $element['id'] );
		$out[]               = $element;
	}
	foreach ( $patch['insert'] ?? array() as $insert ) {
		if ( (string) $insert['parent'] === (string) $parent ) {
			array_splice( $out, min( (int) $insert['index'], count( $out ) ), 0, array( $insert['element'] ) );
			$done[] = $insert['element']['id'];
		}
	}
	return $out;
};
// A staged patch builds on your newer autosave, so successive staged patches accumulate.
$elements = $apply( $doc->get_elements_data( $staged ? 'draft' : 'publish' ), '' );
$wanted   = array_merge( array_keys( $replace ), $remove, array_map( function ( $insert ) {
	return $insert['element']['id'];
}, $patch['insert'] ?? array() ) );
$missing  = array_diff( $wanted, $done );
if ( $missing ) {
	throw new RuntimeException( 'Not found: ' . implode( ', ', $missing ) . '. Nothing was saved.' );
}
$target = $staged ? $doc->get_autosave( 0, true ) : $doc;
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
	'data_sha256'     => hash( 'sha256', (string) get_post_meta( $id, '_elementor_data', true ) ),
	'elements_sent'   => $count( $elements ),
	'elements_saved'  => $count( json_decode( $stored, true ) ?: array() ),
);
```

Use a nowdoc (`<<<'JSON'`) so PHP leaves `$` and backslashes in the JSON alone. `saved: false` means the current user cannot edit the document. `elements_saved` lower than `elements_sent` means Elementor dropped unregistered element types; undo with a patch built from the copies you read in step 3, or remove those elements, and tell the user which ones. A staged save is stored on the autosave revision; the user reviews it in the Elementor editor and publishes it there. The returned `data_sha256` is the live hash, unchanged by a staged save, for the next patch.

**Page settings.** Passing `settings` to `save()` replaces all page settings. Merge first: `'settings' => array_replace_recursive( $doc->get_db_document_settings(), $changes )`. Settings are written before elements, so a later element validation error (V4) can leave new settings saved; snapshot both.

**Converting a classic page.** `save()` does not set `_elementor_edit_mode`; the patch snippet sets it when missing. Converting replaces what visitors see with the Elementor tree, so do it only when asked.

## Rules

- Classic and V4 data differ. Classic widgets use plain settings (`"title": "Hello"`), V4 atomic elements use typed props (`{"$$type": "string", "value": "h1"}`) and a `styles` map. Match what the page already uses; see the data-format reference.
- Prefer the kit's global colors and fonts through `__globals__` over literal values, so site-wide changes still apply.
- Accounts without `unfiltered_html` (for example when the site sets `DISALLOW_UNFILTERED_HTML`) get their element data passed through kses on save. Site Agent requires an administrator, or a super administrator on multisite, who otherwise has it.
- Elementor ships its own MCP abilities in 4.x (`elementor/get-page-structure`, `elementor/manage-elements` and others). They are separate from Site Agent and only active when the site owner enabled them; do not assume they exist.

## Completion report

State the post ID, whether the save went to the live document or an autosave (and its ID), the element count before and after, the checks that passed, which caches were cleared, and what is unverified (editor appearance, Pro-only behavior, outside caches).
