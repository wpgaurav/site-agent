# Site Agent 0.1.2

Adds a browser-only converter to Tools > Site Agent. It accepts a WordPress username and a dedicated Application Password and copies the Base64 token, full Basic Authorization value or complete MCP configuration. Grouped passwords and UTF-8 usernames are supported. Generated tokens start masked; input changes, explicit clearing and navigation invalidate them. Converter inputs have no form names and are not submitted or stored by Site Agent. The operating system clipboard retains copied data until replaced.

The public GitHub release, protected FluentCart download 325 and production installation use the same 376252-byte ZIP, SHA-256 `a86da8af21a231a5b1d2a61c46747335b8866053d9e1cf161baa698b91847670`. All 282 installed files match the released package. Version 0.1.1 remains available as FluentCart rollback row 324 and in a private production backup.

Validation passed 24 WordPress integration tests with 106 assertions, 6 credential-converter tests, coding standards, PHP/JavaScript syntax, translations, runtime freshness and packaging. CI passed PHP 8.0, 8.3 and 8.5. Browser checks on the rendered converter covered correct encoding, masking, copying and clearing with synthetic inputs.

Licensed delivery returned the correct off-site R2 package, matched its checksum and ZIP identity, refused invalid activation and downloaded successfully through WordPress while maintenance mode was active. The synthetic verification license was disconnected and disabled. Both Site Agent R2 objects are referenced; no scoped orphan remained.

After production deployment, 21 public HTTPS checks passed for the existing configured access groups, including authentication, initialization, reads, protocol handling and session termination. The temporary test credential was revoked and removed, and public site health checks passed. Current access settings, update credentials, product content, commerce records and unrelated active plugins were preserved. No production content or source-write tool was executed.
