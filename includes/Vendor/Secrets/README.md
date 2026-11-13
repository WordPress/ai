# Vendored: Secrets API (WordPress core)

This directory contains a copy of the Secrets API runtime, bundled so the Key Encryption experiment works on WordPress versions before 7.2 without requiring users to install a separate plugin. On WordPress 7.2 and later, core provides the API and this copy is never loaded.

- **`src/wp-includes/`:** WordPress core trunk at [r64690](https://core.trac.wordpress.org/changeset/64690) (`fb78023b06` in `wordpress-develop`), which follows the Secrets API's introduction in [r64337](https://core.trac.wordpress.org/changeset/64337) ([#66187](https://core.trac.wordpress.org/ticket/66187)).
- **`plugin/class-secrets-api-legacy-reader.php`:** [ericmann/secrets-api](https://github.com/ericmann/secrets-api) at `93be262cf25d1a1829ff30edb5881091597d0d76`. This reader is not part of core.
- **License:** GPL-2.0-or-later. Original copyright © Eric Mann / WordPress Core Contributors.

The `src/wp-includes/` files are core's, changed only in two ways for Plugin Check:

1. An `ABSPATH` guard after each file's header docblock.
2. The `'default'` text domain added to each translation call, so the strings keep using core's translations.

To resync, copy `src/wp-includes/secrets.php` and `src/wp-includes/secrets/` from `wordpress-develop` and reapply those two changes. Nothing else should differ from core.

## What was copied

The core API implementation, plus the one feature-plugin file needed to read keys stored before 1.5.0:

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

## Loader and Non-Interference

The bundled loader (`load.php`) checks `function_exists( 'wp_get_secret' )` before requiring these files. If WordPress Core (7.2+) or a standalone Secrets API plugin is already active, this bundled copy stands down completely so there is never a function or class redeclaration conflict.

It loads only the `src/wp-includes/` files and overrides nothing: it never sets `$GLOBALS['wp_secrets_store']`, `$GLOBALS['wp_secrets_keyring']`, or `$GLOBALS['wp_secrets_provider']`, since core reserves those for a `secrets.php` drop-in. The API therefore behaves the same whether this copy or core provides it.

The feature plugin's other adapters, `Secrets_API_Prototype_Fallback_Store` and `Secrets_API_Migrator`, are deliberately not bundled. The fallback store only maps unnamespaced names, so it never applies to this plugin's `ai/*` keys, and installing it would change `wp_get_secret()` for every plugin on the site. The migrator backs the feature plugin's WP-CLI command, which this plugin does not ship.

## Keys stored before 1.5.0

The plugin used to bundle the Displace Secrets Manager, which stored keys in `_secret_ai/*` rows. That code is gone. `Secrets_Bridge` reads those rows once with `plugin/class-secrets-api-legacy-reader.php`, which it loads only when a legacy row exists, and moves each key to the Secrets API.
