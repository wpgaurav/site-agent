# Fields

`site-agent-core-forms-save-form` takes `fields`, the complete ordered list, and `layout`.
Core Forms' renderer (`Core_Forms\Schema\SchemaRenderer`, or `SmartForms\MultiStep` for
stepped layouts) turns them into markup, and both are saved together. Read the current list
with `site-agent-core-forms-get-form`, change it, and send all of it back.

## Field object

| Key | Meaning |
| --- | --- |
| `name` | Required, unique. The submitted key and the `[name]` data variable. Keep names stable once a form has entries or actions. |
| `type` | Required. One of `overview.field_types` (custom types registered with `cf_register_field_type` appear there too). |
| `label` | Label. For `heading` and `paragraph`, the text shown. |
| `placeholder` | Placeholder; for `select`, an empty first option. |
| `default_value`, `default_values`, `checked` | Initial value, selected choices, initial checkbox state. |
| `required` | Answer required. |
| `description` | Help text under the field. |
| `options` | `[{"value": "sales", "label": "Sales"}]` for `select`, `radio` and checkbox groups. `group` makes optgroups. |
| `validation` | For example `{"min": 18, "max": 120}` or `{"pattern": "^[0-9]{5}$"}`. A `rating` uses min and max as its scale. |
| `conditions` | `[{"action": "show", "rule": "topic=support"}]`. More than one is stored as `data-cf-conditions`. |
| `attributes` | Extra control attributes: `autocomplete`, `inputmode`, `rows`, `accept`, `multiple`, `data-*`, `aria-*` and similar. A heading uses `{"level": 2}`. |
| `wrapper_attributes` | Attributes on the field's wrapper element (5.2+): `id`, `class`, `style`, `title`, `role`, `tabindex`, `dir`, `lang`, `hidden`, `data-*`, `aria-*`. Others are dropped. |
| `css_class` | Classes on the control; for choice groups and containers, on the group. |
| `parent` | Name of the `container` or `fieldset` the field sits in. |
| `group` | Step name in a `multi-step` or `conversational` layout. |
| `cols_desktop`, `cols_tablet`, `cols_mobile`, `gap` | Columns (1 to 6) and gap of a `container`. |

Types: inputs (`text`, `email`, `url`, `tel`, `number`, `date`, `time`, `datetime-local`,
`password`, `color`, `range`, `hidden`, `search`, `month`, `week`), `textarea`, `select`,
`radio`, `checkbox`, `file`, composites (`name`, `address`, `consent`, `rating`), layout
(`container`, `fieldset`, `heading`, `paragraph`, `divider`, `html`) and buttons (`submit`,
`button`, `reset`). Name a submit button `_submit`: names starting with `_` are not stored.

## Rules that matter

- **Conditions** use the builder's rule strings: `field=value`, `field:a|b|c` (any of), and
  `field=*` (any answer). `action` is `show` or `hide`. Hidden fields are skipped by
  validation and left out of the entry.
- **Wrapper classes.** A field's own wrapper class goes in `wrapper_attributes.class`. To add
  classes to every field wrapper of a form, set `settings.field_wrapper_class` (5.2+, for
  example `"mb-4 col-span-full"`). Core Forms adds it when the form renders, also to
  plain-HTML forms whose fields sit in a single-control wrapper. Utility-framework characters
  such as `md:w-1/2` are kept; quotes and brackets are removed.
- **File uploads** need the File Uploads module (`overview.modules`). `attributes.accept`
  limits types (`.pdf,image/*`, enforced on the server too). `attributes.multiple: true`
  allows several files and the name gets `[]` automatically. `attributes["data-max-files"]`
  caps the count once multiple is on; PHP's `max_file_uploads` still applies. Size and type
  failures use the `file_too_large`, `file_invalid_extension` and `file_too_many` messages.
- **Layouts.** `single` (default) renders fields in order. `multi-step` and `conversational`
  group fields into steps by `group` and add their own navigation and submit button.
- **Containers.** Give a `container` a name and `cols_desktop`, then set `parent` on the
  fields inside. A `fieldset` adds a legend from its label.

## Markup (code view)

Send `markup` instead of `fields` for hand-written HTML: inputs, labels and wrappers
without the `<form>` tag (Core Forms adds it, with the honeypot and nonce). Conditional
wrappers use `data-show-if="topic=support"` or `data-hide-if`. If the form has a stored
schema, pass `schema_from_markup: true` after adding, removing or renaming fields, or the
server keeps validating against the old field list. Without a stored schema, validation
reads the markup directly.

## Example

```json
{
  "title": "Project inquiry",
  "settings": { "submit_button_text": "Send inquiry", "field_wrapper_class": "mb-4" },
  "messages": { "success": "Thanks. We reply within one business day." },
  "fields": [
    { "name": "name", "type": "text", "label": "Name", "required": true, "attributes": { "autocomplete": "name" } },
    { "name": "email", "type": "email", "label": "Email", "required": true, "wrapper_attributes": { "class": "col-half" } },
    { "name": "budget", "type": "select", "label": "Budget", "placeholder": "Choose one",
      "options": [ { "value": "small", "label": "Under $5k" }, { "value": "large", "label": "$5k or more" } ] },
    { "name": "brief", "type": "file", "label": "Brief", "attributes": { "accept": ".pdf,.docx", "multiple": true, "data-max-files": 3 } },
    { "name": "details", "type": "textarea", "label": "Details", "conditions": [ { "action": "show", "rule": "budget=large" } ] }
  ]
}
```
