# Agent Users

Gives external AI agents their own auditable identity as agent accounts. This is the identity slice of [WordPress/ai#923](https://github.com/WordPress/ai/issues/923). Attribution and audit surfaces come in a follow-up once this approach is validated.

## The problem

Today an external agent (an MCP client, a coding agent, a scheduled job) can only borrow a human user's credentials. There is no way to see what an agent changed, or to revoke its access without touching a human account. Running with no user at all is not a smaller version of a user either: content loses its author and capability-dependent filters behave in surprising ways.

## What an agent account is

A regular user account with a marker in user meta. Reusing the user primitive means the whole ecosystem gets agent attribution for free: `post_author`, revisions, comments, and logs all keep working. Compared to a human account:

- **No interactive login.** The password form rejects agent accounts. They authenticate with Application Passwords, or any other mechanism that resolves the request to the account. Password resets are disabled. After provisioning, the administrator creates an Application Password through WordPress core's REST flow, which reveals the plaintext credential only in the creation response.
- **Roles stay the capability ceiling.** An "Editor agent" means what you expect. There are no agent-only roles. Provisioners need both `create_users` and `promote_users`, and may only assign roles whose effective capabilities do not exceed their own.
- **A few capabilities are always blocked**, no matter the role, because their defaults are written for humans:
  - `unfiltered_html` — model output combined with it means stored XSS.
  - `create_users`, `edit_users`, `promote_users`, `delete_users`, `remove_users` — an agent must not mint accounts or escalate through an existing one.

  The list is fixed while the experiment gathers feedback. The block is enforced on both `user_has_cap` and `map_meta_cap` from one shared list, so the two layers cannot drift apart. The `map_meta_cap` layer maps to `do_not_allow`, which also covers multisite super admins. Do not make an agent a super admin.
- **Fully visible in user queries.** A lot of code enumerates users to make decisions, and an invisible principal breaks it. Agents appear in `get_users()`, counts, and listings like any account. Hiding is a display concern for picker UIs, addressed separately.

## Enabling and disabling

The experiment uses the standard enablement layers, nothing extra. Agent accounts work only when all three allow it:

1. WordPress core reports AI support via `wp_supports_ai()`. When the environment disables AI, the plugin does not load at all, so none of this code runs.
2. The plugin's AI features toggle is on.
3. This experiment is turned on.

The plugin-level toggles are off by default, so no provisioning UI surfaces until a site owner opts in deliberately. Turning the experiment off hides the whole agent UI again: the Add Agent entry points, the agent adjustments on the profile screen, the agent marker in the Role column, and the account type filter.

Agent safeguards are not controlled by the experiment toggle. Existing agent accounts retain their blocked interactive login, disabled password resets, and capability restrictions for as long as the plugin is active, even if the experiment or the global AI features toggle is later disabled. Their credentials also remain valid; to retire an agent, delete its account or revoke its Application Passwords on the profile screen.

WP-CLI is not gated either way. `wp --user=<agent>` is an operator with shell access, which no site option can meaningfully restrict.

## What ships in this experiment

There is no separate agent screen. An agent is a user, so it is created and managed where users are created and managed:

- **Users → Add Agent** (also a header action on the Users screen) opens the Add User screen in agent mode. It is the same form, so both flows share one implementation. Agent mode keeps the username, email, names, website, and role fields, and hides the password and the notification checkbox. The email receives notifications about the agent's activity. The display name is derived from the names exactly as for any user. Submitting creates the agent and redirects to its profile. Requires `create_users` and `promote_users`; assignable roles cannot exceed the provisioner's capabilities.
- **The agent's profile screen** is the management surface. A note under the title marks the account type. The screen hides what only makes sense for a person (password and sessions, admin UI preferences) and hosts core's Application Passwords UI. Everything else stays: names, contact info, biographical info, profile picture. Right after creation a notice points to that section, where the first credential is created through the same REST-backed one-time reveal flow core uses for regular users.
- **The Users screen** shows agents as "Editor (agent)" in the Role column, gets a filter (all users, agents only, exclude agents) in the table actions, and agent row actions: "Send password reset" is replaced by a shortcut to the agent's Application Passwords.
- A read-only `wpai_is_agent` field on REST user responses, so clients can render badges or filter their own pickers.
- Everything else is core behavior: change the role on the profile, revoke Application Passwords there, delete the agent (with content reassignment) on the Users screen.

## Out of scope, by design

- **Assistants in a logged-in user's session.** They run as that user, exactly as today. Every execution has one principal, so there is no capability intersection between an agent and a user.
- **Credentials** (expiry, OAuth, scoping) — the identity is authentication-agnostic.
- **Audit trails and attribution surfaces** (`wp_ability_invoked` logging, provenance, per-run correlation ids, author picker exclusion) — the follow-up slice.
- **Trust levels, autonomy tiers, approval workflows.**

## Multisite

User meta is shared across the network, so being an agent is a network-wide fact. Per-site agency stays what it is for humans: the role granted on each site. The provisioning UI operates on the current site only.

## For developers

Check whether an account is an agent:

```php
use WordPress\AI\Experiments\Agent_Users\Agent_Account;

if ( Agent_Account::is_agent( $user_id ) ) {
	// ...
}
```

The marker lives in user meta under `wpai_agent` (`Agent_Account::META_KEY`). `wpai_agent_created_by` records who provisioned the account.

The experiment deliberately ships without custom hooks while the approach gathers feedback. If the prototype validates, extension points (adjusting the blocked capability list, reacting to provisioning) come with the proper API design.

Notes:

- Application Passwords require HTTPS (or a `local` environment type). On a plain-HTTP site core does not offer credential creation and will not accept Application Password authentication.
- Agent accounts are not removed on uninstall. They own content, so removing them is a deliberate decision for the site owner.

## Disable the experiment

```php
add_filter( 'wpai_feature_agent-users_enabled', '__return_false' );
```
