# Access Controls

## Summary

Access Controls let administrators limit each supported AI feature to chosen roles and specific users. A restriction applies wherever the feature's abilities run, not just in the plugin's own screens. Nothing changes for anyone until an administrator turns access control on for a feature **and saves**.

## Overview

### For End Users

On the AI settings page (**Settings → AI**), open the **Developer Tools** menu (the wrench icon) and select **Access controls**. Each enabled feature that supports access control then shows:

- **Enable access control**: turns the restriction on or off for that feature.
- **Roles**: one checkbox per role that has the `edit_posts` capability. **Administrator** and **Editor** are checked by default.
- **Users**: search for and add individual users. Listed users can always use the feature, whatever their role.
- **Save**: appears once you change something. Nothing is stored until you click it.

When the **Access controls** view is turned off, a feature that is actually restricted shows "Access restricted to selected roles and users." so the restriction stays visible. Turning the view off never clears saved settings.

### What changes, and when

- **Access control off** (the default): access is identical to before. Anyone who passes the feature's normal permission checks can use it.
- **Turned on but not saved**: nothing is written, so access is still identical to before.
- **Turned on and saved**: only the checked roles and the listed users can use the feature. If you save without touching the defaults, Administrator and Editor are saved. That can be stricter than before. For example, Authors and Contributors lose access.
- **No roles checked and no users listed**: nobody can use the feature, including administrators.

On multisite, super admins can always use every feature.

Access control only ever adds a restriction. Each ability's own permission checks still apply, so adding a user here does not let them edit posts they otherwise could not.

### Where the restriction applies

The restriction applies to the feature's abilities on every path they run:

- the plugin's own buttons and screens in wp-admin and the editor (users without access do not get the feature's UI);
- the Abilities REST API (`/wp-abilities/v1/abilities/{name}/run`);
- MCP over HTTP;
- `runAbility()` in JavaScript, and server-side calls to `wp_get_ability()->execute()`;
- abilities one feature calls from another. For example, alt text generated during Image Generation is checked against the Alt Text Generation feature's settings;
- Text to Speech's own REST routes (`/ai/v1/text-to-speech/{id}`), which are not abilities but check the same capability.

A user without access gets a `wpai_feature_access_denied` error (HTTP 401 when logged out, 403 when logged in).

**WP-CLI is not restricted.** Commands run through WP-CLI bypass access control entirely. This includes MCP over STDIO, which runs through WP-CLI.

### Which features support it

Only features whose `access_control` metadata is `true` show these controls. By default that is every feature outside the **Admin** category, plus Comment Moderation and Suggest Reply. Other admin features already require `manage_options`.

### Settings export and import

Access control settings are **not** included in [settings export and import](settings-import-export.md). Role slugs and user IDs differ between sites, so configure access controls on each site directly.

## Stored options

Each supported feature stores three options, registered in their own option group (`ai_experiments_access`) and exposed through the core `/wp/v2/settings` endpoint:

| Option | Type | Default |
|---|---|---|
| `wpai_feature_<id>_access_control_enabled` | boolean | `false` |
| `wpai_feature_<id>_roles` | list of role slugs | unset, meaning no role restriction |
| `wpai_feature_<id>_users` | list of user IDs | `[]` |

An unset roles option is different from an empty list. Unset means "no restriction yet". A saved empty list means "no role has access".

## For Developers

### The `wpai_use_feature` capability

Access is decided by the `wpai_use_feature` meta capability, with the feature ID as its argument:

```php
if ( current_user_can( 'wpai_use_feature', 'title-generation' ) ) {
	// The current user may use Title Generation.
}
```

The plugin maps it to `exist` (allowed) or `do_not_allow` (denied) in a `map_meta_cap` filter at priority 10. The recommended way to extend the check is your own `map_meta_cap` filter at a later priority that adds a requirement to the plugin's result. The following example also requires `publish_posts` for Title Generation. The admin's role and user settings stay in force, so a user must pass both:

```php
add_filter(
	'map_meta_cap',
	static function ( array $caps, string $cap, int $user_id, array $args ): array {
		if ( 'wpai_use_feature' === $cap && 'title-generation' === ( $args[0] ?? '' ) ) {
			// Also require publish_posts, on top of the access control settings.
			$caps[] = 'publish_posts';
		}
		return $caps;
	},
	20,
	4
);
```

Run your filter after priority 10 so it sees the plugin's result. Append to `$caps` rather than replacing it: returning a new array discards the plugin's decision.

The `user_has_cap` filter also works, but it only sees the primitive `exist` or `do_not_allow` result, not the roles and users behind it. Core never grants `do_not_allow`, so `user_has_cap` is mainly useful for denying access.

### Linking abilities to features

Features register their abilities with `Abstract_Feature::register_feature_ability()`. It adds the feature ID to the ability's `wpai_feature` meta key and then calls `wp_register_ability()`. The plugin checks `wpai_use_feature` for that feature whenever an ability with this meta key runs.

Abilities registered directly with `wp_register_ability()` are not tied to any feature, so only their own `permission_callback` applies.

Feature UI that should be hidden from users without access belongs in `register_ui()`. See the [Developer Guide](../DEVELOPER_GUIDE.md).
