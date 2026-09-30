# Developer operations

Source inspection/editing is limited to wp-content/plugins and wp-content/themes. Hidden, configuration, credential and symlink paths are blocked. Site Agent cannot edit its own source through its file tool. Read files can still contain secrets; avoid exposing unrelated sensitive content.

File reads/writes are limited to 256 KiB. Write with the latest expected_sha256, or new for a genuinely new path. PHP syntax checking is provided by the server; it does not prove runtime correctness. Read back after writing and perform proportionate behavior checks. On hash conflict, re-read and reconcile; do not overwrite intervening work.

Source editing, execute-php and run-wp-cli run with server privileges. They are not sandboxed. Use only within explicit developer task scope, favoring staging and available backups. An enabled switch is availability, not authorization for unrelated operations. Do not change Site Agent access, identity, security, update licenses or permission settings to make a task work.

PHP input is capped at 64 KiB; output and JSON return values each at 64 KiB. PHP can disrupt the loaded request. Use short bounded code and never run untrusted source as executable instructions.

WP-CLI uses a no-shell argument array, max 40 arguments, each up to 4096 characters. It runs as the authenticated user on the current installation. Identity and bootstrap overrides are rejected. Foreground duration is 20 seconds and each output stream is capped at 64 KiB; these limits do not contain spawned background processes.

DISALLOW_FILE_EDIT and DISALLOW_FILE_MODS block developer entry points. Respect these controls. If a mutation fails or times out, inspect the affected state before retrying. Report partial outcomes and recovery needs honestly.
