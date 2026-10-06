# Divi 5 content format

Evidence: Divi 5.8.1 PHP source and Elegant Themes' official GitHub repositories `d5-extension-example-modules`, `d5-example-core-modules` and `d5-dev-tool` (September and October 2026). Divi 5.13 announced "Components and Slots" as a rework of global elements, so re-check global element storage on newer versions.

## Wrapper and nesting

The whole layout sits in exactly one placeholder block:

```html
<!-- wp:divi/placeholder -->
<!-- wp:divi/section {"module":{"meta":{"adminLabel":{"desktop":{"value":"Hero"}}}}} -->
<!-- wp:divi/row {"module":{"advanced":{"columnStructure":{"desktop":{"value":"1_2,1_2"}}}}} -->
<!-- wp:divi/column {"module":{"advanced":{"type":{"desktop":{"value":"1_2"}}}}} -->
<!-- wp:divi/text {"content":{"innerContent":{"desktop":{"value":"\u003cp\u003eFast hosting for WordPress.\u003c/p\u003e"}}}} /-->
<!-- /wp:divi/column -->
<!-- wp:divi/column {"module":{"advanced":{"type":{"desktop":{"value":"1_2"}}}}} -->
<!-- wp:divi/text {"content":{"innerContent":{"desktop":{"value":"\u003cp\u003eSetup takes about ten minutes.\u003c/p\u003e"}}}} /-->
<!-- /wp:divi/column -->
<!-- /wp:divi/row -->
<!-- /wp:divi/section -->
<!-- /wp:divi/placeholder -->
```

- Divi's own code notes that without the placeholder wrapper, re-serialization keeps only the first row. Keep one wrapper and nothing outside it.
- `divi/section` contains `divi/row`, which contains `divi/column`, which contains modules. Specialty sections use `divi/row-inner` and `divi/column-inner`. Fullwidth and specialty sections are `divi/section` with `module.advanced.type` set to `fullwidth` or `specialty`; fullwidth sections hold `divi/fullwidth-*` modules directly.
- Column types in a row must add up to the row's `columnStructure`, using the Divi 4 values (`4_4`, `1_2`, `1_3`, `2_3`, `1_4`, `3_4`, `1_5`, `2_5`, `3_5`, `1_6`).
- Core `parse_blocks()` reads this format; Divi's parser extends core's `WP_Block_Parser`.
- The text values above use WordPress's escapes inside the JSON. Element and key names vary by module (images, buttons and blurbs each have their own); copy them from an existing instance on the site or from the installed module's `module.json`.

## Escaping

Divi's PHP converter writes attribute JSON with WordPress's `serialize_block_attributes()`, which writes `--`, `<`, `>`, `&` and `\"` inside JSON strings as `\u002d\u002d`, `\u003c`, `\u003e`, `\u0026` and `\u0022`. HTML inside a text module's value is therefore stored as `\u003cp\u003e...`. Write new values the same way. Raw `<`, `>` or `--` inside a block comment can be mangled by kses for users without `unfiltered_html`, and backslashes vanish if a write skips `wp_slash()` (Site Agent's `save-content` slashes correctly). Do not invent private-use escapes (`\uE000` and up).

## Attribute shape

Attributes nest as element group, then area, then option, then breakpoint, then state:

```json
{ "module": { "decoration": { "spacing": { "desktop": { "value": { "padding": { "top": "40px", "bottom": "40px", "syncVertical": "on" } } } } } } }
```

- **Element groups:** `module` on every block, plus module-specific elements such as `content`, `title`, `button`, `image`.
- **Areas:** `meta` (`adminLabel`), `advanced` (`type`, `htmlAttributes`, `link`, `text`, `columnStructure`), `decoration` (`spacing`, `background`, `border`, `boxShadow`, `font`, `sizing`, `layout`, `animation`, `filters`, `transform`, `position`, `zIndex`, `scroll`, `sticky`, `conditions`, `disabledOn`, `overflow`, `transition`) and `innerContent` for text and media values.
- **Breakpoints:** `desktop` (base), `tablet`, `phone`, plus optional `phoneWide`, `tabletWide`, `widescreen` and `ultraWide`, which are off unless enabled in the `et_divi_builder_breakpoints` option.
- **States:** `value` (default), `hover`, `sticky`.
- Mappings from Divi 4: `admin_label` is `module.meta.adminLabel`, text module content is `content.innerContent`, `button_text` and `button_url` become `button.innerContent.*.text` and `.linkUrl`, column `type` is `module.advanced.type`, row `column_structure` is `module.advanced.columnStructure`.

## Module names

Divi 5 block names map one to one to Divi 4 shortcodes with the `et_pb_` prefix replaced: `divi/text` (`et_pb_text`), `divi/heading`, `divi/button`, `divi/image`, `divi/blurb`, `divi/code`, `divi/accordion` with `divi/accordion-item`, `divi/tabs` with `divi/tab`, `divi/slider` with `divi/slide`, `divi/contact-form` with `divi/contact-field`, `divi/pricing-tables` with `divi/pricing-table`, `divi/fullwidth-header`, `divi/fullwidth-code`, and `divi/woocommerce-*` for `et_pb_wc_*`. Divi 5 adds modules with no Divi 4 equivalent, including `divi/group`, `divi/group-carousel`, `divi/breadcrumbs` and `divi/tooltip`.

For an unfamiliar module, copy the attribute structure from an existing instance on the site, or read its `module.json` in the installed theme with source inspection. Do not guess keys.

## Dynamic values and variables

Design variables and dynamic content appear as tokens inside attribute strings, for example `$variable({"type":"color","value":{"name":"gcid-primary-color","settings":{}}})$`. Keep existing tokens intact, and prefer referencing the site's variables over literal colors and sizes.

## Global elements

- A synced element carries `"globalModule": "<et_pb_layout ID>"`, and `<!-- wp:divi/global-layout {"globalModule":"123"} /-->` blocks are replaced at render time with the library post's content.
- Edit the `et_pb_layout` post, not the page copy. Options listed in that post's `_et_pb_excluded_global_options` meta stay per page.
- Saving a library layout should be followed by clearing all static CSS.

## Site-wide design data (options)

| Data | Where |
|---|---|
| Design variables | `et_divi_global_variables` option |
| Divi 5 presets, including option-group presets | `et_divi_builder_global_presets_d5` option (Divi 4 presets were in `et_divi_builder_global_presets_ng`) |
| Global colors | `et_global_data` key inside the `et_divi` option |
| Breakpoints | `et_divi_builder_breakpoints` option |

These names are for the Divi theme; Extra uses its own prefix (`et_extra_`), which was not verified. Change them only on request: snapshot the option first, prefer Divi's own PHP API when it exists (`\ET\Builder\Packages\GlobalData\GlobalPreset::get_data()` and `save_data()`, `GlobalData::get_global_colors()` and `set_global_colors()`), and clear all static CSS afterwards.

## Meta

| Key | Meaning |
|---|---|
| `_et_pb_use_builder` = `on` | The post renders through Divi. Set with `et_builder_enable_for_post( $id, false )`. |
| `_et_pb_use_divi_5` = `on` | Set by the migrator and Visual Builder saves. |
| `_et_pb_divi_4_content` | The migrator's copy of the original Divi 4 content, used for rollback. Do not delete it. |
