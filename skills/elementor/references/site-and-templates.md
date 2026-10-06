# Elementor templates, kit, caches and WP-CLI

Verified against Elementor `release/stable` at `3301e5cd` (4.3 line). Pro behavior is marked unverified.

## Templates

- Templates are `elementor_library` posts. The type is stored in `_elementor_template_type` and the `elementor_library_type` taxonomy; categories use `elementor_library_category`.
- Core types include `page`, `section`, `container` (with the container experiment), `kit`, `landing-page` and `floating-buttons`. Pro adds `header`, `footer`, `single`, `archive`, `popup`, `loop-item` and others (unverified).
- Edit a template like a page: read and write it with the snippets in SKILL.md using the template's post ID. A change shows up on every page that uses the template, so clear all CSS afterwards (below).
- Export and import JSON: `{"content": [elements], "page_settings": {...}, "version": "0.4", "title": "...", "type": "section"}`. Import regenerates element IDs and drops unknown widgets. `run-wp-cli` with `["elementor", "library", "import", "<file-path-or-url>"]` imports a file the server can reach. Core has no `library export` command.
- Theme Builder display conditions (Pro) are stored in `_elementor_conditions` as strings such as `include/general`, cached site-wide in the `elementor_pro_theme_builder_conditions` option. Change conditions in the editor; the full grammar and cache regeneration are unverified.

## Kit: global colors and fonts

- The active kit's post ID is the `elementor_active_kit` option. The kit is an `elementor_library` post of type `kit`.
- Its `_elementor_page_settings` hold `system_colors` and `custom_colors` (rows `{"_id": "primary", "title": "Primary", "color": "#6EC1E4"}`) and `system_typography` and `custom_typography` (rows with `typography_typography: "custom"` and the font fields).
- These become CSS variables `--e-global-color-{_id}` and `--e-global-typography-{_id}-{property}`.
- Change the kit with its document save, merging settings, because a partial `settings` array replaces all of them:

```php
$kits    = \Elementor\Plugin::$instance->kits_manager;
$kit     = $kits->get_active_kit();
$current = $kit->get_db_document_settings();
$current = is_array( $current ) ? $current : array(); // Empty until Site Settings is first saved.
// Effective colors, including Elementor's defaults when nothing is stored yet.
$changes = array( 'system_colors' => $kits->get_current_settings( 'system_colors' ) );
foreach ( $changes['system_colors'] as &$row ) {
	if ( 'primary' === $row['_id'] ) {
		$row['color'] = '#1b2733';
	}
}
unset( $row );
return $kit->save( array( 'settings' => array_replace_recursive( $current, $changes ) ) );
```

Saving the kit clears CSS for the whole site, which regenerates on the next page views. Snapshot `$current` first; an empty snapshot means the site used Elementor's defaults. Repeater rows such as `system_colors` are lists: replace the whole list as above, because `array_replace_recursive` merges lists by position.

## Caches

`Document::save()` clears the saved page's CSS file and element cache. Anything else needs a manual purge.

- **Element cache** stores each document's rendered HTML in `_elementor_element_cache` for 24 hours by default (`elementor_element_cache_ttl` option; `disable` turns it off). It is on by default and is not an experiment.
- **One page** (after a raw write, PHP): `\Elementor\Core\Files\CSS\Post::create( $id )->delete(); delete_post_meta( $id, '_elementor_element_cache' );`
- **Whole site** (after template or kit changes, or any raw write that affects several pages): `\Elementor\Plugin::$instance->files_manager->clear_cache();`, or `run-wp-cli` with `["elementor", "flush-css"]` (add `--regenerate` to rebuild CSS immediately). This deletes `uploads/elementor/css/*` and every `_elementor_css`, `_elementor_element_cache` and `_elementor_page_assets` entry.
- Page cache plugins, host caches and CDNs are separate; Elementor does not clear them.

## WP-CLI commands in core

Run these with `run-wp-cli` as an argument array, for example `["elementor", "experiments", "status", "container"]`. Site Agent already runs WP-CLI as the authenticated user.

| Command | Effect |
|---|---|
| `elementor flush-css [--regenerate] [--network]` | Clear all Elementor CSS and element caches, optionally regenerating CSS |
| `elementor replace-urls <old> <new> [--force]` | Replace URLs inside `_elementor_data`, then clear caches |
| `elementor system-info` | Environment report as JSON |
| `elementor experiments status <name>` | Whether an experiment such as `container` or `e_atomic_elements` is active |
| `elementor experiments activate <names>` / `deactivate <names>` | Change experiments; this changes how existing pages load, so only on request |
| `elementor library sync [--force]` | Refresh the remote template library |
| `elementor library import <file-or-url>` / `import-dir <dir>` | Import template JSON |
| `elementor kit export <file.zip>` / `kit import <zip>` / `kit revert` | Whole-site kit transfers; imports overwrite site settings, so only on request |
| `elementor update db [--force]` | Run Elementor database upgrades |
