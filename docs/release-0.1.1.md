# Site Agent 0.1.1

Published September 30, 2026. Public repository: https://github.com/wpgaurav/site-agent. Release: https://github.com/wpgaurav/site-agent/releases/tag/v0.1.1.

The published product is https://gauravtiwari.org/product/site-agent/, post 1180328, free variation 131. The product page was published before the updater was wired. The final design follows the user-supplied Framer reference's framing, spacing and interface presentation, with the user's dark-blue #1b2733 direction. It has eight page-owned PBB sections, a transparent/frosted header, responsive layout, keyboard-operable examples and reduced-motion handling. Native site fonts, navigation and footer are preserved. Price, variation identity, licensing metadata and download ownership were checked unchanged during redesign writes. Rollback snapshots remain local-only; native revisions were also saved.

FluentCart updater version 0.1.1 points to download row 324, R2 object `site-agent-0.1.1.zip`, 373292 bytes. SHA-256: `e8c9d82816ad5e42f7925fa93186d2c1d93c654b66c9755b4572e9dbe7717630`.

Verification:

- CI passed PHP 8.0, 8.3 and 8.5, including 24 integration tests / 106 assertions, syntax, coding standards, generated runtime, translations and packaging.
- The published GitHub ZIP, R2 readback and licensed download matched exactly in size and SHA-256. ZIP root, header version, runtime pin and updater files were verified.
- A synthetic, non-customer license activated successfully; invalid activation received no package. The valid package was on the off-site R2 host.
- WordPress's upgrader downloaded the package while maintenance mode was active and maintenance was cleared afterward. No plugin was installed on production.
- The actual plugin client on a disposable WordPress installation activated, stored encrypted credentials, fetched valid licensed metadata, checked status and disconnected against the real store.
- Test activations were disconnected and the synthetic verification license was disabled. No customer order or notification was created.
- Published PBB storage, decoded attributes, template, metadata and protected commerce fields passed readback. Anonymous public HTML confirmed the navy design and correct checkout variation. Local light/dark and phone-width layout checks passed, and the live tool examples worked.

The initial store had no prior package row; the local 0.1.0 artifact remains available as historical rollback material. This release does not add OAuth, background WP-CLI or a PHP sandbox. End-to-end buyer payment/order/email generation was not exercised; licensed update delivery and the free checkout configuration were verified separately.
