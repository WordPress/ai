# Vendored: Secrets API (WordPress core)

This directory contains a copy of the Secrets API runtime, bundled so the Key Encryption experiment works without requiring users to install a separate plugin. No released WordPress version ships it yet but if a future core version loads it, or the standalone Secrets API plugin is installed, this copy is never loaded.

- **`src/wp-includes/`:** WordPress core trunk at [r65466](https://core.trac.wordpress.org/changeset/65466) (`b102f994` in `wordpress-develop`), the last Secrets API change before it was reverted in [r65538](https://core.trac.wordpress.org/changeset/65538). The API was introduced in [r64337](https://core.trac.wordpress.org/changeset/64337) ([#66187](https://core.trac.wordpress.org/ticket/66187)). The same code ships in [ericmann/secrets-api](https://github.com/ericmann/secrets-api) 0.5.1.
- **`plugin/class-secrets-api-legacy-reader.php`:** [ericmann/secrets-api](https://github.com/ericmann/secrets-api) at `3e72abe7a3ac` (0.5.1), with only an `ABSPATH` guard added. This reader is not part of core.
- **License:** GPL-2.0-or-later. Original copyright © Eric Mann / WordPress Core Contributors.

The `src/wp-includes/` files are core's, changed only in two ways for Plugin Check:

1. An `ABSPATH` guard after each file's header docblock.
2. The `'default'` text domain added to each translation call, so the strings keep using core's translations.

To resync, copy `src/wp-includes/secrets.php` and `src/wp-includes/secrets/` from `wordpress-develop` at `b102f994` (the parent of the revert), or from the `src/wp-includes/` directory of ericmann/secrets-api, and reapply those two changes. Nothing else should differ from core.

## What was copied

The core API implementation, plus the one feature-plugin file needed to read keys stored before 1.5.0:

| Vendored file | Upstream source |
| --- | --- |
| `src/wp-includes/secrets.php` | `src/wp-includes/secrets.php` |
| `src/wp-includes/secrets/class-wp-secret.php` | `src/wp-includes/secrets/class-wp-secret.php` |
| `src/wp-includes/secrets/class-wp-secret-version.php` | `src/wp-includes/secrets/class-wp-secret-version.php` |
| `src/wp-includes/secrets/class-wp-secrets-provider.php` | `src/wp-includes/secrets/class-wp-secrets-provider.php` |
| `src/wp-includes/secrets/class-wp-secrets-keyring.php` | `src/wp-includes/secrets/class-wp-secrets-keyring.php` |
| `src/wp-includes/secrets/class-wp-secrets-store.php` | `src/wp-includes/secrets/class-wp-secrets-store.php` |
| `src/wp-includes/secrets/class-wp-secrets-config-key-provider.php` | `src/wp-includes/secrets/class-wp-secrets-config-key-provider.php` |
| `src/wp-includes/secrets/class-wp-secrets-broken-keyring.php` | `src/wp-includes/secrets/class-wp-secrets-broken-keyring.php` |
| `src/wp-includes/secrets/class-wp-secrets-cipher.php` | `src/wp-includes/secrets/class-wp-secrets-cipher.php` |
| `src/wp-includes/secrets/class-wp-secrets-key-manager.php` | `src/wp-includes/secrets/class-wp-secrets-key-manager.php` |
| `src/wp-includes/secrets/class-wp-secrets-option-store.php` | `src/wp-includes/secrets/class-wp-secrets-option-store.php` |
| `src/wp-includes/secrets/class-wp-secrets-broken-store.php` | `src/wp-includes/secrets/class-wp-secrets-broken-store.php` |
| `src/wp-includes/secrets/class-wp-secrets-libsodium-provider.php` | `src/wp-includes/secrets/class-wp-secrets-libsodium-provider.php` |
| `src/wp-includes/secrets/class-wp-secrets-broken-provider.php` | `src/wp-includes/secrets/class-wp-secrets-broken-provider.php` |
| `plugin/class-secrets-api-legacy-reader.php` | `plugin/class-secrets-api-legacy-reader.php` |

## Loader and Non-Interference

The bundled loader (`load.php`) checks `function_exists( 'wp_get_secret' )` before requiring these files. If WordPress core or the standalone Secrets API plugin already provides the API, this bundled copy stands down completely so there is never a function or class redeclaration conflict.

It loads only the `src/wp-includes/` files and overrides nothing: it never sets `$GLOBALS['wp_secrets_store']`, `$GLOBALS['wp_secrets_keyring']`, or `$GLOBALS['wp_secrets_provider']`, since core reserves those for a `secrets.php` drop-in. The API therefore behaves the same whether this copy or core provides it.

The feature plugin's other adapters, `Secrets_API_Prototype_Fallback_Store` and `Secrets_API_Migrator`, are deliberately not bundled. The fallback store only maps unnamespaced names, so it never applies to this plugin's `ai/*` keys, and installing it would change `wp_get_secret()` for every plugin on the site. The migrator backs the feature plugin's WP-CLI command, which this plugin does not ship.

## Keys stored before 1.5.0

The plugin used to bundle the Displace Secrets Manager, which stored keys in `_secret_ai/*` rows. That code is gone. `Secrets_Bridge` reads those rows once with `plugin/class-secrets-api-legacy-reader.php`, which it loads only when a legacy row exists, and moves each key to the Secrets API.
