---
name: core-commerce
description: "Work on Core Commerce, the commerce part of Core Forms: license products, plans, releases, orders, licenses, activations, subscriptions, payment routing and client invoices, through its own abilities served as core-forms-* tools. Keep money units, ask before customer email, invoice issue, void or payment records, and keep license keys and credentials private."
---

# Core Commerce

Core Commerce ships inside Core Forms (`core-forms/core-forms.php`) as modules: Payments,
Licensing and Storefront (products, plans, releases, orders, licenses, activations,
subscriptions, customer portal, update server) and Commerce Plus modules such as Coupons,
Tax and VAT, Automatic Renewals, License Upgrades, Invoices, Reports, Page Access, Client
Invoices and the FluentCart License Bridge. Records live in Core Forms' own tables
(`cf_license_*`, client invoices), not in posts. Checkout runs through Core Forms forms with
payment settings and the commerce actions (`issue_license`, `renew_license`).

## Establish the installation

1. Call `site-agent-site-context`: Core Forms active, version, enabled tools. This guide was
   checked against Core Forms 5.2.0-beta.1.
2. Tools named `core-forms-*` (no `site-agent-` prefix) are Core Commerce's own abilities.
   They appear when **Core Commerce tools** is on in Tools > Site Agent, the connection
   approved that group, and the matching module is on: Licensing for the catalog and
   licenses, Client Invoices for invoices. If they are missing, say which switch or module
   is off. Turning a module on is the user's decision (see the core-forms skill).
3. Read [operations](references/operations.md) for each ability's inputs and the flows.

Core Commerce checks its own permission (`LicenseApi::can_manage`,
`ClientInvoices::can_manage`) on every call. Site Agent adds the group switch, per-password
limits and the audit history (record ID only, never content).

## Rules

- **Read before writing.** Get the exact product, plan, order, license or invoice and use its
  ID. Products, plans, orders, licenses, subscriptions and invoices have separate IDs.
- **Money.** Plans store `price_minor` (integer minor units, so 4900 is 49.00 USD); `price`
  accepts a decimal string and converts it. Client invoices take decimal strings such as
  `"125.00"`. Zero-decimal currencies such as JPY have no minor digits. Never mix currencies.
- **Ask first** and show the specifics (customer, amount, effect) before: issuing, sending,
  voiding, reopening, scheduling or marking an invoice paid; `resend-customer-access`;
  changing or resetting a license or its activations; changing a subscription; publishing a
  release; updating currency routes; deleting a product. A request to look something up
  authorizes reading only.
- **Test and live stay apart.** Payment `mode` and gateway credentials are global settings,
  not commerce tools. Never change them here, and never treat a test order as revenue.
- **Secrets stay out of chat.** License keys come back masked; keep them masked. Gateway
  credentials belong in wp-config.php constants (`CORE_FORMS_{GATEWAY}_{FIELD}`).
- **Retries.** Use the same `idempotency_key` when retrying `create-client-invoice`. After a
  timeout on any write, read the record back before trying again.
- **No refunds or charges.** No ability refunds, captures or charges a card. Point the user
  to the gateway or Core Forms admin for that.

## Without the Core Commerce tools group

With WP-CLI enabled: `core-forms license product_list`, `plan_list`, `license_list`,
`order_list` (read), `product-create`, `plan-update`, `license-update`, `product-delete`
(`--dry-run` first), `maintenance` (expires overdue licenses and subscriptions now), and
`core-forms commerce import-fluentcart --dry-run` or `fluentcart-link`. With PHP execution,
call an ability rather than its tables, for example
`wp_get_ability( 'core-forms/get-order' )->execute( array( 'id' => 12 ) )`.

## Investigation map

`src/licensing/class-license-abilities.php` and `class-license-api.php` (catalog, orders,
licenses), `src/commerce/class-client-invoice-api.php` (invoice abilities),
`src/commerce/` (coupons, tax, subscriptions, upgrades, reports, page access),
`src/payments/` (gateways and currency routing), `src/cli/class-license-command.php`,
`src/cli/class-commerce-command.php` and `docs/licensing/`, `docs/payments/`.
