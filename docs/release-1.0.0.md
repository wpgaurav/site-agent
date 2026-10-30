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

| Local artifact | Bytes | SHA-256 |
| --- | ---: | --- |
| `site-agent-1.0.0.zip` | 766429 | `ef337b67bf32b5cd90eaadc2ca8f51bd8bcd8d2fb6f63c027700f4d8d7661cd3` |
| `site-agent-companion-1.0.0.zip` | 22510 | `4f2f86794a8f374b6178dc45cf53f736baa5da4bc58a3ab3d82058c42a5192af` |

The WordPress archive has 453 files under a single `site-agent` root, and its header and
constant both say 1.0.0.

## Delivery receipt

Published from merge commit `ff30321` and annotated tag `v1.0.0`, after
[pull request #12](https://github.com/wpgaurav/site-agent/pull/12). The PR run `41021886466` and
the merge-commit run `41022132744` passed all three WordPress jobs on PHP 8.0, 8.3 and 8.5. The
[GitHub release](https://github.com/wpgaurav/site-agent/releases/tag/v1.0.0) is published and
stable, and it is the repository's latest release, so Core Support 1.4.0's one-click install
picks it up. Fresh downloads of both assets matched the sizes and SHA-256 values above byte
for byte.

**Store.** FluentCart post 1180328, free variation 131.

- The server fetched the public GitHub asset and checked it. A 1-byte ranged GET for
  `site-agent-1.0.0.zip` returned 404 (206 for the 0.5.2 control).
- Upload went through FluentCart Pro's R2 driver, so the keys stayed on the server. The
  object read back as 200, 766,429 bytes, same SHA-256.
- One transaction locked and re-checked the previous state (0.5.2, download 469, changelog
  starting at 0.5.2), saved it to a private server snapshot, created download **506**
  (serial 8), set `license_settings.version = 1.0.0` and `global_update_file = 506`, and
  prepended the 1.0.0 changelog. Download 469 and `site-agent-0.5.2.zip` remain for rollback.

**Licensed check: 13 of 13**, with release-verification license 134. It was enabled with a
fresh key that never left the server:

- a wrong key is rejected;
- the key activates and checks valid for this product;
- the store offers 1.0.0 with `license_status` valid;
- the package is an HTTPS R2 address off the store's host, with the exact bytes;
- the activation deactivates;
- a request with only an invalid activation gets no package.

A valid key sent with a wrong activation hash still gets the package, because FluentCart
treats the key as the credential.

Afterwards license 134 was disabled with a rotated key and 0 activations, and the synthetic
site was removed. Customer activations were unchanged (fingerprint `a4a47e50933e3857` before
and after).

**gauravtiwari.org.** It ran a pre-release test build. It was moved to 1.0.0 by atomic folder
swap after PHP 8.3 and 8.5 lint, and OPcache was cleared for its 287 PHP files. The backup is
`~/backups/site-agent-20261030T075352`. Settings were kept, including Core Support tools on, and
Site Agent serves the 7 Core Support tools there.
