# Agent Users

Gives external AI agents their own auditable identity as agent accounts. This is the identity slice of [WordPress/ai#923](https://github.com/WordPress/ai/issues/923). Attribution and audit surfaces come in a follow-up once this approach is validated.

## The problem

Today an external agent (an MCP client, a coding agent, a scheduled job) can only borrow a human user's credentials. There is no way to see what an agent changed, or to revoke its access without touching a human account. Running with no user at all is not a smaller version of a user either: content loses its author and capability-dependent filters behave in surprising ways.

## What an agent account is

A regular user account with a marker in user meta. Reusing the user primitive means the whole ecosystem gets agent attribution for free: `post_author`, revisions, comments, and logs all keep working. Compared to a human account:

- **No interactive login.** The password form rejects agent accounts. They authenticate with Application Passwords, or any other mechanism that resolves the request to the account. Password resets are disabled. The first Application Password is created during provisioning and shown exactly once.
- **Roles stay the capability ceiling.** An "Editor agent" means what you expect. There are no agent-only roles.
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

The plugin-level toggles are off by default, so nothing surfaces and no agent can act until a site owner opts in deliberately. Turning the experiment off hides the whole agent UI again: the Users → AI Agents page, the Agent badge column, and the AI Agents view.

One caveat comes with reusing these toggles. Turning any of them off also removes the identity rules, but it does not revoke credentials: existing agent accounts keep working Application Passwords and behave as ordinary users of their role. To retire agents, delete their accounts or revoke their Application Passwords on the profile screen before (or after) disabling the experiment.

WP-CLI is not gated either way. `wp --user=<agent>` is an operator with shell access, which no site option can meaningfully restrict.

## What ships in this experiment

- Provisioning UI under **Users → AI Agents**: create an agent (name + role), see existing agents, one-time Application Password reveal. Requires `create_users`.
- An **Agent** badge column and an opt-in **AI Agents** view on the Users screen.
- A read-only `wpai_is_agent` field on REST user responses, so clients can render badges or filter their own pickers.
- Everything else reuses core screens: revoke Application Passwords on the agent's profile, delete the agent (with content reassignment) on the Users screen.

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

- Application Passwords require HTTPS (or a `local` environment type). On a plain-HTTP site the credential is created but core will not accept it for authentication.
- Generated placeholder emails use the reserved `agents.invalid` domain and can never route mail.
- Agent accounts are not removed on uninstall. They own content, so removing them is a deliberate decision for the site owner.

## Disable the experiment

```php
add_filter( 'wpai_feature_agent-users_enabled', '__return_false' );
```
