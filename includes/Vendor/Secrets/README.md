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

WordPress and PHP global *functions* and *constants* are left unqualified; PHP resolves them against the global namespace automatically.

## Updating

To pull a newer upstream version, re-copy the files above, re-apply the two modifications, and bump the commit hash here. These files are excluded from the project's PHPCS and PHPStan rules (see `phpcs.xml.dist` / `phpstan.neon.dist`) so they do not need to be reformatted to match the plugin's coding standards.
