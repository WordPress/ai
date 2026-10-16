# Vendored: Secrets API (WordPress core)

This directory contains a copy of the Secrets API runtime, bundled so the Key Encryption experiment works on WordPress versions before 7.2 without requiring users to install a separate plugin. On WordPress 7.2 and later, core provides the API and this copy is never loaded.

- **`src/wp-includes/`:** WordPress core trunk at [r64344](https://core.trac.wordpress.org/changeset/64344) (`7a47d39442` in `wordpress-develop`), which follows the Secrets API's introduction in [r64337](https://core.trac.wordpress.org/changeset/64337) ([#66187](https://core.trac.wordpress.org/ticket/66187)).
- **`plugin/`:** [ericmann/secrets-api](https://github.com/ericmann/secrets-api) at `93be262cf25d1a1829ff30edb5881091597d0d76`. These adapters are not part of core.
- **License:** GPL-2.0-or-later. Original copyright © Eric Mann / WordPress Core Contributors.

The `src/wp-includes/` files are core's, changed only in two ways for Plugin Check:

1. An `ABSPATH` guard after each file's header docblock.
2. The `'default'` text domain added to each translation call, so the strings keep using core's translations.

To resync, copy `src/wp-includes/secrets.php` and `src/wp-includes/secrets/` from `wordpress-develop` and reapply those two changes. Nothing else should differ from core.

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
