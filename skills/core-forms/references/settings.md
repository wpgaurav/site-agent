# Global settings, constants and fallbacks

## Global settings

Site-wide settings live in the `cf_settings` option and are read with `cf_get_settings()`.
`site-agent-core-forms-overview` returns them with secrets as `[stored]`. Notable keys:
`enable_nonce`, `load_form_theme`, `load_stylesheet`, `wrapper_tag` (`p`, `div` or `span`,
the default field wrapper), `enable_analytics`, `blocklist_words`, `blocklist_domains`,
`blocklist_action` (`spam` or `reject`), `hide_uploads_from_media`, `reply_from_email`,
`standalone_base_slug`, `delete_data_on_uninstall`, captcha keys, `emailit`, `headless`,
`whatsapp_cloud` and `payments` (mode, currency routes and gateway credentials).

There is no tool that writes global settings. Change them in Core Forms > Settings, or,
when PHP execution is enabled and the user asked for that change, merge into the stored
array so Core Forms' own sanitizer and wp-config guard run:

```php
$settings = (array) get_option( 'cf_settings', array() );
$settings = array_replace_recursive( $settings, array( 'blocklist_action' => 'reject' ) );
update_option( 'cf_settings', $settings );
return array_intersect_key( cf_get_settings(), array( 'blocklist_action' => 1 ) );
```

Never write credentials this way, and never print a settings array that holds them. Payment
`mode` switches every payment form between test and live charges: change it only on an
explicit request.

## wp-config.php constants (5.2+)

A defined, non-empty constant wins over the saved setting. The settings screens show such a
field read-only by constant name, saves never copy it into the database, and removing the
constant brings the saved value back. `overview.constants` shows which ones are active, by
name only. Recommend constants for every credential; the user adds them to wp-config.php.

| Setting | Constants (first defined wins) |
| --- | --- |
| reCAPTCHA keys | `CORE_FORMS_RECAPTCHA_SITE_KEY`, `CORE_FORMS_RECAPTCHA_SECRET_KEY` |
| Turnstile keys | `CORE_FORMS_TURNSTILE_SITE_KEY` or `GT_TURNSTILE_SITE_KEY`; `CORE_FORMS_TURNSTILE_SECRET_KEY` or `GT_TURNSTILE_SECRET_KEY` (shared with other Gatilab plugins) |
| Emailit | `CORE_FORMS_EMAILIT_API_KEY` |
| WhatsApp Cloud | `CORE_FORMS_WHATSAPP_ACCESS_TOKEN`, `CORE_FORMS_WHATSAPP_PHONE_NUMBER_ID`, `CORE_FORMS_WHATSAPP_BUSINESS_ACCOUNT_ID`, `CORE_FORMS_WHATSAPP_VERIFY_TOKEN`, `CORE_FORMS_WHATSAPP_APP_SECRET` |
| Twilio inbound | `CORE_FORMS_TWILIO_AUTH_TOKEN` |
| Headless API key | `CORE_FORMS_HEADLESS_API_KEY` |
| Payment gateways | `CORE_FORMS_{GATEWAY}_{FIELD}`, for example `CORE_FORMS_STRIPE_SECRET_KEY_LIVE` for `payments.stripe.secret_key_live` (not `mode` or currency routes) |
| License | `CORE_FORMS_LICENSE_KEY`, activated automatically for an administrator |
| Protected uploads | `CF_PRIVATE_UPLOAD_DIR`, a writable directory outside the web root |

## Modules

Features are modules (`overview.modules`): file uploads, webhooks, payments, licensing,
client invoices, analytics, headless, block editor forms, captchas and more. Turn one on or
off only on request: Core Forms > Modules, or with WP-CLI enabled
`["core-forms", "module", "enable", "webhooks"]`. Disabling a module that forms still use
needs `--force` and stops those features on those forms.

## Without the Core Forms tools group

With PHP execution enabled, dispatch Core Forms' REST routes; don't write post meta or the
submissions table directly:

```php
$request = new WP_REST_Request( 'GET', '/cf/v1/forms/42' );
$response = rest_do_request( $request );
return array( 'status' => $response->get_status(), 'data' => $response->get_data() );
```

Routes under `cf/v1` (all need `edit_forms` and access to the form):

- `GET|POST /forms`, `GET|PATCH|DELETE /forms/{id}`. PATCH takes `title`, `slug`, `markup`
  and `settings` (merged; `actions` replaces the list) plus `fingerprint`.
  DELETE removes the form permanently.
- `GET|PUT /forms/{id}/schema`, `POST /forms/{id}/schema/generate` (`save: true`),
  `PUT /forms/{id}/editor-state` (`markup` and `schema` together), and
  `GET|POST /forms/{id}/fields`, `PATCH|DELETE /forms/{id}/fields/{name}`. Every mutation
  needs the current `fingerprint`.
- `POST /forms/{id}/publishing` (`action`: start, publish or discard),
  `POST /forms/{id}/revisions/{revision}/restore`, `POST /forms/{id}/rehearsal`.
- `GET /submissions`, `GET /forms/{id}/submissions`, `GET|PATCH|DELETE /submissions/{id}`.
- `GET /action-logs`, `/action-logs/stats`, `/action-logs/failures`, `/action-queue/stats`,
  `POST /action-queue/{id}/retry`, `POST /actions/test` (administrators only).
- `GET /analytics/{id}/funnel|daily|field-dropoff|attribution|spam-trends|recommendations`
  and `/analytics/overview` (Analytics module).

Messages are `cf_message_{key}` post meta; with the tools group off, change them in the
form's Messages tab.

## WP-CLI

With WP-CLI enabled (`site-agent-run-wp-cli`, arguments without `wp`):

- `core-forms doctor` health check; `core-forms form list|export|import|check [--fix]`.
- `core-forms submission list|update|cleanup`. `update` and `cleanup` change or delete many
  records: run with `--dry-run` first and only on request.
- `core-forms module list|enable|disable`.
- `core-forms license product_list|plan_list|license_list|order_list|product-create|
  plan-update|license-update|product-delete|maintenance` and
  `core-forms commerce import-fluentcart|fluentcart-link` (see the core-commerce skill).

## Investigation map

`src/class-rest-forms.php`, `src/class-rest-submissions.php`, `src/class-form-definition.php`
(fingerprints, locks, publishing), `src/schema/` (field schema, renderer, markup parser,
drift), `src/integrations/class-action-settings.php` and `class-field-mapping.php`,
`src/actions/` (one class per action type), `src/workflows/` (queue, logs, rehearsal,
signing), `src/class-credentials.php`, `src/functions.php` (`cf_get_form`,
`cf_get_settings`, `cf_get_form_default_settings`, data variables) and `docs/`.
