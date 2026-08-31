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

WordPress stores user identity and Application Passwords across the network, while memberships and roles are site-specific. Agent accounts follow that core model: one agent may be a member of multiple sites, and its role on each site defines what it can do there. The same credential identifies the network user on every site, but it does not grant site membership or capabilities.

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
use WordPress\AI\Experiments\Agent_Users\Agent_Account;

if ( Agent_Account::is_agent( $user_id ) ) {
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
