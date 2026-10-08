# Vendored: Secrets API (WordPress 7.2 Core Proposal Feature Plugin)

This directory contains a copy of the Secrets API runtime from [ericmann/secrets-api](https://github.com/ericmann/secrets-api), bundled so the Key Encryption experiment functions out of the box on the new WordPress Secrets API without requiring users to install a separate plugin.

- **Upstream:** [ericmann/secrets-api](https://github.com/ericmann/secrets-api)
- **Vendored commit:** `93be262cf25d1a1829ff30edb5881091597d0d76`
- **License:** GPL-2.0-or-later. Original copyright © Eric Mann / WordPress Core Contributors.

## What was copied

The core-bound API implementation and plugin adoption adapters:

| Vendored file | Upstream source |
| --- | --- |
| `src/wp-includes/secrets.php` | `src/wp-includes/secrets.php` |
| `src/wp-includes/secrets/class-wp-secret.php` | `src/wp-includes/secrets/class-wp-secret.php` |
| `src/wp-includes/secrets/class-wp-secret-version.php` | `src/wp-includes/secrets/class-wp-secret-version.php` |
| `src/wp-includes/secrets/interface-wp-secrets-provider.php` | `src/wp-includes/secrets/interface-wp-secrets-provider.php` |
| `src/wp-includes/secrets/interface-wp-secrets-keyring.php` | `src/wp-includes/secrets/interface-wp-secrets-keyring.php` |
| `src/wp-includes/secrets/interface-wp-secrets-store.php` | `src/wp-includes/secrets/interface-wp-secrets-store.php` |
| `src/wp-includes/secrets/class-wp-secrets-config-key-provider.php` | `src/wp-includes/secrets/class-wp-secrets-config-key-provider.php` |
| `src/wp-includes/secrets/class-wp-secrets-broken-keyring.php` | `src/wp-includes/secrets/class-wp-secrets-broken-keyring.php` |
| `src/wp-includes/secrets/class-wp-secrets-cipher.php` | `src/wp-includes/secrets/class-wp-secrets-cipher.php` |
| `src/wp-includes/secrets/class-wp-secrets-key-manager.php` | `src/wp-includes/secrets/class-wp-secrets-key-manager.php` |
| `src/wp-includes/secrets/class-wp-secrets-option-store.php` | `src/wp-includes/secrets/class-wp-secrets-option-store.php` |
| `src/wp-includes/secrets/class-wp-secrets-broken-store.php` | `src/wp-includes/secrets/class-wp-secrets-broken-store.php` |
| `src/wp-includes/secrets/class-wp-secrets-libsodium-provider.php` | `src/wp-includes/secrets/class-wp-secrets-libsodium-provider.php` |
| `src/wp-includes/secrets/class-wp-secrets-broken-provider.php` | `src/wp-includes/secrets/class-wp-secrets-broken-provider.php` |
| `plugin/class-secrets-api-legacy-reader.php` | `plugin/class-secrets-api-legacy-reader.php` |
| `plugin/class-secrets-api-prototype-fallback-store.php` | `plugin/class-secrets-api-prototype-fallback-store.php` |
| `plugin/class-secrets-api-migrator.php` | `plugin/class-secrets-api-migrator.php` |

## Loader and Non-Interference

The bundled loader (`load.php`) checks `function_exists( 'wp_get_secret' )` before requiring these files. If WordPress Core (7.2+) or a standalone Secrets API plugin is already active, this bundled copy stands down completely so there is never a function or class redeclaration conflict.

When loaded, it sets `$GLOBALS['wp_secrets_store']` to an instance of `Secrets_API_Prototype_Fallback_Store( new WP_Secrets_Option_Store() )`. This allows existing prototype rows (`_secret_*`) from earlier versions of this plugin to be read and transparently promoted to the current format on first access.
