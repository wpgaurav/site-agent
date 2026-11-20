# Site Agent 1.1.0

Released November 20, 2026 through GitHub and FluentCart, alongside Core Tables 1.3.0.

- **Core Forms tools** (`core_forms`, off by default) add 13 `site-agent-core-forms-*` tools.
  - They need Core Forms 5.1 or later. Field wrapper classes and the wp-config.php key report
    need 5.2.
  - With them an assistant can list, read, create, edit, duplicate, publish and trash forms. It
    can build fields from the visual builder's schema with Core Forms' own renderer, change
    settings and messages, manage the ordered action list, rehearse a submission, test one action,
    and read and triage entries.
  - Every change goes through Core Forms' `cf/v1` REST routes as the signed-in administrator, so
    Core Forms' fingerprint checks, locks, sanitizing and revisions apply.
  - Stored secrets show as `[stored]`.
- **Core Commerce tools** (`core_commerce`, off by default) serve the 42 listed `core-forms/*`
  abilities as `core-forms-*` tools. They cover products, plans, releases, orders, licenses,
  activations, subscriptions, payment routing and client invoices.
- **Core Tables tools** (`core_tables`, off by default) serve the 29 listed `core-tables/*`
  abilities as `core-tables-*` tools.
  - They're served only while Core Tables' own MCP read and write settings open them.
  - Core Tables 1.2 registers 11 of these abilities. Core Tables 1.3.0 adds table listing and
    editing, unpublish, trash, restore and permanent delete, revisions, activity, refresh sources
    and appearance defaults.
- **Bundled guides:** `core-forms` and `core-commerce`. Core Tables registers its own guide.
  Site context reports Core Forms as a plugin workflow and offers both guides.
- **OAuth consent and Application Password limits** cover the three new groups. Calls are audited
  with `form N`, `entry N`, `#id` or `table N` as the target.

## Found and fixed before release

On Core Forms 5.1.x, a `field_wrapper_class` sent through `save-form` was stored exactly as sent,
because that version neither sanitizes nor uses the setting. Site Agent now refuses the setting
with `site_agent_core_forms_version` unless Core Forms provides `cf_sanitize_class_list` (5.2+).
`CoreFormsTest` covers both versions.

The docs said the Core Forms tools need Core Forms 4.11. They actually need 4.12 for
`cf_duplicate_form`, and they were tested on 5.1.4 and 5.2.0-beta.1, so the docs now say 5.1.

## Local validation

- **154 PHPUnit tests** passed on PHP 8.3 and 8.5 against the marked disposable fixture:
  - with Core Forms 5.1.4 and Core Tables 1.2.0 plus the 1.3.0 abilities, 1,737 assertions;
  - with Core Forms 5.2.0-beta.1, 1,736 assertions.
  Eight tests skip when Core Forms and Core Tables aren't installed, as in CI.
- **Other checks:** Composer strict validation, the regenerated runtime (unchanged), PHP syntax,
  WordPress coding standards, skill validation (13 skills, 99 files, 74 pinned imports), the
  translation template check, the 9 JavaScript tests, and the artifact and HTTP-fixture guard
  tests.
- **End to end over HTTP**, on a marked disposable copy of a local site, with Core Forms 5.1.4:
  - the client created a form with fields and an email action;
  - a rehearsal passed, and a stale fingerprint was refused;
  - a visitor submitted the form, and the email action ran (the fixture caught the message);
  - the client read the entry back and marked it read;
  - a Core Commerce ability answered.
  The same script passed earlier on 5.2.0-beta.1.
- **Core Tables end to end:** the Core Tables script exercised all 29 tools.
  - It created, edited, published and embedded a table, and restored a revision.
  - It configured a custom-fields source and refused a credential.
  - It re-saved appearance defaults, then unpublished, trashed, restored and deleted the table.
  - With Core Tables' MCP write off, only the 13 read tools were listed. With the Site Agent group
    off, no tools were listed.
- `tests/http-smoke.py` passed in full against the same fixture.

| Local artifact | Bytes | SHA-256 |
| --- | ---: | --- |
| `site-agent-1.1.0.zip` | 807689 | `2745b2fdb581d808b1c542c5e6a721a0c28a3b6d91ea687fc8afdf094890702c` |
| `site-agent-companion-1.1.0.zip` | 23099 | `3d2332024dfef99c42b1c580781962fe02343759174e2f984fc5add1c28a396c` |

The WordPress archive has 466 files under a single `site-agent` root, and its header and
constant both say 1.1.0.
