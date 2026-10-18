# Site Agent 0.5.1

Bug fixes following the 0.5.0 sweep, released October 18, 2026 through GitHub
and FluentCart. Production installation is a separate action.

- OAuth credentials are site-local and resource-bound. Atomic insert-only and
  expected-value mutations protect revocation and refresh state. Legacy grants migrate
  only where their registered client exists locally; tombstones prevent revoked imports
  from being recreated. Client authorization bypasses stale option caches.
- Authenticated refresh generations recognize previously issued ancestors without an
  unbounded hash history. New families provide this guarantee; hashes discarded by an
  older version cannot be reconstructed. Client activity is refreshed and pending
  registrations can be removed through protected administrator controls.
- Staged edits preserve omitted fields and previous working edits, including within
  core's one-second timestamp resolution. Metadata uses native permissions, schemas,
  coercion and sanitizers. Complete-save hooks see final terms, featured media and meta.
  Duplicate terms resolve consistently, literal backslashes survive, and partial errors
  identify the saved record for recovery.
- File mutations coordinate through a stable pathname lock, validate stale inode handles,
  preserve later edits during rollback and consider both paths for MU move health checks.
  Advisory locks cannot force external writers to cooperate.
- PHP/process output stays valid UTF-8 within the limit, JSON object shapes are preserved,
  unavailable process termination is detected before startup, and uploaded metadata keeps
  literal backslashes.
- Package builders validate staged archives/checksums before promotion and preserve prior
  artifacts on failure. HTTP mutation smoke tests verify both local and server fixture
  identity and read all eleven built-in guides while permitting plugin-added skills.

Regression tests cover the failures rather than asserting implementation text. Prior
versions failed the corresponding autosave, metadata, OAuth, output/media, uninstall,
file-concurrency and package-interruption probes.

## Local validation

- An isolated copy of the full candidate passed **134 PHPUnit tests / 1,396 assertions**
  on both PHP 8.3.35 and PHP 8.5.10, against the marked disposable WordPress 7.1.3 fixture.
  This includes 46 added PHP regression cases relative to 0.5.0.
- Nine JavaScript tests, eleven artifact-preservation tests and seventeen HTTP-fixture
  guard tests passed. Artifact tests cover ordinary failures and HUP/INT/TERM during
  promotion, including repeated signals during rollback. SIGKILL cannot be handled.
- Composer strict validation, all PHP syntax checks, WordPress coding standards, skill
  validation and whitespace checks passed. Regenerated official runtime matched the
  frozen candidate exactly. All 23 application PHP files parsed under PHP 8.0 grammar;
  actual PHP 8.0 / WordPress 6.9 execution was verified by the release CI matrix.
- The native WordPress installer accepted the built ZIP on the disposable fixture.
  Authenticated HTTP MCP checks exercised initialization, all eighteen tools' schemas,
  anonymous/contributor denial, all eleven guide reads and fatal-MU-file rollback.
  Additional HTTP probes verified Unicode truncation, JSON object shapes, title-only
  autosaves and protection of Site Agent's installed source.
- Studio's web runtime had no discoverable WP-CLI binary. HTTP checks required the
  documented unavailable error and explicitly skipped command execution there. Native
  PHP execution of `run-wp-cli` separately returned exit 0 and WordPress 7.1.3.
- Package integrity and source parity passed. All 21 earlier local release ZIPs retained
  their checksums. Temporary fixture credentials were removed, original fixture settings
  restored and the original development symlink reinstated after package testing.

| Local artifact | Bytes | SHA-256 |
| --- | ---: | --- |
| `site-agent-0.5.1.zip` | 756479 | `aa6eb8c2e8e27e9b95f03cc4c3ce866d07033388d5870b0d44fc4c75d6b14cad` |
| `site-agent-companion-0.5.1.zip` | 22515 | `5eb9e2e3ba7e604547b48ad5c5193c846eff2e7191c18cc7999756eeb59688a2` |

The WordPress archive has 387 files; the companion has ten. Production installation
is separate from this GitHub and FluentCart release.

## Delivery receipt

Published from merge commit `6fe3dacf004a05adfc47548dbb79f84a1f30e7a9` and annotated
tag `v0.5.1`, after [pull request #8](https://github.com/wpgaurav/site-agent/pull/8).
The [GitHub release](https://github.com/wpgaurav/site-agent/releases/tag/v0.5.1) is
published and stable. Both the PR run `39090864131` and exact merge-commit run
`39090967259` passed all three WordPress 6.9 jobs on PHP 8.0, 8.3 and 8.5. The PHP 8.0
CI archives matched the canonical extracted file trees. Fresh downloads of both
published GitHub assets matched the sizes and SHA-256 values above byte-for-byte.

Live identity was confirmed as Site Agent, published `fluent-products` post 1180328,
slug `site-agent`, free variation 131, with FluentCart and Pro 2.2.0 active. The verified
GitHub WordPress ZIP was uploaded through the installed R2 driver to the previously
absent root key `site-agent-0.5.1.zip` in the existing bucket, then downloaded again
for byte-size and SHA-256 comparison before the updater metadata changed.

A guarded InnoDB transaction re-read and locked the previous metadata and download,
created download **431**, and set `license_settings.version = 0.5.1` and
`global_update_file = 431`. It prepended the sanitized release changelog while retaining
its history. All unrelated license settings, product content, identity, commerce fields,
variations and metadata were preserved. Download **428** and `site-agent-0.5.0.zip`
remain for rollback; a fresh R2 read matched the preceding release's SHA-256. A private
server snapshot preserves the preceding release metadata and download record.

An existing active license and activation were selected internally. The real licensed
updater passed **13/13 checks, zero failures or skips**: valid new-version metadata,
direct off-site package, matching bytes and SHA-256, ZIP root and embedded version, and
invalid activation receiving no package. An additional request required `success: true`
and the direct R2 service host. No license key, activation hash, customer URL or signed
package URL was printed. Synthetic verification license 134 remains disabled.

On the explicitly marked disposable fixture, `.maintenance` produced actual HTTP 503
while native `WP_Upgrader::download_package()` fetched the licensed R2 package and
verified its size, SHA-256, single root and 0.5.1 header. The maintenance flag was cleared
in `finally`; production was never put in maintenance mode. Scoped R2 reconciliation
listed 247 objects, below the unpaginated 1000-object limit, and found no orphaned Site
Agent objects.

The exact remote staging ZIP, local signed-package metadata and temporary mutation and
verification scripts were removed after verification. The disposable site was stopped.
All 21 earlier local release ZIPs retained their checksums. Previous release rows and
R2 objects remain. This release did not install 0.5.1 on either production site;
gauravtiwari.org's loaded plugin still reported 0.5.0 during final delivery verification.
