# Key Encryption

Opt-in experiment that encrypts AI connector API keys at rest.

## Summary

- While enabled, every `connectors_ai_*_api_key` option is transparently routed through a **bundled** libsodium-based secrets API, so the `wp_options` table never contains a plaintext key.
- Existing keys are encrypted on opt-in, restored on opt-out, and restored on plugin deactivation
  — users cannot get locked out of their own credentials.

## At a Glance

- **Type:** Experiment (may change or be removed in any release; try it on a staging site first)
- **Runs without a user action:** Doesn't call your AI provider
- **When it runs:** Encrypts stored AI provider API keys when enabled
- **Sent to your AI provider:** Nothing
- **Requests and data:** Restores plaintext keys when disabled

## Threat model

Encrypting keys at rest raises the cost of **database-level** exposure. It is not a sandbox around the credentials, and it cannot be one.

**Protects against** — anything that reveals the contents of `wp_options` without also revealing `wp-config.php`:

- A stolen or leaked database dump or backup.
- A SQL-injection read in an unrelated plugin.
- Shared-host or third-party access scoped to the database: support tooling, analytics ETL, a read-only replica, a screenshot of phpMyAdmin.
- Query logs, slow-query logs, or error output that echo option values.

**Does not protect against** — anything executing inside the WordPress PHP process, or anything that can read the filesystem:

- **Any active plugin, theme, mu-plugin, or drop-in.** WordPress has no privilege separation between them; code execution in any one of them is code execution as the whole site. While the experiment is enabled, `get_option( 'connectors_ai_openai_api_key' )` returns the decrypted key to any in-process caller. That is what "transparent" means, and every existing caller (`Connector_Key_Index`, REST dispatch, the AI client registry) depends on it.
- **Filesystem read access.** The encryption key is `WP_SECRETS_KEY`, which lives in `wp-config.php`, so anyone who can read that file can decrypt the `_wp_secret_*` rows directly. Encryption at rest is worth much less against a full filesystem compromise than against a database-only one.
- **Users who can already see the key.** Anyone able to manage AI settings can read it through the settings screen and the REST API, by design.

## Secrets backend & Secrets API integration

