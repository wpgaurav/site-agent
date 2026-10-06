# Elementor element data

Verified against Elementor `release/stable` at `3301e5cd` (4.3 line). File references point at that source.

## Storage

| Meta key | Holds |
|---|---|
| `_elementor_data` | JSON string of the element array. Elementor writes `wp_slash( wp_json_encode( $elements ) )`, so stored URLs look like `https:\/\/example.com` (`wp elementor replace-urls` relies on that form). |
| `_elementor_edit_mode` | `"builder"`. Required for the front end to render the Elementor tree. |
| `_elementor_template_type` | Document type: `wp-page`, `wp-post`, `page`, `section`, `container`, `kit`, `landing-page` and others. Selects the document class. |
| `_elementor_version` | Elementor version of the last save. Missing or below 2.5.0 adds legacy wrapper markup. |
| `_elementor_page_settings` | Serialized PHP array (not JSON). Title, status, excerpt, featured image and template are applied to the post itself rather than stored here. |
| `_elementor_css` | CSS file state for `uploads/elementor/css/post-{ID}.css`. |
| `_elementor_element_cache` | Cached rendered HTML (see the site reference). |
| `_elementor_page_assets` | Scripts and styles the page needs; rebuilt on save. |

`post_content` is regenerated as plain HTML or text on every `Document::save()` (for search, feeds and plugin deactivation). Its hash in `get-content` therefore changes after any Elementor save; re-read before using `save-content` on the same post.

## Element shape

The top level is an array of elements:

```json
[
  {
    "id": "4a8be05",
    "elType": "container",
    "isInner": false,
    "settings": { "content_width": "boxed", "flex_direction": "column" },
    "elements": [
      {
        "id": "1c29003",
        "elType": "widget",
        "widgetType": "heading",
        "settings": { "title": "Fast WordPress hosting", "header_size": "h1" },
        "elements": []
      }
    ]
  }
]
```

- `id`: lowercase hex, unique within the document. The editor generates 7 characters; PHP can produce up to 8. IDs drive `.elementor-element-{id}` CSS selectors.
- `elType`: `section` and `column` always exist; `container` only when the container experiment is active; V4 elements add their own types (below). Everything else is `widget` with a `widgetType`.
- `isInner`: `true` for nested containers and inner sections. Widgets omit it.
- Empty `settings` are stored as `[]`.
- Repeater rows (icon list items, tabs and similar) carry an `_id` of 7 hex characters.
- **Unregistered types are deleted on save without an error**, including their children.

## Containers

The container experiment is stable. It is off by default but on for sites first installed on Elementor 3.16.0 or later; check it with the read snippet. Older pages may still use `section` and `column`.

- `container_type`: `flex` (default) or `grid`. `content_width`: `boxed` (default) or `full`. Also `boxed_width`, `width`, `min_height`.
- Flex: `flex_direction` (`row`, `column`, `row-reverse`, `column-reverse`), `flex_justify_content`, `flex_align_items`, `flex_wrap`, `flex_align_content`, `flex_gap` as `{"column": "20", "row": "20", "isLinked": true, "unit": "px"}`.
- Grid: `grid_columns_grid` as `{"unit": "fr", "size": 3}`, `grid_rows_grid`, `grid_gaps`, `grid_auto_flow`, `grid_justify_items`, `grid_align_items`.

## Classic control values

| Control | Shape |
|---|---|
| Slider | `{"unit": "px", "size": 24, "sizes": []}` |
| Dimensions | `{"unit": "px", "top": "10", "right": "20", "bottom": "10", "left": "20", "isLinked": false}` (strings) |
| Media | `{"url": "https://...", "id": 123, "size": ""}` |
| URL | `{"url": "https://...", "is_external": "", "nofollow": "", "custom_attributes": ""}` |
| Icons | `{"value": "fas fa-check", "library": "fa-solid"}` |
| Color | `"#1b2733"` |

