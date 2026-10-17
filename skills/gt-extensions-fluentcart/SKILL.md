---
name: gt-extensions-fluentcart
description: "Use GT Extensions for FluentCart through Site Agent for store reports, orders, invoices, quotes, stock alerts, product specifications and page access. Discover module-specific skills, use gtfc_agent with native validation, and keep drafting, issuing and sending separate."
---

# GT Extensions for FluentCart

This plugin extends FluentCart. Its global agent entry point is `gtfc_agent($command,
$input)`, not `stackless_agent()`. Calls run as the connected WordPress user and return
an array with `ok` and either results or `error`.

## Preflight

Call `site-agent-site-context`; verify the site, active FluentCart and GT Extensions
versions and `execute-php` in `enabled_tools`. Read `list-skills`, then the matching
plugin-provided `gtfc-store`, `gtfc-invoice` or `gtfc-quote` skill when present. They carry
the installed version's detailed inputs. This bundled overview was checked against the
2.12.x source family and never replaces plugin permission checks or module gates.

Start with a bounded availability read:

```php
if (!function_exists('gtfc_agent') || !function_exists('gt_extensions_fluentcart')) {
    return new WP_Error('unavailable', 'GT Extensions agent API is unavailable.');
}
return array('modules' => gt_extensions_fluentcart()->getEnabledModules());
```

`gtfc_agent('context')` is invoice-oriented and can refuse when Custom Invoices is off.
That refusal does not mean store reads are unavailable. Use the discovered workflow's
own preflight. Don't enable modules automatically to silence errors.

## Run a command

```php
return gtfc_agent('order.list', array('page' => 1, 'mode' => 'live'));
```

For arbitrary input text use `json_decode` on a nowdoc or another correctly serialized
data value; never concatenate customer text into executable PHP. Commands available in
the inspected source include:

| Work | Commands | Important input or gate |
| --- | --- | --- |
| Sales and orders | `store.summary`, `order.list`, `order.get` | Site-timezone `from`/`to`, live/test `mode`, order `id` |
| Invoices | `context`, `lookup`, `invoice.list`, `invoice.get`, `invoice.create`, `invoice.update`, `invoice.issue`, `invoice.send` | Custom Invoices; current `revision`, `review_hash`, stable create `idempotency_key` |
| Quote proposals | `quote.list`, `quote.get`, `quote.new`, `quote.prepare`, `quote.send`, `quote.withdraw` | Custom Invoices and Request a Quote; follow `gtfc-quote` |
| Invoice schedules | `invoice.schedule`, `invoice.unschedule`, `invoice.schedules` | Billing and future email effects; inspect installed skill |
| Stock demand | `stock_alert.list`, `stock_alert.send` | Back in Stock Alerts; sending requires `variation_id` and a dry run |
| Product details | `product.details.get`, `product.details.set` | Product Enhancements; these specifications are not native commerce pricing |
| Page entitlements | `page_access.pages`, `page_access.add`, `page_access.remove` | Page Access; changes affect who can read the page |

Read [FluentCart](../fluentcart/SKILL.md) for product, variation and download identity.
Resolve IDs through lookups, never guess them. Keep currencies separate. Agent invoice
prices use decimal strings while native FluentCart amounts commonly use minor units.

## Finish the authorized workflow

- Invoice creation saves a private draft. Issuing creates an order, assigns a number and
  locks the invoice; sending emails the customer. Authorization for one stage doesn't
  automatically authorize the others. Use the latest revision and review hash to issue.
- Keep the same idempotency key for an invoice creation retry with identical input.
  Re-read after any uncertain result before creating or sending again.
- For commands supporting `dry_run`, run it first and inspect recipients, amounts and
  affected pages. Use the corresponding get/list command after the real operation.
- Customer messages, proposals, stock alerts and invoice emails require explicit user
  authorization. If already given for the exact operation, carry it through without
  asking for it again. Draft preparation and read-only lookups can proceed directly.
- Access changes must preserve other product mappings. A live product details update
  changes the public page; confirm the final shown rows and hidden/custom field lists.

Read errors as errors even when the PHP call itself succeeded. Report invoice/order/quote
ID, totals and currency, status, public payment link when appropriate, and actual send
result. Don't reveal licenses, gateway secrets or customer data beyond the requested work.

## Other modules

Module presence doesn't imply an agent command exists for it. Inspect
`gt-extensions-fluentcart.php`, `includes/Agent/Api.php`, `includes/Agent/Skills.php`,
`includes/Modules/` and the installed admin settings flow for unsupported operations.
Preserve unrelated settings with a targeted, validated merge. Never bypass a command
refusal with raw database writes, trusted automation helpers or a changed current user.
