# Agent Users

Agent Users gives external software—AI agents, MCP clients, scheduled jobs, and similar tools—a dedicated WordPress identity. The goal is to make its work attributable and independently revocable instead of sharing a human account.

This experiment implements the identity model proposed in [WordPress/ai#923](https://github.com/WordPress/ai/issues/923). Audit trails and richer provenance can build on that identity separately.

## Design

An agent is a regular WordPress user marked with `wpai_agent` user meta. Reusing `WP_User` preserves the behavior the ecosystem already expects: roles and capabilities, content authorship, revisions, comments, deletion with content reassignment, and user-based logs.

Every agent is the child of a human **parent** account it acts on behalf of, stored in `wpai_agent_parent` user meta. The agent keeps its own identity, so its work is attributed to the agent, and the link lets that work be shown as "Agent on behalf of Parent". The provisioner is recorded separately in `wpai_agent_created_by`, because an administrator may create an agent for another user.

The link is user meta rather than a new `wp_users` column: a plugin should not alter core's schema, and user meta is network-wide on multisite, exactly like the agent marker. A column only makes sense if the model is proposed for core.

The marker changes the account's security contract:

- **Authentication is non-interactive.** Password login, password resets, and password changes are blocked. Provisioning sets an unknown random password, and updates through the profile, REST, WP-CLI, `wp_update_user()`, or `wp_set_password()` keep it, so nobody holds a password that would work while the safeguards are inactive. Credentials are issued and revoked through core's Application Password flows under its normal permission checks. Other authentication mechanisms may be used if they resolve the request to the agent, because the identity restrictions are applied to the resulting WordPress user rather than to one credential format.
- **The parent is the ceiling.** Every capability check for an agent also checks its parent for the same capability with the same arguments, such as the post being edited, after every other mapping has run. An agent's authority is therefore both its own role and its parent's *current* permissions. Demoting the parent narrows their agents immediately, and object-specific rules that deny the parent also bound the agent.
- **Agent and parent share post ownership.** The agent acts for its parent, so each may edit, delete, and read the other's posts under the rules for their own posts, without `edit_others_posts`. Own-post limits still apply, for example a Contributor still cannot edit published posts. Agents sharing a parent are not linked to each other.
- **Parent eligibility is a capability.** `wpai_add_agents` means "may have agents". Agents are still created by user managers; self-service creation by parents, if added, is meant to be gated by this same capability. Users without an explicit grant or denial receive it when they can `edit_posts`, and site owners restrict it per role or per user with any role editor. Agents never receive it, and they cannot provision agents for anyone else either. On multisite, a super admin can be a parent without being a member of the site.
- **Agents without an eligible parent are suspended.** When the parent is missing, or loses `wpai_add_agents`, the agent keeps its account, content, and credentials but is denied every capability, including the self-management core otherwise allows without any, and its Application Passwords stop authenticating. An administrator restores the parent's eligibility or deletes the agent. A role change never deletes accounts, because that would delete content on a reversible administrative action.
- **Parents manage their own agents.** A parent can edit their agent's profile and issue or revoke its Application Passwords without `edit_users`. Changing the agent's role or deleting it still requires core's permissions.
- **The links are protected.** `wpai_agent` and `wpai_agent_parent` cannot be written through capability-checked meta APIs such as REST, so an agent cannot be re-parented or turned into a human account that way.
- **The role defines authority within that ceiling.** Provisioning requires `create_users`, `promote_users`, and the primitive `edit_users` capability needed to manage the resulting agent. The selected role cannot exceed the provisioner's or the parent's effective capabilities, and the provisioner comparison is repeated against the real marked account after creation so user-specific capability filters cannot widen it. Once assigned, an agent receives the same capabilities WordPress grants a human with that role, within its parent's.
- **Agents cannot manage the site.** Agents are not administrators, so they cannot have a role with `manage_options`, such as Administrator. Provisioning and REST reject them. WP-CLI and the bulk role change on the Users screen write roles directly, so an agent given such a role anyway is suspended on that site until it gets another role. The capability, rather than the role name, decides, so custom administrative roles are covered as well.
- **Agents cannot change other users.** Whatever their role, agents cannot edit, promote, remove, or delete any account but their own, including their parent's, or assign a role to a user they create; on multisite they also cannot add users to sites or mark them as spam from the network screens. They can still edit their own profile and manage their own Application Passwords, but not change their own role.
- **Agents cannot write unfiltered HTML.** Some roles agents may hold carry `unfiltered_html`, most notably Editor on single-site installations. Model output stored with that capability becomes stored XSS, so agent content always passes through core's normal KSES filtering.
- **Agents remain visible as users.** Hiding a principal from ordinary user queries would break ownership and capability-dependent code. Normal queries continue to return them.

## Administration

- **Every agent username ends with `_agent`.** Provisioning appends the suffix when it is missing, including for programmatic callers. The convention makes agents recognizable wherever only the login is shown, such as WP-CLI output, author names, and logs. Accounts created outside this flow carry no such guarantee.

## Multisite

WordPress stores user identity and Application Passwords across the network, while memberships and roles are site-specific. Agent accounts follow that core model: one agent may be a member of multiple sites, and its role on each site, bounded by its parent's capabilities on that site, defines what it can do there. Suspension is evaluated per site: an agent is suspended on every site where its parent is not a member, unless the parent is a super admin, or cannot have agents. Its credentials do not authenticate on those sites, so they never see the agent as a logged-in user. The same credential identifies the network user on every other site, but it does not grant site membership or capabilities, so a site where the parent can have agents but the agent is not a member sees an authenticated user without capabilities there.

Agents are provisioned from a site so their initial role has site context. Adding an existing agent to another site of its parent, removing it, changing its role, and deciding who may manage it all use core's normal multisite permission and invitation flows. Removing an agent from one site removes its authority there without changing its memberships or roles elsewhere. Core only lets accounts with the network-level `manage_network_users` capability edit other users on multisite, and the same rule decides who can provision agents.

**Needs discussion:** parents are a deliberate exception to that rule. They can edit their own agents' profiles and credentials on multisite without `manage_network_users`, so that per-agent credentials stay in the parent's hands on every install. This differs from the core permission model and is flagged in code for a decision with maintainers.

Agent provisioning is available on multisite only when the plugin is network-activated. The agent marker is network-wide, so per-site activation cannot guarantee that every site blocks interactive login and password resets for the same account. Direct provisioning fails until a network administrator activates the plugin across the network. Existing-agent management remains available so credentials can still be listed and revoked, but no new ones can be created: an Application Password authenticates the agent on every site, including sites where the plugin is not active to bound it.

## Enablement and retirement

Security rules for agents register whenever the plugin is active, before optional AI requirements and feature toggles are evaluated.

To retire an agent, revoke its Application Passwords or delete the account and choose how to reassign its content.

Accounts marked as agents outside the provisioning flow have no parent and are suspended until they are recreated or deleted.

WP-CLI is intentionally outside these runtime restrictions. An operator using `wp --user=<agent>` already has shell and database authority.

## Developer reference

The global helpers are part of the experiment, so their names may change, or they may be removed, while it settles. They only exist while the plugin is active.

```php
if ( function_exists( 'wpai_is_agent_user' ) && wpai_is_agent_user( $user_id ) ) {
	// Apply agent-specific presentation or behavior.
	$parent = wpai_get_agent_parent( $user_id ); // WP_User|null.
}
```

Stored metadata:

- `wpai_agent` (`Agent_Account::META_KEY`) marks the account.
- `wpai_agent_parent` (`Agent_Account::META_PARENT`) links the agent to its parent.
- `wpai_agent_created_by` (`Agent_Account::META_CREATED_BY`) records the provisioner.

`Agent_Account::PARENT_CAP` (`wpai_add_agents`) is the parent eligibility capability, `Agent_Account::is_suspended()` reports whether an agent currently has an eligible parent on the current site, and `Agent_Account::get_suspension_reason()` explains why it does not.

`Agent_Provisioner::LOGIN_SUFFIX` holds the username suffix, and `Agent_Provisioner::apply_login_suffix()` appends it to a sanitized login when missing.

Application Passwords require HTTPS or a `local` environment type. The experiment does not override that global core requirement.

The experiment deliberately omits custom extension hooks until the identity contract is validated. It also does not cover assistants acting inside a logged-in human session, credential protocols such as OAuth, trust tiers, approval workflows, or per-run audit correlation.

## Open questions

- **Restrictions below site management.** Apart from the `unfiltered_html` rule, the exclusion of roles with `manage_options`, and changing other users, agents keep role parity within their parent's permissions: an agent with a custom role that can install plugins can, for example, do so when its parent can. The parent restricts it through the agent's role or their own. Whether agents should always be denied other capabilities is left to maintainers.
- **Parent-controlled narrowing.** The role limits an agent within its parent's permissions, but only user managers can change it. Letting parents narrow their own agents, and create them, belongs with self-service provisioning gated by `wpai_add_agents`.
- **Multisite parent management.** See the exception above.

## Known limitations

- Every capability check for an agent also runs the same check for its parent. The cost has not been measured on large REST collections; cache the parent object, never the authorization results, if it matters.
