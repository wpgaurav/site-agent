# Actions

Actions run after a successful submission, in list order. They live in the form's
`settings.actions`. Change them only with `site-agent-core-forms-save-actions`, which sends
the complete list through Core Forms' REST API: it validates mapped field names, keeps stored
secrets and keeps actions you don't touch byte for byte.

## Reading and writing the list

`site-agent-core-forms-get-form` returns each action as `index`, `fingerprint`, `type`,
`label`, `available` and `settings` (the stored object with `type`, `_cf_action_id` and
secrets shown as `[stored]`). In `save-actions`:

- Keep an action unchanged: `{"keep_index": 1, "keep_fingerprint": "<its fingerprint>"}`.
  Core Forms refuses the save if that action changed meanwhile.
- Edit an action: send its `settings` object back with your changes. Leave `[stored]` and
  `_cf_action_id` as they are; Site Agent puts the stored secret back.
- Add an action: send a settings object with `type` and the keys from
  `overview.action_types[].defaults`. Site Agent gives it a stable `_cf_action_id`.
- Remove an action: leave it out. Reorder: change the list order. `[]` removes all actions.

Only registered types can be added or edited. A type missing from `overview.action_types`
needs its module or plugin (webhooks need the Webhooks module; FluentCRM, MailerPress,
MC4WP and similar integrations need their plugins). Core Forms keeps unavailable actions
it already has, but they don't run.

## Common keys

These keys work on every action:

- `conditions`: run only when answers match, for example
  `[{"field": "topic", "operator": "equals", "value": "sales"}]`. Operators: `equals`,
  `not_equals`, `contains`, `not_contains`, `empty`, `not_empty`. All conditions must match;
  an unknown operator never matches.
- `async`: `1` runs the action in the background queue with retries (needs saved entries).

Data variables fill answers into strings: `[field_name]`, `[all]`, `[all:label]`,
`[CF_FORM_TITLE]`, `[CF_FORM_ID]`, `[CF_TIMESTAMP]`, `[CF_IP_ADDRESS]`, `[CF_REFERRER_URL]`, and
for payments `[CF_PAYMENT_STATUS]`, `[CF_PAYMENT_AMOUNT_FORMATTED]` and similar.

## Email (`email`)

`to` (comma-separated; data variables allowed), `from`, `subject`, `message`,
`content_type` (`text/html` or `text/plain`), `headers` (one per line, for example
`Reply-To: [email]`). `recipient_routes` sends to other recipients by answer:
`[{"field": "topic", "value": "support", "recipient": "help@example.com"}]`.

## Autoresponder (`autoresponder`)

Confirmation to the person who submitted. `email_field` (the field holding their address;
required), `from_name`, `from_email`, `reply_to`, `subject`, `message`, `content_type`.

## Webhook (`webhook`)

Webhooks module. `url`, `content_type` (`form` or `json`), optional `auth_header_name` and
`auth_header_value`, and signing with `webhook_secret` and `webhook_key_id`: requests then
carry `X-CF-Signature` (`sha256=` HMAC of `timestamp.body`), `X-CF-Timestamp`, `X-CF-Key-Id`
and `X-CF-Event-Id`. Receivers should verify the raw body and store the event ID so retries
stay idempotent.

## Integrations

Other types (Slack, Discord, Telegram, Google Sheets, Notion, Airtable, HubSpot, Mailchimp,
Brevo, ConvertKit, FluentCRM, MailerPress, Zapier, Make, n8n, create post or user,
license issuing and more) take the keys in their `defaults`. Many hold credentials: ask the
user to enter those in the form's Actions tab or wp-config.php, never in chat. Fields named
`*_field` map answers by field name and must exist in the form.

## Checking an action

1. `site-agent-core-forms-rehearse` with sample answers: each action's `would_run` or
   `would_skip`, whether its handler is available, and its destination. Nothing is sent.
2. `site-agent-core-forms-test-action` with `index` runs that saved action once, for real,
   with `test_data` (default: Core Forms' samples). It needs the user's go-ahead, especially
   for customer-facing destinations, paid messaging (WhatsApp, SMS) and CRMs.
3. After live submissions, read the action log: `GET /cf/v1/action-logs?form_id=...` and
   `/cf/v1/action-logs/failures` (see [settings and fallbacks](settings.md)).