The Key Encryption experiment is built on the [WordPress Secrets API](https://github.com/ericmann/secrets-api) (the feature plugin proposed for WordPress 7.2 Core):

- **Bundled feature plugin:** The experiment bundles the Secrets API runtime in `includes/Vendor/Secrets/`, providing `wp_get_secret()`, `wp_set_secret()`, and `wp_delete_secret()` out of the box with zero external dependencies.
- **Native core & standalone compatibility:** If WordPress Core (7.2+) or a standalone Secrets API plugin is already present, the bundled loader gracefully stands down and Key Encryption delegates directly to the environment's implementation.
- **Automated migration:** Keys stored before 1.5.0 in `_secret_ai/*` rows are moved to the Secrets API on upgrade, or on first read. They are read with the bundled read-only `Secrets_API_Legacy_Reader`; the old Displace Secrets Manager code is no longer included.

Secrets are stored under the `ai/` namespace (e.g. `ai/openai_api_key`).

## Requirements

- **libsodium.** The Secrets API needs libsodium. This is built
  into PHP 7.2+ and WordPress also ships a `sodium_compat` fallback, so it is effectively always
  available. If, in some unusual environment, sodium is unavailable, the experiment fails safe:
  writes pass through unchanged so user keys are never silently dropped (just stored as plaintext,
  as they would be without the experiment).
- **`WP_SECRETS_KEY` in `wp-config.php` (required).** The Secrets API encrypts everything under
  this key and has no fallback. Without a usable key, nothing can be encrypted, so the experiment
  leaves API keys in plaintext, as they would be without it, and shows administrators a notice.
  Once the key is added, the keys are encrypted on the next page load. Empty values, the
  `wp-config-sample.php` placeholder and copies of an authentication key or salt are rejected.
  WordPress 7.2 adds the key to `wp-config.php` for new installs. Existing sites must add it by
  hand, below the salts.

  Generate the recommended form, a base64-encoded random 32-byte key:

  ```sh
  php -r 'echo base64_encode( random_bytes( 32 ) ), "\n";'
  ```

  ```php
  define( 'WP_SECRETS_KEY', 'the-generated-value' );
  ```

  Once secrets are stored, don't change or remove the key: every stored secret stays unreadable
  until the old value is restored.

### Key rotation

Changing `WP_SECRETS_KEY` is not automatic. Set the old value as `WP_SECRETS_KEY_PREVIOUS` next
to the new `WP_SECRETS_KEY`, re-wrap the root key once, then remove `WP_SECRETS_KEY_PREVIOUS`:

```php
define( 'WP_SECRETS_KEY', 'the-new-value' );
define( 'WP_SECRETS_KEY_PREVIOUS', 'the-old-value' );
```

```sh
wp eval '( new WP_Secrets_Key_Manager() )->rotate_site_key( new WP_Secrets_Config_Key_Provider( true ), new WP_Secrets_Config_Key_Provider() );'
```

No stored secret is rewritten. On multisite, one run covers the network.

### Keys stored without `WP_SECRETS_KEY`

Before `WP_SECRETS_KEY` was required, the Secrets API encrypted its root key under
`LOGGED_IN_KEY . LOGGED_IN_SALT` on sites that did not define it. Neither the bundled copy nor
WordPress 7.2 can read those keys any more, so while the experiment is enabled it recovers them
on the next request:

- **`WP_SECRETS_KEY` is defined:** the root key is re-encrypted under it. Every stored secret is
  readable again, including those of other plugins.
- **`WP_SECRETS_KEY` is not defined:** this site's API keys are moved back to their options in
  plaintext, so they keep working. They are encrypted again once the key is added.

Turning the experiment off, deactivating or uninstalling the plugin recovers them the same way
before restoring plaintext keys. This needs the salts the keys were stored with: if they have
been changed since, the keys cannot be recovered and must be entered again.

## How it works

While enabled, the experiment registers two transparent option filters per connector:

- `pre_update_option_{setting_name}` — stores the value with `wp_set_secret()` and forces the
  `wp_options` row to remain empty. If the secret cannot be stored, the value is written in
  plaintext instead, so the key is never lost.
- `option_{setting_name}` — returns the secret from `wp_get_secret()` on read; passes through to
  the stored value if no secret exists (handles partially-migrated state).

The Secrets API checks no capabilities: these filters run in cron, front-end and REST requests.
The `ai/` namespace only avoids name collisions with other plugins; it is not an isolation
boundary. See [Threat model](#threat-model).

All existing callers — `Connector_Key_Index`, REST dispatch, the AI client registry — keep
working because `get_option()` transparently returns the decrypted value through the read
filter.

## Opt-in / opt-out lifecycle

Migration is driven by this experiment's own toggle (`wpai_feature_key-encryption_enabled`) and
nothing else. Turning it on encrypts existing plaintext keys; turning it off is a transition out of
the enabled state and triggers the reverse migration. This matters because when the experiment is
off the transparent read filter is never installed — without the reverse migration, the user would
be locked out of their own keys.

## Disabling the experiment

Toggle the experiment off from the Experiments settings page. The reverse migration runs as soon
as the toggle flips off.

Avoid using the `wpai_feature_key-encryption_enabled` filter to force-disable this experiment: the
filter only short-circuits `is_enabled()`, so the transparent read filter is never installed —
but no toggle changes, so the reverse migration is never triggered either, and the user is locked
out of encrypted keys. The same applies to the code-level `wpai_features_enabled` filter: returning
false from it stops every feature from loading (including this read filter) without triggering the
reverse migration. Always change the stored toggle instead.
