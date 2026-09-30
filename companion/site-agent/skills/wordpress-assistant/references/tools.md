# Source-derived tool reference

Reference source: wpgaurav/site-agent, commit 51a46952a1feab439e091d2464021da06e8d4c98, includes/class-abilities.php and includes/class-content.php. Live discovery determines the names and schemas used by the host.

| Source tool name | Input fields | Purpose |
| --- | --- | --- |
| site-agent-site-context | none | Site URL, environment, versions, plugins, theme, post types, enabled groups |
| site-agent-list-content | post_type, search, limit, page | Search accessible content; default post, 20 results |
| site-agent-get-content | post_id required | Raw content, title, excerpt, status, content_sha256 |
| site-agent-list-media | search, limit, page | Existing media IDs, URLs, MIME types and alt text |
| site-agent-save-content | post_id, post_type, title, content, excerpt, status, expected_content_sha256 | Creates default to draft; updates require current hash |
| site-agent-list-files | path required, offset, limit | Browse plugin/theme paths |
| site-agent-read-file | path required | UTF-8 source and SHA-256 |
| site-agent-write-file | path, content, expected_sha256 required | Hash-checked file write; new for a new file |
| site-agent-execute-php | code required | PHP statements, without PHP tags |
| site-agent-run-wp-cli | arguments required | An argument array, not a shell string |

Content/media limit is 1-100. File listing limit is 1-200. Save statuses are draft, pending, publish and private. Omitted update fields remain unchanged, including a published status.

Tool groups are disabled by default. Base content reads require Site Agent enabled. Content writes, source inspection, source editing, PHP and WP-CLI each require their relevant opt-in. Tools return errors when unavailable; do not infer success from transport completion.

This version has no dedicated SEO metadata, media import, arbitrary settings, plugin-update or deletion tools. Developer entry points are broader but are not substitutes for missing capabilities without appropriate scope and authorization.
