# Divi 4 shortcode format

Evidence: Divi's own PHP source (the Divi 4 builder framework that still ships inside Divi 5, read at 5.8.1). Latest Divi 4 release found: 4.27.8 (August 2026).

## Structure

```text
[et_pb_section admin_label="Hero"]
	[et_pb_row column_structure="1_2,1_2"]
		[et_pb_column type="1_2"]
			[et_pb_text]<p>Fast hosting for WordPress.</p>[/et_pb_text]
		[/et_pb_column]
		[et_pb_column type="1_2"]
			[et_pb_image src="https://example.com/wp-content/uploads/hero.jpg" alt="Server rack"][/et_pb_image]
		[/et_pb_column]
	[/et_pb_row]
[/et_pb_section]
```

- `et_pb_section` contains `et_pb_row`, which contains `et_pb_column`, which contains modules (`et_pb_text`, `et_pb_button`, `et_pb_image`, `et_pb_blurb` and so on).
- Fullwidth sections (`fullwidth="on"`) hold `et_pb_fullwidth_*` modules directly, with no rows or columns.
- Specialty sections (`specialty="on"`) hold `et_pb_column` elements with `specialty_columns`, which contain `et_pb_row_inner` and `et_pb_column_inner`.
- Column `type` values: `4_4`, `1_2`, `1_3`, `2_3`, `1_4`, `3_4`, `1_5`, `2_5`, `3_5`, `1_6`. A row's `column_structure` lists them (`1_3,2_3`, `1_4,1_2,1_4` and so on), and the columns inside must match it.
- Every structural tag is closed explicitly. Keep each structural tag on its own line or directly adjacent to the next, and never wrap them in `<p>`: Divi strips the paragraph and line-break tags that `wpautop` adds around its shortcodes, but hand-written wrappers break the layout.

## Attributes

- Common: `admin_label`, `_builder_version`, `_module_preset` (often `default`), `global_colors_info`.
- Spacing: `custom_padding` and `custom_margin` are pipe lists, `top|right|bottom|left|linked-top-bottom|linked-left-right`, for example `custom_padding="40px||40px||true|false"`.
- Responsive: `<attribute>_tablet` and `<attribute>_phone` hold the device values, and `<attribute>_last_edited="on|phone"` turns responsive output on. Phone falls back to tablet, then desktop.
- Hover and sticky: `<attribute>__hover` with `<attribute>__hover_enabled`, and `<attribute>__sticky` with `<attribute>__sticky_enabled`.

## Encoding rules

- Never put a literal `"`, `[` or `]` inside an attribute value. Divi encodes `"` as `%22` and `[`, `]` as `%91`, `%93`; a literal `]` ends the shortcode early. `global_colors_info` is JSON written with those encodings.
- Backslashes are encoded as `%92` in custom CSS fields, form option JSON and date formats (`%5c` in `breadcrumb_separator`).
- In content, Divi stores ` target=` on links as ` data-et-target-link=` and restores it when rendering. Keep the stored form when editing existing content.
- Code modules (`et_pb_code`, `et_pb_fullwidth_code`) store line breaks as `<!-- [et_pb_line_break_holder] -->` and square brackets as `&#091;` and `&#093;`. Keep that encoding when editing code inside them.
- Dynamic content tokens look like `@ET-DC@...@`. Keep them intact.

## Where text lives

The main body sits between the opening and closing shortcode for `accordion_item`, `blurb`, `code`, `counter`, `cta`, `fullwidth_code`, `fullwidth_header`, `login`, `map_pin`, `pricing_table`, `slide`, `social_media_follow_item`, `tab`, `team_member`, `testimonial`, `text` and `toggle` (all with the `et_pb_` prefix). Other text is in attributes such as `title`, `heading`, `button_text`, `button_url`, `url`, `src` and `alt`.

## Meta

| Key | Meaning |
|---|---|
| `_et_pb_use_builder` = `on` | The post renders through Divi; without it the shortcodes show as raw text. |
| `_et_pb_old_content` | The content from before the builder was first enabled. |
| `_et_builder_version` | The builder that last saved, for example `VB\|Divi\|4.27.4`. |
| `_et_pb_page_layout` | `et_right_sidebar`, `et_left_sidebar`, `et_full_width_page` or `et_no_sidebar`. |
| `_et_pb_custom_css` | Page-level custom CSS. |

Only `_et_pb_use_builder` and `_et_pb_old_content` are registered for REST (when Divi's block editor integration has loaded), so most of these need `execute-php` to change. Use `update_post_meta()` with `wp_slash()` on values containing backslashes.

## Library and Theme Builder

- Library items are `et_pb_layout` posts, with the `scope` taxonomy (`global` for synced items) and `layout_type` (`module`, `row`, `section`, `layout`). A page references a synced item with `global_module="<ID>"`, and the library post's settings override the page copy except options listed in its `_et_pb_excluded_global_options` meta. Edit the library post.
- The Theme Builder uses `et_theme_builder` (the live set is published), `et_template` (one per template, with `_et_header_layout_id`, `_et_body_layout_id`, `_et_footer_layout_id`, `_et_use_on`, `_et_exclude_from`, `_et_enabled` and `_et_default` meta) and `et_header_layout`, `et_body_layout`, `et_footer_layout` posts that hold the layouts themselves.
- None of these post types are REST-enabled, so read and write them with `execute-php` following the hash-checked snippet in SKILL.md. Change template assignments only on explicit request.
