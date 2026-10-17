---
name: fluentcart
description: "Work on FluentCart products, variations, downloads, orders, customers, subscriptions and store configuration through Site Agent. Distinguish product content from commerce data, discover native routes and policies, preserve money units, and verify guarded changes."
---

# FluentCart

FluentCart owns commerce records and checkout. A product description is WordPress content;
prices, variations, downloads, orders, subscriptions and licenses have separate storage
and APIs. Updating `post_content` alone cannot update those commerce fields.

## Resolve the store and operation

1. Call `site-agent-site-context`. Check the site, active `fluent-cart/fluent-cart.php`,
   optional Pro plugin, installed versions, environment and enabled tools.
2. Call `list-skills`. For GT Extensions invoices, quotes or store commands read
   [GT Extensions](../gt-extensions-fluentcart/SKILL.md) and the matching active `gtfc-*`
   skill. Those commands aren't native FluentCart commands. WooCommerce and
   [Stackless](../stackless/SKILL.md) use different records even on the same site.
3. Determine whether the task changes editorial content or commerce data. Record exact
   product post ID, variation ID, order ID, customer ID or subscription ID as appropriate.
   These identifiers are not interchangeable, and customer IDs needn't be WordPress user IDs.

## Content and product pages

The product post type is `fluent-products`. Use `list-content` and `get-content` when it
is exposed in `site-context.post_types`. Read raw content, builder, status, template and
hash. Use `save-content` for allowlisted editorial fields only. Preserve product identity,
slug, commerce settings and layout metadata. Follow the builder skill, including
[PBB](../page-blocks-builder/SKILL.md), when product pages use that builder.

New editorial drafts and staged autosaves are distinct from creating a complete purchasable
product. New commerce products should use FluentCart's product creation API with an explicit
draft status, then verify the generated default variation. Don't copy a product's whole
database row to create another one.

## Native commerce operations

Read [API operations](references/operations.md) before using PHP to dispatch native REST
requests. Discover actual registered routes and method/argument requirements on the target
version. The local reference was inspected at FluentCart 1.6.6. Pro and enabled modules
can change availability; a disabled native ability is not permission to recreate it in PHP.

- List narrowly, paginate, then get the exact record and its related variations before changes.
- FluentCart's native monetary fields use integer minor units as documented by each endpoint.
  GT Extensions agent invoices accept decimal strings instead. Inspect currency precision;
  don't blindly divide every field by 100 or combine currencies.
- Keep live and test orders, gateway modes and license delivery separate. Test credentials
  don't authorize a live charge, renewal, refund or subscription cancellation.
- Preserve native policies and validation. Use supported services or routes so stock,
  tax, order events, subscription state and downloadable entitlements stay consistent.
- Snapshot the precise affected fields and related IDs. Apply a targeted change, then
  read back both commerce records and the rendered page when available. The content hash
  guards post content only; it is not a commerce transaction or a version lock for prices.

Payment capture, refunds, recurring billing, customer email, license revocation and public
price changes need authorization for that operation. Avoid new approval requests when the
user has already clearly authorized the exact action. A timeout requires a read-back
before retrying, especially for order creation and payment operations.

Never expose gateway credentials, customer exports, license keys, activation hashes or
signed download URLs. A license or automatic-update setting doesn't grant permission to
alter commerce access. Report saved state, payment state and delivery state separately.

## Sources

Inspect `app/CPT/FluentProducts.php`, `app/Http/Routes/`, `app/Http/Controllers/`,
`app/Http/Policies/`, `app/Models/` and `app/Modules/` in the installed plugin. Use the
[official REST documentation](https://dev.fluentcart.com/restapi/) to interpret those APIs;
the installed source and route discovery decide which operations this site supports.
