# Site Agent 1.0.0

Released October 30, 2026 through GitHub and FluentCart, alongside Core Support 1.4.0.

- **Core Support tools** (`core_support`, off by default) serve Core Support's own abilities on Site
  Agent's endpoint as `core-support-*` tools: `get-activity`, `list-tickets`, `get-ticket`,
  `notify-team`, `get-roadmap`, `add-roadmap-item` and `update-roadmap-item`.
  - Only those 7 are served, and only from Core Support's `core-support` category
    (`Core_Support::SERVED`). An ability added later, or one another plugin registers under the same
    prefix, never reaches a connection that approved the group for what it does now.
  - Every call passes the group switch, the OAuth grant's approved groups or the Application
    Password's limits, and the administrator transport check, as Bricks' direct tools do. Grants
    approved before the group existed don't include it.
  - Core Support's own capability checks still apply. None of the tools replies to customers.
- **OAuth consent** offers "Check Core Support tickets and feature requests, send team alerts and
  work the roadmap" while the group is on and Core Support is active.
- **Audit history:** successful read-only checks (`get-activity`, `list-tickets`, `get-ticket`,
  `get-roadmap`) aren't recorded, so a watch polling every 15 minutes doesn't push other records
  out of the last 100. Failed checks and every change are recorded with `#id` as the target.
- **Core Support guide:** the bundled skill describes the tools and the watch pattern: keep the
  first cursor as a baseline, then message only when events come back.
- No change to the existing tools, schemas, OAuth flows or licensing.

## Review before release

An independent review of the diff found no high-severity issues. This release fixes its two
medium findings:

- Every `core-support/*` ability was served. It's now the fixed list above.
- Polling would have cleared the 100-row audit history in about a day.

It also adds the missing tests, translation template and readme entries.

## Local validation

- **139 PHPUnit tests / 1,416 assertions** on PHP 8.5 and 8.3 against the marked disposable
  fixture, including 5 new `CoreSupportTest` cases:
  - only the listed abilities are served;
  - the switch decides access;
  - tools are hidden while the group is off;
  - calls are limited and changes audited without their text;
  - successful polls stay out of the audit.
- **Other checks:** Composer strict validation, the regenerated runtime (unchanged), PHP syntax,
  WordPress coding standards, skill validation (11 skills, 93 files, 74 pinned imports), the
  translation template check, the 9 JavaScript tests, and the artifact and HTTP-fixture guard
  tests.
- **End to end with Core Support 1.4.0 on a local site:** `tools/list` showed the 7
  `core-support-*` tools only while the group was on, and `core-support-get-activity` returned a
  synthetic new ticket, customer reply and feature request over the MCP endpoint.
