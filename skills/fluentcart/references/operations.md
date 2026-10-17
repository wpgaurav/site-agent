# Native REST operations

Site Agent has no generic REST-request MCP tool. Use a plugin-specific agent command when
available, or dispatch through an explicitly enabled `execute-php` tool. This retains
the authenticated WordPress user and REST permission callbacks. Do not call controllers
directly or use database writes to work around a refusal.

## Discover live routes

```php
$found = array();
foreach (rest_get_server()->get_routes() as $route => $handlers) {
    if (strpos($route, '/fluent-cart/') !== 0) {
        continue;
    }
    $methods = array();
    foreach ($handlers as $handler) {
        if (isset($handler['methods']) && is_array($handler['methods'])) {
            $methods = array_merge($methods, array_keys(array_filter($handler['methods'])));
        }
    }
    $found[] = array('route' => $route, 'methods' => array_values(array_unique($methods)));
}
return $found;
```

If the result exceeds the tool's 64 KiB cap, restrict the prefix to the relevant family,
such as `/fluent-cart/v2/products`. Read the matching route, request class and policy from
installed source; not every field accepted by a controller is a registered REST argument.

## Bounded read

```php
$request = new WP_REST_Request('GET', '/fluent-cart/v2/products');
$request->set_query_params(array('per_page' => 5, 'page' => 1));
$response = rest_do_request($request);
return array('status' => $response->get_status(), 'data' => $response->get_data());
```

The current product read route is `GET /fluent-cart/v2/products/{product}`; replace the
placeholder with the resolved product post ID. Check the response status and error data
before interpreting a record. Use the current route schema for filters and related data.
For large descriptions, read one record or select just needed fields from the response.

## Change and verify

Build a `WP_REST_Request` with the discovered method and path, and use `set_body_params`
for a structured update. For example, product creation uses POST to the products collection;
set `post_status: "draft"` explicitly and inspect `ProductCreateRequest` before supplying
detail fields. Read current records before a targeted update, preserve omitted values and
check whether that endpoint merges or replaces nested arrays. Don't invent an
`expected_content_sha256` or idempotency field on a native API that doesn't accept one.

Product ID, variation ID and download ID identify different records. Updating file delivery
needs the actual download row and its product/variation mappings. Preserve earlier packages
and validate new file identity, version and checksum before switching a licensed download.
Keep credential-bearing URLs out of chat and logs.

Use [Products API](https://dev.fluentcart.com/restapi/products) and the installed route files
for native behavior. Read product, pricing and download records back after a change. Verify
an order or subscription through its own API; a WordPress post save doesn't verify them.
