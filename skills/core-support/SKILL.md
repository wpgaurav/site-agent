---
name: core-support
description: "Inspect and triage Core Support tickets, prepare customer replies and internal notes, maintain documentation and changelogs, and investigate mail, portal and chat issues through Site Agent. Preserve visibility, ticket versions, request keys and delivery boundaries."
---

# Core Support

Core Support owns ticket conversations, customer portals, mail delivery, docs, changelogs
and optional chat. Tickets live in plugin tables, not ordinary WordPress posts. A normal
`list-content` call cannot list tickets.

## Establish the installation

Call `site-agent-site-context` and confirm `core-support/core-support.php` is active.
Read its version, tools and matching registered skills. This guide was checked against
the 0.8.x source family; features in an installed beta can differ. Inspect actual handlers
and registrations instead of treating a roadmap or README as proof of implementation.

Use base content tools for registered docs and portal-page content. Ticket operations use
the plugin's native REST route through explicitly enabled PHP execution; see
[ticket operations](references/operations.md). Keep its permission callbacks, actor and
ownership checks. Don't call the database directly or impersonate a customer.

## Ticket workflow

1. List a narrow status/search/assignment/priority slice, then read the complete matching
   ticket. Use its numeric ID, current `version`, visible messages and delivery state.
2. Distinguish a proposed reply in this chat from a saved **internal note** and a **public
   reply**. Public replies can queue customer mail. Internal notes can still notify staff.
   Neither is an implicit way to save an assistant draft.
3. Prepare the requested text using the full conversation. Read purchase context as
   supporting evidence; don't infer entitlement from an email address or product name.
4. Save only the action the user authorized. A reply needs `visibility`, current `version`
   and a stable `request_key`. Ticket triage uses a version-checked PATCH.
5. On conflict, re-read and reconcile. After a timeout, check for the existing message using
   the same request key; don't create another key for a blind retry.
6. Re-read status, assignment, priority, message visibility and delivery. Queued, captured,
   failed and provider-accepted mail are distinct states; none proves inbox delivery.

Explicit user authorization is required to send a customer reply or message another person.
A request to inspect a ticket authorizes reading and preparing a response, not posting it.
Don't retry failed deliveries, run a mail queue, poll real mailboxes, or send test messages
as a diagnostic without an appropriate request.

## Docs, changelogs and portal pages

- Docs use `csp_doc` and the `csp_collection` taxonomy. Preserve parent/child hierarchy and
  collection membership. New docs start as drafts. Parent and term operations unsupported
  by `save-content` need the plugin's current documented API; don't invent tool fields.
- Changelogs use `csp_changelog`; their content holds release history. Preserve older
  releases, sync mode and source mapping. Never expose the private GitHub token metadata.
- Portal pages can contain native Core Support blocks. Read the registered block schema
  before changing their attributes. Preserve the selected portal URL and page identity.
- Chat settings, learned sources, provider tests and indexing have separate permissions
  and side effects. Don't silently learn private tickets, enable inbound AI, or start a
  provider call while editing a documentation page.

Use current content hashes and staged autosaves as described by Site Agent. A public URL
or successful save alone doesn't verify a customer can see private tickets or documents.
Read only the customer information needed, and never copy credentials, attachments,
relay signatures, private message bodies or full exports into public artifacts.

## Investigation map

Inspect `src/Http/Api.php`, `src/Http/Session.php`, `src/Access/Roles.php`, `src/Tickets/`,
`src/Portal/Settings.php`, `src/Docs/`, `src/Changelog/`, `src/Chat/` and `src/Cli/`.
With WP-CLI explicitly enabled, `core-support doctor` reports diagnostics; inspect its
output before sharing. `deliver`, `mail-run`, `mail-poll`, imports, storage migrations and
changelog sync are mutations. Never run synthetic mail fixtures on a real installation.
