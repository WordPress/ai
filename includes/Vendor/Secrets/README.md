# Vendored: Displace Secrets Manager (SDK subset)

This directory contains a **minimal, namespaced copy** of the secrets SDK from [ericmann/displace-secrets-manager](https://github.com/ericmann/displace-secrets-manager), bundled so the Key Encryption experiment works without requiring users to install a separate plugin.

- **Upstream:** [ericmann/displace-secrets-manager](https://github.com/ericmann/displace-secrets-manager)
- **Vendored commit:** `49c6aca6beabefc4ed726737d4a88e1baf6869cb`
- **License:** GPL-2.0-or-later. Original copyright © Eric Mann.

## What was copied

Only the runtime SDK needed to encrypt/decrypt secrets:

| Vendored file | Upstream source |
| --- | --- |
| `Secrets.php` | `includes/class-secrets.php` |
| `Secrets_Manager.php` | `includes/class-secrets-manager.php` |
| `Secrets_Provider.php` | `includes/interface-secrets-provider.php` |
| `Secrets_Exception.php` | `includes/class-secrets-exception.php` |
| `Secrets_Context.php` | `includes/class-secrets-context.php` |
| `Secrets_Audit.php` | `includes/class-secrets-audit.php` |
| `Secrets_Provider_Encrypted_Options.php` | `includes/providers/class-secrets-provider-encrypted-options.php` |

## What was intentionally NOT copied

- The plugin bootstrap (`displace-secrets-manager.php`) and its global `get_secret()` / `set_secret()` functions — we call the `Secrets` facade directly, which avoids fatal "cannot redeclare function" collisions if a site also installs the real plugin.
- Admin UI (`admin/`), Site Health check, and WP-CLI commands — not needed at runtime.

## Modifications applied to each copied file

Kept byte-for-byte identical to upstream **except**:

1. A `namespace WordPress\AI\Vendor\Secrets;` declaration was inserted after the file docblock (so the classes are isolated from the global namespace and resolved by the plugin's PSR-4 autoloader).
2. References to global classes that are not in this namespace were fully qualified: in `Secrets_Exception.php`, `RuntimeException` → `\RuntimeException` and `Throwable` → `\Throwable`. (Other global classes — `\WP_Error`, `\Exception`, `\SodiumException` — were already qualified upstream.)
3. `Secrets_Context::detect_calling_plugin()` also skips backtrace frames originating in this directory (matched against `__DIR__`). Upstream recognises its own frames by the `displace-secrets-manager/` plugin directory, which never matches a copy vendored inside a *different* plugin — so before this change the nearest frame was always an SDK file and every lookup returned the host plugin's slug (`ai`) regardless of the real caller. Regression tests live in `tests/Integration/Includes/Vendor/Secrets/`.
4. `Secrets_Context::detect_calling_plugin()` changed from `private static` to `public static`, so the audit logger (below) and tests can reach it.
5. `Secrets_Audit::log()` adds a `detected_plugin` entry to the context passed to the `secrets_accessed` and `secrets_{$operation}` actions, so audit consumers see the backtrace-derived caller next to the caller-asserted `plugin` value and can flag a mismatch. Computed only when a listener is registered, since detection walks a backtrace.
6. Docblocks on `Secrets_Context::__construct()`, `Secrets_Context::can_access_namespace()`, `Secrets_Manager::check_access()`, and the `Secrets` facade were expanded to state that the namespace check is a collision guard rather than an isolation boundary between plugins. No behaviour change. See the [threat model](../../../docs/experiments/key-encryption.md#threat-model) for why.

WordPress and PHP global *functions* and *constants* are left unqualified; PHP resolves them against the global namespace automatically.

## Storage compatibility

The provider writes secrets to `_secret_{namespace}/{key}` options and keeps its encrypted master key in `_secrets_master_key`, both inherited unchanged from upstream. A site running the real Displace Secrets Manager plugin alongside this one therefore **shares that storage and master key** with it: each side reads what the other wrote, and a `WP_SECRETS_KEY` rotation covers both. Interoperable, not conflicting — but do not assume the store only ever holds `ai/*` keys.

## Updating

To pull a newer upstream version, re-copy the files above, re-apply the two modifications, and bump the commit hash here. These files are excluded from the project's PHPCS and PHPStan rules (see `phpcs.xml.dist` / `phpstan.neon.dist`) so they do not need to be reformatted to match the plugin's coding standards.
