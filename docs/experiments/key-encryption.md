# Key Encryption

Opt-in experiment that encrypts AI connector API keys at rest.

## Summary

- Extends `Abstract_Feature`.
- While enabled, every `connectors_ai_*_api_key` option is transparently routed through a
  **bundled** libsodium-based secrets API, so the `wp_options` table never contains a plaintext
  key.
- Existing keys are encrypted on opt-in, restored on opt-out, and restored on plugin deactivation
  — users cannot get locked out of their own credentials.

## Bundled secrets backend

No separate plugin needs to be installed. The encryption backend is a minimal, namespaced copy of
[Displace Secrets Manager](https://github.com/ericmann/displace-secrets-manager) vendored into this
plugin at `includes/Vendor/Secrets/` under the `WordPress\AI\Vendor\Secrets` namespace. Only the
runtime SDK is bundled (facade, manager, encrypted-options provider) — the upstream plugin
bootstrap, admin UI, and WP-CLI commands are intentionally omitted. See
[`includes/Vendor/Secrets/README.md`](../../includes/Vendor/Secrets/README.md) for the exact
upstream commit and the list of modifications.

Secrets are stored under the `ai/` namespace (e.g. `ai/openai_api_key`). The experiment calls the
vendored `Secrets::get()` / `Secrets::set()` / `Secrets::delete()` facade directly — it never
defines the global `get_secret()` / `set_secret()` functions, so it will not collide if a site also
installs the real Displace Secrets Manager plugin.

## Requirements

- **libsodium.** The encrypted-options provider needs `sodium_crypto_secretbox()`. This is built
  into PHP 7.2+ and WordPress also ships a `sodium_compat` fallback, so it is effectively always
  available. If, in some unusual environment, sodium is unavailable, the experiment fails safe:
  writes pass through unchanged so user keys are never silently dropped (just stored as plaintext,
  as they would be without the experiment).
- **A dedicated encryption key (recommended).** For meaningful at-rest security, define
  `WP_SECRETS_KEY` in `wp-config.php`. Without it, the provider derives an encryption key from
  existing WordPress salts (`LOGGED_IN_KEY . LOGGED_IN_SALT`) — usable, but it ties your encrypted
  secrets to those salts, so rotating the salts would make stored keys undecryptable.

  Any sufficiently long, random string works as the key (it is hashed to a 32-byte key with
  BLAKE2b). Generate one with a password manager or the
  [WordPress.org salt generator](https://api.wordpress.org/secret-key/1.1/salt/), then add it to
  `wp-config.php`:

  ```php
  define( 'WP_SECRETS_KEY', 'a-long-random-secret-string' );
  ```

### Key rotation

To rotate `WP_SECRETS_KEY`, set the old value as `WP_SECRETS_KEY_PREVIOUS` alongside the new
`WP_SECRETS_KEY`. On the next read the provider transparently re-encrypts the internal master key
under the new key. The previous key can be removed afterward.

```php
define( 'WP_SECRETS_KEY', 'the-new-random-secret-string' );
define( 'WP_SECRETS_KEY_PREVIOUS', 'the-old-random-secret-string' );
```

## How it works

While enabled, the experiment registers two transparent option filters per connector:

- `pre_update_option_{setting_name}` — encrypts the value via `Secrets::set()` and forces the
  `wp_options` row to remain empty.
- `option_{setting_name}` — decrypts and returns the secret on read via `Secrets::get()`; passes
  through to the stored value if no secret exists (handles partially-migrated state).

Each call passes an explicit `[ 'plugin' => 'ai' ]` context. The bundled secrets manager enforces
access control on every operation, granting "self-namespace" access when the caller's plugin slug
matches the key's namespace. Passing the context explicitly guarantees that match — these filters
run in unauthenticated request contexts (cron, front-end, REST) where no user holds the
`manage_secrets` capability, so relying on automatic caller detection would be fragile.

All existing callers — `Connector_Key_Index`, REST dispatch, the AI client registry — keep
working because `get_option()` transparently returns the decrypted value through the read
filter.

## Opt-in / opt-out lifecycle

Migration is driven by the **effective** enabled state — the conjunction of the global features
toggle (`wpai_features_enabled`) and this experiment's individual toggle. Either toggle flipping
off is a transition out of "effectively enabled" and triggers the reverse migration. This matters
because when the global toggle is off the transparent read filter never gets installed at all —
without the reverse migration, the user would be locked out of their own keys.

## Disabling the experiment

Toggle the experiment off from the Experiments settings page. The reverse migration runs as soon
as the toggle (or the global features toggle) flips off.

Avoid using the `wpai_feature_key-encryption_enabled` filter to force-disable this experiment: the
filter only short-circuits `is_enabled()`, so the transparent read filter is never installed —
but no toggle changes, so the reverse migration is never triggered either, and the user is locked
out of encrypted keys. Always change the stored toggle (or the global toggle) instead.
