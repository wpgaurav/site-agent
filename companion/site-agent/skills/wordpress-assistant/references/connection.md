# Connection

Endpoint supplied by the owner: https://gauravtiwari.org/wp-json/site-agent/v1/mcp
Transport: Streamable HTTP.
Server source requires HTTPS and native WordPress Application Password authentication, supplied through a Basic Authorization header or explicitly enabled URL authentication. The supplied Authorization value was a placeholder, not a usable credential. OAuth is outside the server's current version.

This package deliberately contains no Authorization header or credentials. Use a host-supported private connection setting to supply authentication if the host supports this server's Basic header requirement. If the host requires OAuth, or cannot configure headers or retain an authenticated URL, the direct connection is blocked; report that limitation. Do not claim installation authenticates the server, invent an OAuth endpoint, publish credentials in a manifest, or silently deploy a proxy.

WordPress setup: install the complete Site Agent release ZIP, open Tools > Site Agent, enable access and required groups, and create a dedicated Application Password in the administrator's profile. On multisite, server access requires super-administrator rights. Keep developer groups off unless the task needs them.

Never ask for credentials in chat. The Tools > Site Agent converter runs in the browser and can generate a Basic header for a compatible client. Use secure host credential entry when available. Disabling Site Agent or revoking the dedicated Application Password revokes access.

Verification after connection: discover live tools, call the harmless site-context tool, verify site_url and enabled_tools, then read existing content. Do not test authentication with a write.

The server also supports explicitly enabled URL authentication via an auth query parameter containing Base64-encoded username and Application Password credentials. Keep the complete URL only in a host-supported private connection setting, never in manifests, instructions or reports. Each request must retain its query parameters. OAuth-only hosts or clients that discard the query still require a compatible bridge; do not claim universal client support.
