# Agent Users

Agent Users gives external software—AI agents, MCP clients, scheduled jobs, and similar tools—a dedicated WordPress identity. The goal is to make its work attributable and independently revocable instead of sharing a human account.

This experiment implements the identity model proposed in [WordPress/ai#923](https://github.com/WordPress/ai/issues/923). Audit trails and richer provenance can build on that identity separately.

## Design

An agent is a regular WordPress user marked with `wpai_agent` user meta. Reusing `WP_User` preserves the behavior the ecosystem already expects: roles and capabilities, content authorship, revisions, comments, deletion with content reassignment, and user-based logs.

The agent acts as its own principal, not on behalf of the person who created it. The creator is recorded as provisioning provenance only; sharing the agent with other people does not change how its work is attributed.

The marker changes the account's security contract:

- **Authentication is non-interactive.** Password login and password resets are blocked. Credentials are issued and revoked through core's Application Password flows under its normal permission checks. Other authentication mechanisms may be used if they resolve the request to the agent, because the identity restrictions are applied to the resulting WordPress user rather than to one credential format.
- **The role defines authority.** Provisioning requires `create_users`, `promote_users`, and the primitive `edit_users` capability needed to manage the resulting agent. The selected role cannot exceed the provisioner's effective capabilities, and that comparison is repeated against the real marked account after creation so user-specific capability filters cannot widen it. Once assigned, an agent receives the same capabilities WordPress grants a human with that role. An Administrator agent is therefore fully trusted and carries the same operational risk as any other Administrator account; lower roles remain limited by WordPress's normal capability mapping.
- **Agents without administrative access cannot write unfiltered HTML.** Some roles below Administrator carry `unfiltered_html`, most notably Editor on single-site installations. Model output stored with that capability becomes stored XSS, so agents without `manage_options` pass through core's normal KSES filtering. Capability checks, rather than role names, keep custom roles aligned with core behavior.
- **Agents remain visible as users.** Hiding a principal from ordinary user queries would break ownership and capability-dependent code. The admin UI marks agents and offers an explicit filter, while normal queries continue to return them.

## Administration

Agent management stays on core user screens because the underlying resource is a user:

- **Users → Add Agent** reuses the Add User form, keeping core's identity fields, role controls, validation, and accessibility behavior. Password and human notification controls are omitted because nobody logs in as the account. After creation, the administrator is redirected to the agent profile to create the first Application Password.
- **Every agent username ends with `_agent`.** Provisioning appends the suffix when it is missing, including for programmatic callers. The convention makes agents recognizable wherever only the login is shown, such as WP-CLI output, author names, and logs. Accounts created outside this flow carry no such guarantee.
- **The profile** remains the canonical place for administrators to change the role, edit identity data, and issue or revoke Application Passwords. Human-only login and admin-interface preferences are hidden.
- **The Users list** labels roles such as `Editor (agent)`, provides account-type filtering, and replaces the password-reset action with credential management.
- **REST user responses** expose the read-only `wpai_is_agent` field so clients can distinguish agent identities.

## Multisite

WordPress stores user identity and Application Passwords across the network, while memberships and roles are site-specific. Agent accounts follow that core model: one agent may be a member of multiple sites, and its role on each site defines what it can do there. The same credential identifies the network user on every site, but it does not grant site membership or capabilities. It still authenticates the agent as a logged-in user everywhere, like any WordPress credential, so a site the agent does not belong to sees an authenticated user without capabilities there.

Agents are provisioned from a site so their initial role has site context. Adding an existing agent to another site, removing it, changing its role, and deciding who may manage it all use core's normal multisite permission and invitation flows. Removing an agent from one site removes its authority there without changing its memberships or roles elsewhere. Core only lets accounts with the network-level `manage_network_users` capability edit other users on multisite. The same rule decides who can provision agents and manage their credentials.

The only agent-specific multisite restriction is that agents cannot become super admins. Super admin is a network-wide status outside the site role system and bypasses most capability checks, so it is incompatible with role-defined agent authority.

Agent provisioning is available on multisite only when the plugin is network-activated. The agent marker is network-wide, so per-site activation cannot guarantee that every site blocks interactive login and password resets for the same account. The Add Agent UI stays unavailable and direct provisioning fails until a network administrator activates the plugin across the network. Existing-agent management remains available so credentials can still be revoked.

## Enablement and retirement

Provisioning and admin UI are loaded only when the environment supports WordPress AI and both the global AI features setting and the Agent Users experiment are enabled. The two feature settings are off by default.

Security rules for existing agents are different: they register whenever the plugin is active, before optional AI requirements and feature toggles are evaluated. Disabling the experiment hides provisioning and management enhancements but does not turn existing agents back into ordinary interactive accounts. Their login, password-reset, and `unfiltered_html` restrictions remain in force.

To retire an agent, revoke its Application Passwords or delete the account and choose how to reassign its content. Disabling the experiment does not revoke credentials or delete accounts.

WP-CLI is intentionally outside these runtime restrictions. An operator using `wp --user=<agent>` already has shell and database authority.

## Developer reference

```php
if ( wpai_is_agent_user( $user_id ) ) {
	// Apply agent-specific presentation or behavior.
}
```

Stored metadata:

- `wpai_agent` (`Agent_Account::META_KEY`) marks the account.
- `wpai_agent_created_by` (`Agent_Account::META_CREATED_BY`) records the provisioner.

`Agent_Account::LOGIN_SUFFIX` holds the username suffix, and `Agent_Account::apply_login_suffix()` appends it to a sanitized login when missing.

Application Passwords require HTTPS or a `local` environment type. The experiment does not override that global core requirement.

The experiment deliberately omits custom extension hooks until the identity contract is validated. It also does not cover assistants acting inside a logged-in human session, credential protocols such as OAuth, trust tiers, approval workflows, or per-run audit correlation.

Disable the experiment in code:

```php
add_filter( 'wpai_feature_agent-users_enabled', '__return_false' );
```

## Integrator guidance

Agent users change the answer to a question a lot of plugin code asks without noticing: given this site, which user should my code act as? Until now every account that could be resolved that way belonged to a person. Some of them no longer do, and the code that resolves one is usually old, several layers down, and written by someone who is no longer looking at it.

The rule that keeps the rest of this short: an agent is hidden from presentation, never from queries. Ownership, capability, revision, and comment code must keep seeing agents or it will draw the wrong conclusions about content they own. Author dropdowns and participant lists are where they should be filtered out.

### Choosing a user to act as

The two common shapes both resolve by authority, and authority is what an agent has:

```php
// By role.
get_users( array( 'role' => 'administrator', 'number' => 1, 'orderby' => 'ID', 'order' => 'ASC' ) );

// By capability: the usual repair when the role query proves unreliable.
foreach ( get_users( array( 'number' => 50, 'orderby' => 'ID' ) ) as $user ) {
	if ( user_can( $user, 'manage_options' ) ) {
		return $user->ID;
	}
}
```

Either can return an Administrator agent. The second one matters more, because moving a resolver from roles to capabilities is the standard advice for making it reliable, and it does not help here. An Administrator agent holds `manage_options` exactly as a human Administrator does, which is the identity model working as designed rather than a gap in it.

Both shapes also tend to be reached for by ID order, and ID order is not a proxy for "the site owner". An agent provisioned before the humans, on a site set up agent-first by a host or by WP-CLI, sorts first. So does an account created by an attacker who backdated it, which is a pattern security scanners already look for; agent users add a legitimate account with the same sorting behaviour.

Where the resolved user will own content or stand in for a person, exclude agents:

```php
get_users( array(
	'role'       => 'administrator',
	'number'     => 1,
	'orderby'    => 'ID',
	'order'      => 'ASC',
	'meta_query' => array(
		array(
			'key'     => 'wpai_agent',
			'compare' => 'NOT EXISTS',
		),
	),
) );
```

For a user already in hand, `wpai_is_agent_user()` is the supported check. Reading the `wpai_agent` meta directly works and will keep working, but the helper is what survives a change in how the marker is stored.

Two properties shape what a fallback chain can safely do. Agents cannot be super admins, so a resolver that falls back to `get_super_admins()` cannot reach one that way. And `get_users( array( 'role' => 'administrator' ) )` is scoped to a site's own member list, so on multisite it already misses a network administrator who runs a subsite without being added to it: that resolver was returning empty on some installs before agent users existed, and the repair for it is usually the capability scan above, which reintroduces the agent case.

### Attribution and display

`post_author` can now point at an agent. Code that treats an author as a person will ask an agent for a biography, an avatar, an email to notify, or an "is this author still with us" answer, and get something plausible and wrong.

Three places this usually surfaces:

- Author archives and bylines, where an agent's display name reaches a visitor. Whether that is correct is an editorial decision for the site, not a default a plugin should make on its behalf.
- Notification and digest code that mails the author. An agent account has an address and no reader.
- "Written by a human" or "needs a human review" logic, which will loop or answer incorrectly unless it consults `wpai_is_agent_user()`.

Attributing work to an agent is the point of the feature. The adjustment is not to hide the attribution, it is to stop inferring a person from it.

### Content written by an agent

Agents without `manage_options` do not hold `unfiltered_html`, so their content passes through KSES like any other filtered write. Code that renders agent-authored content should expect it to have been filtered, and code that stores it should not assume markup survives verbatim.

This matters most for anything that writes structured markup: page-builder payloads, embeds, and scoped styles are the shapes KSES alters or drops. An agent that produced valid builder output on a site where it held `unfiltered_html` can produce broken output on a site where it does not, with no error at the point of writing. The safe set of tags is a property of what a particular site has installed, so it can only be decided by the site.

### What not to change

Some defensive instincts make this worse:

- Do not filter agents out of `get_users()` globally, through `pre_get_users` or otherwise. Ownership and capability code depends on seeing them, and a hidden principal produces failures that are much harder to trace than a visible one.
- Do not key behaviour on the `_agent` username suffix. It is a readability convention applied at provisioning, not a guarantee: accounts created outside that flow do not carry it, and a login is not an identity contract.
- Do not treat "is an agent" as "is untrusted". The role decides authority. An Administrator agent is fully trusted and carries the operational risk of any Administrator account.
