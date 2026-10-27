# Site Agent

## Summary

The Site Agent adds a conversational assistant at **Tools → Site Agent**. It answers questions about the site and proposes changes. Actions are ordinary WordPress Abilities, so each one keeps its own input schema, permission callback, validation, and execution hook. The agent adds only a reviewed allowlist, a propose-then-approve boundary for anything that is not read-only, and an audit trail.

The experiment and its write gate are both disabled by default.

## How it works

1. The model receives only the abilities in the reviewed catalog.
2. An ability annotated `readonly => true` runs immediately.
3. Any other ability is a write. The exact ability name and arguments are stored as a proposal; the model never runs it.
4. The transcript shows the exact stored values and waits.
5. Approval re-checks the ability's own permission callback and calls `$ability->execute( $stored_args )` as the current user. WordPress validates, sanitizes, and checks permissions again.
6. The proposal is marked used, a result note is appended to the conversation, and an audit row is written.

Declining deletes the proposal and appends a note. Nothing runs.

## Abilities

| Ability | Type | Capability | What it does |
| --- | --- | --- | --- |
| `ai/site-agent-read-site` | Read | Section-dependent | Reads the environment, recent posts or pages, installed plugins, or the fixed settings allowlist. |
| `ai/site-agent-create-draft` | Write | `edit_posts` / `edit_pages` | Creates a draft post or page owned by the current user. Never publishes. |
| `ai/site-agent-update-post` | Write | `edit_post` | Updates title, content, excerpt, or draft/pending status. Never publishes and cannot move a published item back to draft. |
| `ai/site-agent-update-setting` | Write | `manage_options` | Updates the site title or tagline only. |

Read sections and their capabilities:

| Section | Capability | Notes |
| --- | --- | --- |
| `environment` | `manage_options` | WordPress, PHP, and database versions, URLs, multisite, active theme, active plugins, offered core/plugin/theme updates. |
| `posts` | `edit_posts` | Up to 20 recent items, filtered by `read_post`. A single item by ID includes its content. |
| `pages` | `edit_pages` | Same as posts. |
| `plugins` | `activate_plugins` | Up to 50 installed plugins with version, active state, and offered update. |
| `settings` | `manage_options` | `blogname`, `blogdescription`, `timezone_string`, `date_format`, `time_format` only. |

## Adding an action

Write a normal ability with a schema, a permission callback, and annotations. Then add its name to `Catalog::READ_ABILITIES` or `Catalog::WRITE_ABILITIES`, or add it with the `wpai_site_agent_tools` filter. An ability that does not declare `readonly => true` is treated as a write and requires approval.

Do not register a general-purpose dispatcher that takes an arbitrary route, URL, option name, or callback. The catalog is only as safe as the abilities in it.

## Gates

| Setting | Default | Controls |
| --- | --- | --- |
| Experiment toggle | `false` | Whether the screen, routes, and abilities load at all |
| `wpai_feature_site-agent_field_allow_writes` | `false` | Whether the write abilities are in the catalog and whether their permission callbacks allow execution |

A gate only decides whether a surface may be proposed. Every ability still runs its own capability check at execution time.

## Security model

- **Allowlist, not discovery.** The model cannot name an ability that is not in the catalog.
- **Abilities are the action layer.** WordPress validates the input, checks the permission callback, and fires the execution hook.
- **Current-user authority.** Everything runs as the signed-in user. The model cannot choose another user.
- **Approval is separate.** The browser sends only the proposal ID and conversation ID. The server executes the stored ability name and arguments, not a browser-supplied request.
- **Server-side transcript.** The browser cannot supply the history the model sees.
- **Storage is bounded.** Proposals are user-scoped, conversation-bound, single-use, and expire after 30 minutes. Conversations expire after two hours.
- **No arbitrary code.** There is no code-execution route, file editor, SQL runner, or shell.
- **No publishing.** Write abilities can create drafts and move items between draft and pending. They cannot publish, change authors, upload media, or delete content.

## Audit

Every executed ability and every proposal decision writes an AI request log row with type `site_agent`. The operation is the ability name. The context carries the phase (`execute`, `proposal`, `approval`, or `decline`), the proposal ID, the conversation ID, and an error code when there is one. It never contains full content bodies, setting values, or credentials.

External audit plugins can listen to `wpai_site_agent_action`.

## Filters

| Filter | Default | Purpose |
| --- | --- | --- |
| `wpai_feature_site-agent_enabled` | `false` | Per-experiment enable/disable |
| `wpai_site_agent_capability` | `manage_options` | Screen and REST capability |
| `wpai_site_agent_tools` | built-in catalog | Add or remove reviewed ability names |
| `wpai_site_agent_max_rounds` | `4` | Maximum model rounds per turn |
| `wpai_site_agent_system_instruction` | built-in | Prompt customization |
| `wpai_site_agent_allow_writes` | `false` | Write gate |
| `wpai_site_agent_model_preference` | preferred text models | Model fallback list |

## Limitations

- Write abilities create drafts and edit drafts. Publishing, trash, media uploads, user writes, plugin installation, core/plugin/theme updates, theme switching, and export are not exposed. Each can be added as a reviewed ability later.
- The agent needs a model that supports function calling.
- Conversations are not listed in a history browser. A reload starts a new topic.

## Testing

### Manual testing

1. Open **Settings → AI**. Open the settings menu and turn on **Advanced settings** so feature configuration options are visible.
2. Enable **Site Agent**. Leave **Allow write proposals** off.
3. Open **Tools → Site Agent**.
4. Ask: “What version of WordPress and PHP am I running?” Confirm the assistant calls `ai/site-agent-read-site` with `section=environment` and returns real values.
5. Ask: “Show my latest draft posts.” Confirm `section=posts` and that the list contains only items you can read.
6. Ask: “Create a draft titled ‘Site Agent test’.” Confirm the assistant says it cannot, because write proposals are off.
7. Return to **Settings → AI** and enable **Allow write proposals** under Site Agent. Reload the Site Agent.
8. Ask: “Create a draft titled ‘Site Agent test’ with a short summary.” Confirm a proposal card shows the exact ability, title, content, type, and status. Confirm no post exists yet.
9. Click **Decline**. Confirm nothing was created and the transcript says nothing changed.
10. Ask again, then click **Approve**. Confirm the draft exists under **Posts → Drafts**, and the transcript shows the result.
11. Ask: “Change the site tagline to ‘Built with WordPress’.” Confirm the proposal shows the exact setting and value. Approve it, then verify under **Settings → General**.
12. Ask for a publish, a trash, a media upload, or a plugin install. Confirm the assistant says it cannot.
13. Open **Tools → AI Request Logs** and filter by type `site_agent`. Confirm rows exist for the proposal, execution, approval, and decline, and that no full content or secret is present.

### Automated testing

```bash
npm run test:php
npm run test:e2e
```

The integration tests live in `tests/Integration/Includes/Abilities/Site_Agent/` and `tests/Integration/Includes/Experiments/Site_Agent/`.
