# Key Encryption

Opt-in experiment that encrypts AI connector API keys at rest.

## Summary

- While enabled, every `connectors_ai_*_api_key` option is transparently routed through a **bundled** libsodium-based secrets API, so the `wp_options` table never contains a plaintext key.
- Existing keys are encrypted on opt-in, restored on opt-out, and restored on plugin deactivation
  — users cannot get locked out of their own credentials.

## Threat model

Encrypting keys at rest raises the cost of **database-level** exposure. It is not a sandbox around the credentials, and it cannot be one.

**Protects against** — anything that reveals the contents of `wp_options` without also revealing `wp-config.php`:

- A stolen or leaked database dump or backup.
- A SQL-injection read in an unrelated plugin.
- Shared-host or third-party access scoped to the database: support tooling, analytics ETL, a read-only replica, a screenshot of phpMyAdmin.
- Query logs, slow-query logs, or error output that echo option values.

**Does not protect against** — anything executing inside the WordPress PHP process, or anything that can read the filesystem:

- **Any active plugin, theme, mu-plugin, or drop-in.** WordPress has no privilege separation between them; code execution in any one of them is code execution as the whole site. While the experiment is enabled, `get_option( 'connectors_ai_openai_api_key' )` returns the decrypted key to any in-process caller. That is what "transparent" means, and every existing caller (`Connector_Key_Index`, REST dispatch, the AI client registry) depends on it.
- **Filesystem read access.** The encryption key is `WP_SECRETS_KEY`, or `LOGGED_IN_KEY . LOGGED_IN_SALT` when that constant is not defined. Both live in `wp-config.php`, so anyone who can read that file can decrypt the `_secret_*` rows directly. Encryption at rest is worth much less against a full filesystem compromise than against a database-only one.
- **Users who can already see the key.** Anyone able to manage AI settings can read it through the settings screen and the REST API, by design.

### The `plugin` context value is not an authorization boundary

Every call into the bundled SDK passes a caller-asserted context (`[ 'plugin' => 'ai' ]`). The namespace check this feeds is a **collision guard**: it keeps well-behaved plugins out of each other's namespaces and gives the `secrets_access` filter a sensible default to refine. It is not, and cannot be, a boundary between plugins:

- The value is supplied by the caller, so in-process code can assert any namespace it likes.
- Deriving the caller from a backtrace instead would not change that — in-process code can steer a backtrace, for example by invoking the SDK from a core hook so the nearest frame belongs to whichever file core dispatched from.
- It would not matter if it could. The same code can read the decrypted value straight out of `get_option()`, decrypt the `_secret_*` row using the salts, or simply return `true` from the `secrets_access` filter.

## Bundled secrets backend

No separate plugin needs to be installed. The encryption backend is a minimal, namespaced copy of
[Displace Secrets Manager](https://github.com/ericmann/displace-secrets-manager) vendored into this
plugin at `includes/Vendor/Secrets/` under the `WordPress\AI\Vendor\Secrets` namespace. Only the
runtime SDK is bundled (facade, manager, encrypted-options provider) — the upstream plugin
bootstrap, admin UI, and WP-CLI commands are intentionally omitted. See
[`includes/Vendor/Secrets/README.md`](../../includes/Vendor/Secrets/README.md) for the exact
upstream commit and the list of modifications.

Secrets are stored under the `ai/` namespace (e.g. `ai/openai_api_key`). The experiment calls the
vendored `Secrets::get()` / `Secrets::set()` / `Secrets::delete()` facade directly and never defines
the global `get_secret()` / `set_secret()` functions, so the PHP *symbols* do not collide if a site
also installs the real Displace Secrets Manager plugin.

**Storage is shared with that plugin, though.** The vendored provider is byte-identical to upstream,
so both write to `_secret_{namespace}/{key}` options, share the `_secrets_master_key` option, and
derive the same key from `WP_SECRETS_KEY` (or the salts). Running both is interoperable rather than
conflicting — each reads what the other wrote, and rotating `WP_SECRETS_KEY` covers both. The
consequence worth knowing: on such a site the store holds other plugins' secrets alongside `ai/*`,
and the namespace check does not isolate them from in-process code (see
[Threat model](#threat-model)).

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

Each call passes an explicit `[ 'plugin' => 'ai' ]` context. The bundled secrets manager runs a
namespace check on every operation, granting "self-namespace" access when the caller's plugin slug
matches the key's namespace. Passing the context explicitly guarantees that match — these filters
run in unauthenticated request contexts (cron, front-end, REST) where no user holds the
`manage_secrets` capability and backtrace-based caller detection is unreliable. That check is a
collision guard, not an isolation boundary; see [Threat model](#threat-model).

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
