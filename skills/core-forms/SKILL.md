---
name: core-forms
description: "Create, edit, duplicate and trash Core Forms forms; build fields from the builder schema or code-view markup; change messages, settings and the ordered action list (email, autoresponder, webhooks, integrations); rehearse and test actions; read and triage entries; inspect global settings and wp-config.php constants. Use the fingerprint on every change and keep secrets out of chat."
---

# Core Forms

Core Forms stores each form as a `core-form` post. Its definition has five parts that change
together under one **fingerprint**: the markup (`post_content`, what visitors see), the field
**schema** (the visual builder's structured copy, used for server validation), **settings**
(including the ordered **actions** list), **messages**, and title/slug. Entries live in the
`cf_submissions` table, not in posts. `list-content` and `save-content` can't change a form
safely; use the Core Forms tools below.

## Establish the installation

1. Call `site-agent-site-context`. Confirm `core-forms/core-forms.php` is active and note its
   version. This guide was checked against Core Forms 5.1.4 and 5.2.0-beta.1; the tools need 5.1 or
   later, and field wrapper classes need 5.2. Core Commerce (products, licenses, orders, invoices) is the
   [core-commerce](../core-commerce/SKILL.md) skill.
2. Check `enabled_tools` for `core-forms-*` tools. If they are missing, ask the user to turn
   on **Core Forms tools** in Tools > Site Agent and to approve that group when the client
   connects. Without it, see [settings and fallbacks](references/settings.md).
3. Call `site-agent-core-forms-overview` once per task. It returns enabled modules, every
   registered action type with the default settings its editor starts from, field types,
   default form settings and messages, global settings (secrets as `[stored]`) and which
   settings come from wp-config.php constants (Core Forms 5.2+). Use those keys; don't guess them.

## Tools

| Tool | Does |
| --- | --- |
| `site-agent-core-forms-list-forms` | Forms you can manage: ID, slug, shortcode, action types, entry count |
| `site-agent-core-forms-get-form` | Full draft definition with `fingerprint`, fields, markup, settings, actions, messages |
| `site-agent-core-forms-save-form` | Create, or change fields, markup, title, slug, settings and messages |
| `site-agent-core-forms-save-actions` | Replace the ordered action list: add, edit, remove, reorder |
| `site-agent-core-forms-rehearse` | Validate sample answers and plan actions; sends nothing |
| `site-agent-core-forms-test-action` | Run one action for real with sample answers |
| `site-agent-core-forms-publish-form` | Start draft publishing, publish the draft or discard it |
| `site-agent-core-forms-duplicate-form` | Core Forms' own copy of a form |
| `site-agent-core-forms-trash-form` | Move a form to the trash; entries stay |
| `site-agent-core-forms-list-submissions`, `-get-submission`, `-update-submission` | Read and triage entries |

Every change runs through Core Forms' `cf/v1` REST API as the signed-in administrator, so
its permission checks, form access scope, locks, sanitizing, revisions and field-mapping
validation apply. Each change needs the latest `fingerprint` (`expected_fingerprint`); each
result returns the new one. `cf_edit_conflict` means the form changed elsewhere: read it
again, reconcile, and retry once. Never loop on conflicts.

## Build a form

1. Plan fields with the user: names, types, labels, required, choices. Read
   [fields](references/fields.md) for the field object, file uploads, containers, steps,
   conditions and wrapper classes.
2. Call `site-agent-core-forms-save-form` without `form_id`, with `title`, `fields` and any
   `settings` and `messages`. Core Forms' own renderer turns the field list into markup and
   saves it with its schema, exactly as the visual builder does. A single-page form without a
   `submit` field gets a `_submit` button (text from `settings.submit_button_text`).
3. Add actions with `site-agent-core-forms-save-actions` (see [actions](references/actions.md)).
   Mapped field names must exist, so save fields first.
4. Call `site-agent-core-forms-rehearse` with realistic answers. Check `valid`, each action's
   `would_run` and destination. Fix and repeat. Rehearsal stores and sends nothing.
5. Only with the user's go-ahead, `site-agent-core-forms-test-action` sends one real email or
   webhook. Prefer a recipient the user names; never test against customers.
6. Embed with the returned `shortcode` (`[cf_form slug="..."]`) or the dynamic block
   `<!-- wp:core-forms/form {"slug":"contact"} /-->` (also `showTitle`, `maxWidth`,
   `alignment`) through the content tools and the matching builder skill.

## Edit a form

- Read with `site-agent-core-forms-get-form` first. `schema: "stored"` means the visual
  builder owns the fields: change them by sending the complete `fields` list (edit the
  returned list; keep names stable, since entries, actions and data variables use them).
- `schema: "markup"` is a plain-HTML form. Edit `markup` (code view). For a stored-schema form,
  a markup edit keeps the schema and Core Forms re-checks choice options; adding, removing or
  renaming fields in markup needs `schema_from_markup: true`, which rebuilds the schema and
  drops builder-only layout data. Check `schema_drift` after any markup save.
- Markup that holds `<!-- wp:core-forms/... -->` blocks is a Block Editor form (4.14+). Its
  fields are `core-forms/field`, `core-forms/choice` and `core-forms/submit` blocks: change
  them as block markup through `markup`, never with `fields`, which the tool refuses here.
- `settings` merge into the current settings. Messages accept the keys in `overview.messages`.
- If `publishing.draft_publishing` is true, saves change the draft only. Tell the user, and
  publish with `site-agent-core-forms-publish-form` (`publish`) only when they approve.
- Duplicate before a risky redesign of a live form. Trash only on an explicit request.

## Entries

`site-agent-core-forms-list-submissions` filters by form, status (`new`, `read`, `replied`,
`archived`), spam, search and dates. Entries contain personal data, IP addresses and user
agents: summarize, quote only what the task needs, and never copy entries into public
content. `update-submission` sets status or the spam flag. There is no delete tool: Core
Forms' `DELETE /cf/v1/submissions/{id}` erases files, replies and logs and refuses entries
tied to unfinished payment or delivery; use it only on an explicit request (see
[settings and fallbacks](references/settings.md)).

## Boundaries

- Secrets in actions and settings show as `[stored]`. Send `[stored]` back to keep them. Never
  ask the user to paste API keys or passwords into chat; on Core Forms 5.2 or later, recommend
  wp-config.php constants.
- Turning modules on or off, changing global settings and payment settings affect every form;
  do them only on request. Payment forms charge real money in live mode.
- A successful save, rehearsal or test proves configuration, not inbox delivery or a
  provider's acceptance. Report what the tool returned.
