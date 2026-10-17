# Plugin workflow guidance

Source work for Site Agent 0.5.0, October 17, 2026. These notes cover implementation
and local verification. Release delivery is recorded in the version's release receipt;
production installation is a separate outcome.

## Design

Extend the existing read-only skills catalog rather than introduce a new privileged
dispatcher. The five existing builder skills remain first; six plugin guides follow.
Keep plugin-provided skill names free, so the current plugin can supply its own detailed
commands without being overwritten by a bundled snapshot.

`site-context.integrations` includes six entries with `skill`, `name`, `file`, `state`,
`version` and associated `skills`. State is `active`, `installed` or `missing`, based on
WordPress's plugin inventory and active-plugin checks, including native network activation
semantics. The installed header supplies the version even while inactive. A skill's
`detected` flag is true only when that plugin is active. An active plugin can still lack
a dependency or enabled module; its own preflight decides API availability.

All bundled guides are readable under base Site Agent access, including for inactive or
missing plugins. Plugin execution uses existing opt-in PHP or WP-CLI tools and native
APIs. This change doesn't create additional execution tools, alter authentication, enable
modules or access groups, or change license gating. `builders` and per-post `builder`
retain their existing layout-storage meaning; PBB sections can be Gutenberg content.

## Source evidence

The guides were independently authored from local source, with no application code,
dependencies or customer data copied into the package.

| Guide | Inspected source | Relevant entry points |
| --- | --- | --- |
| `mailerhive` | MailerHive 1.4.5, revision `17d6c56` | `mh_issue`, `MailerHive\Email::create_draft`, mirroring and `mailerhive` CLI |
| `core-support` | Core Support 0.8.x, revision `d011338` and current working source (`0.8.5-dev.1`) | `src/Http/Api.php`, ticket store/policy, `csp_doc`, `csp_changelog`, doctor and mail CLI |
| `fluentcart` | FluentCart 1.6.6 installed local QA source | Native `fluent-cart/v2` route registrations, policies and product request classes |
| `gt-extensions-fluentcart` | GT Extensions 2.12.x, revision `f94a0d8` | `gtfc_agent`, agent skill registrations and module gates |
| `stackless` | Stackless 1.5.1, revision `56f76dc`, plus optional theme source | `stackless_agent`, live command listing, module functions and registered skills |
| `page-blocks-builder` | PBB 4.2.0, revision `ebf43ce` | `gt_pb_agent`, `pbb/v1`, working-content hashes/autosaves, library ownership and templates |

Core Support's checkout has unrelated ongoing changes; they were inspected only, not
edited. Installed source and live discovery take precedence over these reference versions.
FluentCart's [REST overview](https://dev.fluentcart.com/restapi/) and
[Products API](https://dev.fluentcart.com/restapi/products) were also checked. Some older
documentation uses different route versions, so guides require current route discovery.

## Verification contract

`SkillsTest` covers all six integration states using a synthetic plugin inventory on an
explicitly marked disposable WordPress installation. It checks active/inactive/missing
versions, output schemas, base-only guide access, preservation of plugin-provided GT/PBB
skills, bundled-name protection, separation from builder detection and disabled execution
groups. No target plugin is installed or activated by these state tests.

`bin/sync-skills.py` validates all eleven catalog directories, linked reference files,
safe paths and sizes, the original pinned imports, and parity with the PHP catalog.
The existing build allowlist includes `skills/`; the companion directs clients to these
guides and the installed plugin's skills.

Run Composer validation, runtime regeneration/freshness, syntax checks, WordPress coding
standards, the WordPress integration suite, translation freshness, skill validation,
`bash bin/build.sh` and `python3 bin/build-companion.py`. Validate PHP examples as syntax,
and inspect the built ZIP inventory and version consistency. Keep all prior release ZIPs
unchanged. These checks validate Site Agent's delivery and discovery of guidance, not
end-to-end operation of every external payment, mail, chat or support provider.

## Local results

- WordPress integration suite: 88 tests, 893 assertions passed on both PHP 8.3.35 and
  PHP 8.5.10 against a newly created, explicitly marked disposable WordPress 7.1.3 Studio fixture.
- Composer strict validation, all PHP syntax checks and WordPress coding standards passed.
  Regenerated scoped runtime is byte-for-byte unchanged from the committed runtime.
- All eleven skills and 93 files passed offline validation, including 74 unchanged pinned
  imports. Eleven PHP examples in the new guides passed syntax compilation.
- Translation template regenerated and independently checked for freshness. Documentation
  links and whitespace checks passed.
- Plugin and credential-free companion 0.5.0 ZIPs built successfully. The plugin archive's
  452 entries match the allowlisted source byte-for-byte, with all eleven skills included.
  All 19 previous release ZIPs retained their checksums.
- These local checks didn't install plugins on production or perform customer operations.
  GitHub and licensed download delivery require their own release verification.
