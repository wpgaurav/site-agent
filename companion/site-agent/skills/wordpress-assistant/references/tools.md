# Source-derived tool reference

Reference source: wpgaurav/site-agent 0.3.0, includes/class-abilities.php, includes/class-bricks.php, includes/class-skills.php, includes/class-content.php, includes/class-media.php and includes/class-files.php. Live discovery determines the names and schemas used by the host; every argument carries a description there.

| Source tool name | Input fields | Purpose |
| --- | --- | --- |
| site-agent-site-context | none | Site URL, environment, versions, plugins, theme, active page builders, post types, taxonomies, tools available to this connection |
| site-agent-list-content | post_type, status, orderby, order, search, limit, page | Search accessible content; default post, 20 results, most recently modified first |
| site-agent-get-content | post_id, url or slug (+ post_type), autosave_content | Raw content, title, slug, status, dates, link, terms, featured_media, meta, your autosave (newer flag; its text with autosave_content), builder, content_sha256 |
| site-agent-list-terms | taxonomy, search, limit, page | Term IDs, names, slugs, parents and counts; default category, 50 results |
| site-agent-list-media | mime_type, search, limit, page | Existing media IDs, URLs, MIME types, dimensions and alt text |
| site-agent-list-skills | none | Bundled builder skills (gutenberg, generateblocks, elementor, bricks, divi), whether each builder is active, and their files |
| site-agent-get-skill | skill required; path (default SKILL.md) | Read a builder skill or one of its reference, pattern or example files |
| site-agent-save-content | post_id, post_type, title, content, excerpt, status, slug, date_gmt, terms, featured_media, meta, expected_content_sha256 | Creates default to draft; updates require current hash; edits to live posts are staged unless status is passed |
| site-agent-upload-media | url or data_base64 + filename; title, alt, caption, description, post_id | Import into the media library with alt text |
| site-agent-update-media | id required; title, alt, caption, description | Change media metadata |
| site-agent-list-files | path required, offset, limit | Browse plugin, theme and mu-plugin paths with sizes and modification times |
| site-agent-read-file | path required | UTF-8 source and SHA-256 |
| site-agent-write-file | path, content, expected_sha256 required | Hash-checked write; new for a new file; fatal PHP changes are reverted |
| site-agent-create-directory | path required | Create a directory and missing parents |
| site-agent-delete-file | path, expected_sha256 required | Delete a hash-matched file, or an empty directory with directory |
| site-agent-move-file | from, to, expected_sha256 required | Rename a hash-matched file; the destination must not exist |
| site-agent-execute-php | code required | PHP statements, without PHP tags, exit or die |
| site-agent-run-wp-cli | arguments required | An argument array, not a shell string |
| site-agent-bricks-abilities | ability_name or search | Bricks 2.4+ abilities with summaries, hints and direct tool names; one ability in full with its schemas |
| site-agent-run-bricks-ability | ability_name required, parameters | Run a Bricks ability; Bricks checks its own permissions and schema |
| bricks-* | per Bricks schema | Bricks' own fast-path tools, served unchanged when Bricks tools are enabled |

Content/media/term limit is 1-100. File listing limit is 1-200. Save statuses are draft, pending, publish, private and future; future needs a future date_gmt. Omitted update fields remain unchanged. Updating a published, private or scheduled post without status saves an autosave for human review and returns staged true; pass status (for example publish) only when the user asked to change the live post. Templates and template parts cannot be staged.

Terms replace the post's terms per taxonomy and accept IDs or names (missing names are created). Meta accepts only the keys the site allows, typically Rank Math or Yoast SEO title, description and focus keyword; an empty string deletes a value. Page builder layout meta (Elementor, Bricks, Divi) is refused with builder_meta; use the matching builder skill instead. Prefer existing media over uploads, and always provide meaningful alt text.

Tool groups are disabled by default. Base reads require Site Agent enabled. Content writes, source inspection, source editing, PHP, WP-CLI and Bricks tools each require their relevant opt-in (Bricks tools also need Bricks 2.4+ with its AI/MCP abilities on, and Bricks' PHP ability also needs PHP execution), and an Application Password can be limited to fewer groups, so a connection may see fewer tools than the site enables. Tools return errors with the underlying message; do not infer success from transport completion.

This version has no arbitrary settings, plugin-update or post deletion tools. Developer entry points are broader but are not substitutes for missing capabilities without appropriate scope and authorization.
