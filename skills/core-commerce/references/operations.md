# Core Commerce operations

Tool names are the ability names with `/` replaced by `-`: `core-forms/list-orders` is the
tool `core-forms-list-orders`. Inputs are one object. Core Commerce validates them; unknown
keys are ignored. Lists take `page`, `per_page`, `status`, `search` and, where it applies,
`product_id`.

## Catalog (Licensing module)

| Tool | Input | Notes |
| --- | --- | --- |
| `core-forms-get-commerce-summary` | none | Catalog and operational totals. Start here. |
| `core-forms-list-products` / `get-product` | list filters / `id` | A product with its plans and releases. |
| `core-forms-save-product` | `id` (omit to create), `name`, `slug`, `type`, `status`, `details`, `activation_limit` | `type`: `plugin`, `theme`, `digital`, `service` or `event` (services and events are not licensed). Archive with `status: "archived"` instead of deleting. `activation_limit` 0 means unlimited sites. |
| `core-forms-delete-product` | `id` | Only a product that never sold; others are refused. Ask first. |
| `core-forms-list-plans` / `save-plan` | `product_id` / `id`, `product_id`, `name`, `price_minor` or `price`, `currency`, `billing_period`, `billing_interval`, `trial_days`, `license_duration_days`, `activation_limit`, `status` | `billing_period`: `day`, `week`, `month`, `year` or `lifetime` (unlicensed products are always lifetime). A price change affects new checkouts. |
| `core-forms-list-releases` / `publish-release` | `product_id`, `attachment_id`, `version`, `replace_existing` | Upload the ZIP to the media library first; the release is validated before it is served to licensed sites. Ask first. |
| `core-forms-generate-product-forms` | `product_id` | Creates missing checkout forms, pages and a renewal form; never overwrites. |
| `core-forms-generate-sample-products` | none | Sample products and plans for a demo site only. |

## Orders, licenses and subscriptions

| Tool | Input | Notes |
| --- | --- | --- |
| `core-forms-list-orders` / `get-order` | filters / `id` | Customer, totals in minor units, provider and payment ID, line items. Keys stay masked. |
| `core-forms-list-licenses` / `get-license` | filters / `id` | Masked key, product, subscription, activations. |
| `core-forms-update-license` | `id`, `status`, `activation_limit`, `expires_at` | Ask first; revoking or expiring stops updates for the customer. |
| `core-forms-reset-license-activations` | `license_id` | Deactivates every site of a license. Ask first. |
| `core-forms-list-activations` / `deactivate-activation` | `license_id`, `status` / `activation_id` | One site. Ask first. |
| `core-forms-list-subscriptions` / `update-subscription` | filters / `id`, `status` (`active`, `trialing`, `past_due`, `cancelled`, `expired`), `auto_renew` | Local subscriptions only. One billed by a gateway (it has a provider subscription ID) is refused: change it at the payment provider. Ask first. |
| `core-forms-list-downloads`, `core-forms-list-events` | filters | Audited downloads and the immutable activity stream. |
| `core-forms-resend-customer-access` | `license_id` or `order_id` | Emails the customer a fresh portal link. Ask first. |

## Payment routing

`core-forms-get-gateway-status` reports which gateways are registered and configured,
without credentials. `core-forms-get-currency-routes` and `update-currency-routes`
(`routes`: currency code to an ordered list of gateways) choose the gateway per currency and
keep credentials. Routing changes affect every checkout: ask first.

## Client invoices (Client Invoices module)

1. `core-forms-client-invoice-context`: payable currencies and their payment methods, tax
   settings, next number, reminders. Run first.
2. `core-forms-client-invoice-lookup` with `kind` `customer` or `product` and `term`.
3. `core-forms-create-client-invoice` with `invoice` (`title`, `customer` {`name`, `email`,
   `billing`}, `currency`, `due_date` YYYY-MM-DD, `tax_mode` store, inclusive or exclusive,
   `items` [{`title`, `description`, `quantity`, `price`}], `discount`, `message`, `notes`)
   and an `idempotency_key`. Amounts are decimal strings. A draft has no number, link or
   email.
4. `core-forms-get-client-invoice`: totals, `revision`, `review_hash`, `ready_to_issue`.
   Change a draft with `update-client-invoice` (`id`, `revision`, `patch`; `items` replaces
   the list, `billing` merges).
5. Show the customer, total and due date, and get approval. Then `issue-client-invoice`
   (`id`, `revision`, `review_hash`) numbers it and creates the payment link. It sends no
   email.
6. `send-client-invoice` with `dry_run: true` shows the recipient and email; send for real
   only after approval. `reminder: true` sends the reminder (the overdue one after the due
   date).
7. Later: `list-client-invoices` (`status`: draft, issued, overdue, paid, void, refunded),
   `mark-client-invoice-paid` (`note` with the transfer reference), `void-client-invoice`,
   `reopen-client-invoice`, `duplicate-client-invoice`, `schedule-client-invoice` (repeat
   weekly, monthly or yearly; `dry_run` first) and `unschedule-client-invoice` (pause,
   resume, stop), `list-client-invoice-schedules`.

Every invoice change after issuing is visible to the customer or changes what they owe.
Report the returned state, number and link; a sent email is accepted mail, not proof that it
arrived.
