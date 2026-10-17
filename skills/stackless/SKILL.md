---
name: stackless
description: "Operate Stackless for WooCommerce through Site Agent: discover enabled agent commands, inspect sales, orders, subscriptions and licenses, and work with invoices, quotes, stock alerts, product details and page access. Distinguish the plugin from the optional Stackless theme."
---

# Stackless for WooCommerce

Stackless extends WooCommerce. FluentCart is a separate commerce system; its products,
orders and licenses aren't Stackless records. The optional Stackless theme controls
presentation and isn't required for the plugin's store operations.

## Discover this site's capabilities

Call `site-agent-site-context`. Check WooCommerce, `stackless/stackless.php`, versions,
environment and enabled tools. This guide was inspected at Stackless 1.5.1. Read matching
active `stackless-store`, `stackless-invoice` and `stackless-quote` skills from `list-skills`.
Their presence depends on the AI Agents and relevant workflow modules.

When `execute-php` is enabled, discover the API and commands rather than guessing:

```php
if (!function_exists('stackless_agent')) {
    return new WP_Error('unavailable', 'Stackless agent API is unavailable.');
}
return stackless_agent('context');
```

Then run `return stackless_agent('commands');`. Core discovery can work when the AI Agents
module is off; store reports and order commands need that module. Each other command
family belongs to its own module. A missing command or permission is a stop for that
operation, not a reason to call a hidden callback or alter module settings.

## Choose the workflow

| Request | Discover and use | Read back |
| --- | --- | --- |
| Sales or an order | `store.summary`, `order.list`, `order.get` | Period/timezone, currency, returned totals, exact order |
| Subscription state | `subscription.*` commands listed here | Current status, period end, next payment, linked orders |
| License context | `license.*` commands listed here | Product/customer entitlement and state; keep keys private |
| An invoice | Installed `stackless-invoice` skill | Draft, issue and send results separately |
| A proposal | Installed `stackless-quote` skill | Request, invoice/proposal, expiry and send result |
| Stock alerts | Installed store skill and discovered stock commands | Variation, stock state, recipient count, send result |
| Product details or page access | Installed store skill and matching command family | Displayed fields and preserved entitlement mappings |

Use the installed command listing and skill for exact spellings and input schemas. For
example, after resolving an order ID, `stackless_agent('order.get', array('id' => 42))`
reads that order when the command exists. Check `ok`, not merely the PHP tool's status.
Return limited data, paginate and keep below Site Agent's 64 KiB return limit.

Keep the user's authorization tied to its actual effect. Charges, renewals, refunds,
cancellations, revocations, invoice issuance and customer emails have different effects.
Use a dry run when supported, preserve revisions/review hashes/idempotency inputs, and
read current state before retries. Don't automatically run a renewal or delivery queue
to test whether a setting works. Don't ask again for an action already clearly authorized.

## WooCommerce data and module settings

For operations outside the agent commands, inspect installed code before selecting a
native service. Use WooCommerce CRUD such as `wc_get_order()` and `wc_get_product()`;
don't assume orders live in WordPress posts or bypass High-Performance Order Storage.
Use `stackless_module_active()` and the public functions in `includes/functions.php`
where appropriate. Settings and workflow changes need their owning module's validation
and hooks, not direct edits to subscription/license tables or option dumps.

Sales reports can be truncated; honor any returned truncation note and narrow the range.
Separate currencies and inspect money units at each API boundary. Keep gateway modes,
customer records, signed downloads, payment tokens and license keys private.

## Theme work

If the request concerns the Stackless theme, verify the active parent/child theme in
`site-context.theme` and inspect it with source tools. Its source uses classic PHP
templates, `theme.json` and `--st-*` CSS properties. Edit a child theme where appropriate,
keep source and minified assets in sync using the theme's own workflow, and verify
WooCommerce and Stackless styles only where their integrations are active. Installing
or activating a theme is a separate action from editing a store record.

Source map: `includes/Core/Agent.php`, `includes/functions.php`,
`includes/Modules/AiAgents/`, the owning `includes/Modules/{Name}/Agent.php`, and
`agent-skills/` in the installed Stackless plugin. Guides never enable tool access.
