# Agent Users

Agent Users gives external software—AI agents, MCP clients, scheduled jobs, and similar tools—a dedicated WordPress identity. The goal is to make its work attributable and independently revocable instead of sharing a human account.

This experiment implements the identity model proposed in [WordPress/ai#923](https://github.com/WordPress/ai/issues/923). Audit trails and richer provenance can build on that identity separately.

## Design

An agent is a regular WordPress user marked with `wpai_agent` user meta. Reusing `WP_User` preserves the behavior the ecosystem already expects: roles and capabilities, content authorship, revisions, comments, deletion with content reassignment, and user-based logs.

The marker changes the account's security contract:

- **Authentication is non-interactive.** Password login and password resets are blocked. Administrators issue and revoke credentials through core's Application Passwords UI. Other authentication mechanisms may be used if they resolve the request to the agent, because the restrictions are applied to the resulting WordPress user rather than to one credential format.
- **The role is a ceiling, not an exemption.** Provisioning requires both `create_users` and `promote_users`, and the selected role cannot exceed the provisioner's effective capabilities.
- **Unsafe capabilities are always denied.** Agents cannot use `unfiltered_html` or create, edit, promote, delete, or remove users, regardless of role. `unfiltered_html` is excluded because model-generated markup would otherwise create a stored-XSS path; user management is excluded to prevent account creation and privilege escalation. The same list is enforced through `user_has_cap` and `map_meta_cap`, including checks that bypass ordinary role capabilities.
- **Agents remain visible as users.** Hiding a principal from ordinary user queries would break ownership and capability-dependent code. The admin UI marks agents and offers an explicit filter, while normal queries continue to return them.

## Administration

Agent management stays on core user screens because the underlying resource is a user:

- **Users → Add Agent** reuses the Add User form, keeping core's identity fields, role controls, validation, and accessibility behavior. Password and human notification controls are omitted because nobody logs in as the account. After creation, the administrator is redirected to the agent profile to create the first Application Password.
- **The profile** remains the canonical place to change the role, edit identity data, and issue or revoke Application Passwords. Human-only login and admin-interface preferences are hidden.
- **The Users list** labels roles such as `Editor (agent)`, provides account-type filtering, and replaces the password-reset action with credential management.
- **REST user responses** expose the read-only `wpai_is_agent` field so clients can distinguish agent identities.

## Multisite

WordPress stores user identity and Application Passwords across the network, while roles and memberships are site-specific. Membership alone is therefore too broad: a credential would otherwise follow the same user to every site where it has a role.

Each multisite agent records one assigned site in `wpai_agent_site_id`. It may act only when both conditions are true:

1. The current site is the recorded site.
2. The agent is still a member of that site.

The site ID is the security boundary; membership is the local enable/disable switch. Removing membership disables the agent without changing its assignment, and re-adding it to the assigned site restores it. A role granted on any other site does not widen the boundary. Missing or invalid assignment metadata fails closed.

This model has several deliberate consequences:

- Agents are created from the site they will serve, never from Network Admin. Serving another site requires a separate agent with its own credentials and attribution.
- Authentication is checked after core resolves a user across REST, XML-RPC, and other authentication paths. Matched Application Passwords are rejected before a cross-site use can be recorded as successful.
- `add_user_to_blog()` rejects an agent on any site except its assignment, including core's Add Existing User flow.
- Agents cannot become super admins because that status bypasses most capability checks.
- A site's administrators may manage agents assigned to that site, including their Application Passwords. Core normally reserves editing another multisite user for network administrators; relaxing that requirement is safe here because the agent cannot authenticate or act outside the assigned site. The exception never applies to human accounts, foreign-site agents, or agents managing other users.
- Application Password management is unavailable from Network Admin and from sites other than the assignment.

Network-activate the plugin when using Agent Users on multisite. Per-site activation cannot guarantee that every site enforces the authentication boundary.

## Enablement and retirement

Provisioning and admin UI are loaded only when the environment supports WordPress AI and both the global AI features setting and the Agent Users experiment are enabled. The two feature settings are off by default.

Security rules for existing agents are different: they register whenever the plugin is active, before optional AI requirements and feature toggles are evaluated. Disabling the experiment hides provisioning and management enhancements but does not turn existing agents back into unrestricted human accounts. Their login, capability, and multisite restrictions remain in force.

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
- `wpai_agent_site_id` (`Agent_Account::META_SITE_ID`) records the multisite assignment.

Application Passwords require HTTPS or a `local` environment type. The experiment does not override that global core requirement.

The experiment deliberately omits custom extension hooks until the identity contract is validated. It also does not cover assistants acting inside a logged-in human session, credential protocols such as OAuth, trust tiers, approval workflows, or per-run audit correlation.

Disable the experiment in code:

```php
add_filter( 'wpai_feature_agent-users_enabled', '__return_false' );
```
