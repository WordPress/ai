# Key Encryption

Opt-in experiment that encrypts AI connector API keys at rest.

## Summary

- Extends `Abstract_Feature`.
- While enabled, every `connectors_ai_*_api_key` option is transparently routed through the
  [Displace Secrets Manager](https://github.com/ericmann/displace-secrets-manager) plugin's
  `set_secret()` / `get_secret()` API, so the `wp_options` table never contains a plaintext key.
- Existing keys are encrypted on opt-in, restored on opt-out, and restored on plugin deactivation
  — users cannot get locked out of their own credentials.

## Requirements

This experiment depends on the
[Displace Secrets Manager](https://github.com/ericmann/displace-secrets-manager) plugin being
installed and active. If the secrets manager plugin is missing, enabling this experiment has no
effect: writes pass through unchanged so user keys are never silently dropped.

For meaningful at-rest security, also define `WP_SECRETS_KEY` in `wp-config.php`. Without it,
Displace Secrets Manager derives an encryption key from existing WordPress salts
(`LOGGED_IN_KEY . LOGGED_IN_SALT`) — usable for a proof-of-concept, but weaker than a dedicated
key. Generate one with `wp secret generate-key`.

## How it works

While enabled, the experiment registers two transparent option filters per connector:

- `pre_update_option_{setting_name}` — encrypts the value via `set_secret()` and forces the
  `wp_options` row to remain empty.
- `option_{setting_name}` — decrypts and returns the secret on read; passes through to the
  stored value if no secret exists (handles partially-migrated state).

All existing callers — `Connector_Key_Index`, REST dispatch, the AI client registry — keep
working because `get_option()` transparently returns the decrypted value through the read
filter.