- **Responsive values** add `_{device}` to the control ID; desktop has no suffix. Devices: `mobile`, `mobile_extra`, `tablet`, `tablet_extra`, `laptop`, `widescreen`. Example: `typography_font_size_tablet`.
- **Group controls** prefix their fields with the group name. Typography needs its switch set before any field applies: `"typography_typography": "custom"`, then `typography_font_family`, `typography_font_size`, `typography_font_weight`, `typography_line_height`, `typography_letter_spacing` and the rest. Backgrounds use `"background_background": "classic"` (or `gradient`, `video`, `slideshow`) with `background_color` and `background_image`.
- **Globals** go in `settings.__globals__`, mapping a control to a kit reference: `{"title_color": "globals/colors?id=primary", "typography_typography": "globals/typography?id=accent"}`. System IDs are `primary`, `secondary`, `text` and `accent`; custom globals use their `_id`. A global wins over a literal value; an empty string means none.

## Common widgets

- **heading**: `title`, `link` (URL), `size`, `header_size` (default `h2`), `align` (responsive), typography group `typography`, `title_color`, `text_shadow`.
- **text-editor**: `editor` (HTML), `drop_cap`, `text_columns`, `align`, typography group `typography`, `text_color`, `link_color`.
- **image**: `image` (media), `image_size`, `image_custom_dimension`, `caption_source`, `caption`, `link_to`, `link`, `open_lightbox`, `align`, `width`, `image_border_radius`.
- **button**: `text`, `link`, `size`, `selected_icon`, `align`, typography group `typography`, `button_text_color`, background group `background`, `hover_color`, `border_radius`, `text_padding`.
- **icon-list**: `view`, repeater `icon_list` with rows `{"_id": "a1b2c3d", "text": "...", "selected_icon": {"value": "fas fa-check", "library": "fa-solid"}, "link": {...}}`, `space_between`, `icon_color`, `icon_size`, `text_color`.

For any other widget, read its controls in the installed source with `read-file` (`plugins/elementor/includes/widgets/` for core widgets) or copy the settings from an existing instance on the site.

## Editor V4 (atomic elements)

V4 is experimental: `e_atomic_elements` is beta and `e_opt_in_v4` is alpha. Both are off by default and on for sites first installed on Elementor 4.0.0 or later. Write V4 elements only when the read snippet shows `e_atomic_elements` active and the page already uses them, or the user asks.

- Widgets keep `elType: "widget"` with `widgetType` `e-heading`, `e-paragraph`, `e-button`, `e-image`, `e-svg`, `e-divider`, `e-youtube` or `e-self-hosted-video`.
- Layout elements have their own `elType`: `e-div-block`, `e-flexbox`, `e-grid`, plus tabs and accordion parts.
- Settings are typed props:

```json
"settings": {
  "classes": { "$$type": "classes", "value": ["e-1c29003-a1b2c3d"] },
  "tag": { "$$type": "string", "value": "h1" },
  "title": { "$$type": "escaped-html", "value": "Fast WordPress hosting" }
}
```

- Sizes are `{"$$type": "size", "value": {"size": 48, "unit": "px"}}`, colors `{"$$type": "color", "value": "#059669"}`. Dimensions use logical keys (`block-start`, `inline-end`, `block-end`, `inline-start`). Images nest `image-src` with an `image-attachment-id` or `url`.
- Styles live in a `styles` map keyed by class ID; every style has `"type": "class"` and `variants`, each with `meta` (`breakpoint`, and `state` of `null`, `hover`, `active`, `focus` and others) and `props` named after CSS properties:

```json
"styles": {
  "e-1c29003-a1b2c3d": {
    "id": "e-1c29003-a1b2c3d",
    "label": "local",
    "type": "class",
    "variants": [
      { "meta": { "breakpoint": "desktop", "state": null }, "props": { "font-size": { "$$type": "size", "value": { "size": 48, "unit": "px" } } } }
    ]
  }
}
```

- `g-...` IDs in `classes` are global classes, stored as `e_global_class` posts; change them in the editor, not through meta.
- **Invalid settings make `save()` throw an exception; an invalid style is dropped with only a logged warning.** Re-read after saving and compare the `styles` you sent.
