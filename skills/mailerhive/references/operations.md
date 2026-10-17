# MailerHive operations

Examples are code for Site Agent's explicitly enabled `execute-php` tool. Resolve IDs on
the target site first; the numbers below are placeholders. PHP runs with server privileges.
Return only the fields needed and keep code and return values within the 64 KiB limits.

## Read the relevant configuration

```php
if (!class_exists('MailerHive\\Settings')) {
    return new WP_Error('unavailable', 'MailerHive is not loaded.');
}
return array(
    'auto_draft' => \MailerHive\Settings::on('auto_draft'),
    'campaign_ready' => \MailerHive\Email::ready(),
    'draft_list_ids' => \MailerHive\Email::list_ids(),
);
```

Do not return the entire options table or license option. For branding changes, inspect
`Settings::sanitize()` and the registered settings flow before constructing a targeted
merge. Keep unrelated fields; settings have no Site Agent content-hash guard.

## Inspect a campaign link and create a draft

```php
$post = get_post(42);
if (!$post || $post->post_type !== 'mh_issue' || !current_user_can('edit_post', $post->ID)) {
    return new WP_Error('unavailable', 'Issue not found or not editable.');
}
return array(
    'issue_id' => $post->ID,
    'status' => $post->post_status,
    'campaign_id' => (int) get_post_meta($post->ID, '_mh_campaign_id', true),
    'source' => (string) get_post_meta($post->ID, '_mh_source', true),
);
```

After reading the issue and confirming that a campaign draft is in scope:

```php
if (!class_exists('MailerHive\\Email') || !current_user_can('edit_post', 42)) {
    return new WP_Error('unavailable', 'MailerHive draft creation is unavailable.');
}
return \MailerHive\Email::create_draft(42);
```

Inspect `ok`, `campaign_id` and `message`. This method reuses a non-trashed linked campaign.
It does not promise to update an already-created campaign when issue content changes.
Check the MailerPress draft itself, including audience, sender, subject, HTML, tier lists
and unsubscribe links. Never manipulate campaign database status to trigger sending.

## WP-CLI

Use argument arrays with `run-wp-cli`; check installed `wp help mailerhive` before a command
whose arguments differ from these examples. Do not concatenate a shell command.

| Arguments | Purpose and effect |
| --- | --- |
| `["mailerhive", "import-substack", "issue-slug", "--publication=example", "--dry-run"]` | Preview an import before copying posts and media |
| `["mailerhive", "import-campaign", "123"]` | Import a MailerPress campaign into WordPress; mutation |
| `["mailerhive", "sync"]` | Mirror sent campaigns; can publish issues |
| `["mailerhive", "relink"]` | Rewrite imported issue links; mutation |
| `["mailerhive", "paid", "members", "--format=json"]` | Inspect members; contains customer data |
| `["mailerhive", "paid", "access", "person@example.com"]` | Check the person's highest active paid tier |

Inspect the installed paid CLI before using `grant`, `revoke`, `reconcile` or `sweep`.
These change entitlements; `reconcile` can operate on many orders when no ID is passed.
Use a narrow target and verify the resulting access, not just the command exit status.
