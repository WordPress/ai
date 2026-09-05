# AI Workspace

## Summary

The AI Workspace experiment adds a conversation surface on a dedicated admin screen at `Tools → AI Workspace`, where a site owner can ask questions about their own site. The assistant is given a small allowlist of WordPress Abilities as callable tools; every call runs through `WP_Ability::execute()`, so it can never reach content the requesting user could not read. It cannot write anything on its own: creating drafts is a two-step proposal that a person approves before the server writes. Responses stream into the transcript, and fall back to a buffered request on hosts that cannot stream.

## Overview

### For End Users

Enable the experiment at `Settings → AI` and open `Tools → AI Workspace`. The screen requires `manage_options`, and the menu entry is not shown to users who lack it.

**Key features:**

- A transcript, a context-scope control, and a multi-line prompt input, with four starter prompts on the empty state
- **Site Context**, in which the assistant may call tools to look up content the current user is allowed to read, and **General Knowledge**, in which no tools are declared at all
- Streaming responses with a visible in-progress state, and a cancel control that stops server-side work rather than just hiding output
- Post lists returned by a tool render as a read-only DataViews table inside the message, with editor links for posts the user can edit
- Draft creation as a proposal: the assistant proposes, the person picks which items to create, and only then is anything written
- Conversation history for the session, and a control to clear it and start a new topic
- An action in the block editor that opens the workspace seeded with the current post's identity

The screen degrades with an explanatory state rather than failing when the plugin has no valid AI credentials, or when no configured connector exposes a model that supports function calling.

### Context scopes

The scope belongs to the message being sent and is chosen in the composer:

- **Site Context** declares the permitted tools to the model.
- **General Knowledge** declares no tools; the assistant answers without touching the site.

Site Context reports its own unavailability instead of quietly behaving like General Knowledge. When no tool passes the current user's capability check, the turn returns a `tools_unavailable` status with a reason (`no_tools_registered` or `insufficient_capabilities`), and the transcript says so.

## The tool surface

The abilities offered to the model are an **allowlist**, not everything registered on the site. Three abilities ship in it, held in `Tool_Selector::DEFAULT_CANDIDATES`:

| Ability | What it does | Coarse capability to be declared |
| --- | --- | --- |
| `ai/search-content` | Full-text search over the post types exposed to abilities, returning titles and excerpts | any authenticated user |
| `ai/read-content-bodies` | Returns the full body text of up to five posts named by ID | any authenticated user |
| `ai/propose-drafts` | Records a proposed set of drafts for a person to approve; writes nothing | `edit_posts` |

The coarse capability decides only whether a tool is **declared** to the model. Object-level authorization stays inside `WP_Ability::execute()`, which runs the ability's own `permission_callback` on every call — the same path the MCP surface uses, so the two cannot disagree about what a user may do.

The allowlist is filterable; see [Extending the Experiment](#extending-the-experiment).

### `ai/search-content`

Registered by this experiment (not by Custom Abilities), so the workspace always has a search tool, and so the REST `abilities` endpoints, the Abilities Explorer and MCP clients can reach the same ability.

**Input schema** (`additionalProperties` is false):

```php
array(
    'type'       => 'object',
    'required'   => array( 'search' ),
    'properties' => array(
        'search'    => array( 'type' => 'string', 'minLength' => 1 ),
        'post_type' => array( 'type' => 'array' ), // Exposed post types; defaults to all of them.
        'status'    => array( 'type' => 'array' ), // Defaults to publish.
        'page'      => array( 'type' => 'integer', 'minimum' => 1 ),
        'per_page'  => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 20 ),
    ),
)
```

**Output schema:**

```php
array(
    'type'       => 'object',
    'required'   => array( 'results', 'total', 'total_pages' ),
    'properties' => array(
        'results'     => array( /* id, post_type, status, date, slug, link, title, excerpt, edit_link */ ),
        'total'       => array( 'type' => 'integer' ),
        'total_pages' => array( 'type' => 'integer' ),
    ),
)
```

Two properties are worth stating plainly:

- **No body content is ever returned.** Rows carry a title and a plain-text excerpt, generated from the content when the post has none. Reading a body is `ai/read-content-bodies`'s job, and is capped at five posts a call.
- **Every row is filtered at execute time** by the current user's read permission, using the same inherited-parent walk `core/read-content` performs. `total` comes from the underlying query and may therefore exceed the number of rows returned. The 20-item page cap is a context limit for the model, not an access control. `edit_link` is present only when the user can edit that post.

### `ai/read-content-bodies`

The reading half of retrieval, and the largest increase in reachable content on this surface. Registered by this experiment for the same reason the search ability is: `core/read-content` belongs to the Custom Abilities experiment, and the workspace's reach must not change when a different experiment is switched off.

**Input schema** (`additionalProperties` is false):

```php
array(
    'type'       => 'object',
    'required'   => array( 'ids' ),
    'properties' => array(
        'ids' => array(
            'type'        => 'array',
            'uniqueItems' => true,
            'minItems'    => 1,
            'maxItems'    => 5,
            'items'       => array( 'type' => 'integer', 'minimum' => 1 ),
        ),
    ),
)
```

**Output schema:**

```php
array(
    'type'       => 'object',
    'required'   => array( 'posts', 'unavailable' ),
    'properties' => array(
        'posts'       => array( /* id, post_type, status, date, slug, link, title, content, content_protected, edit_link */ ),
        'unavailable' => array( /* the requested IDs that were not returned */ ),
    ),
)
```

Three properties are load bearing:

- **Five posts a call, enforced twice.** The cap is in the input schema and clamped again in the execute callback, so it survives a transport that never validated the input. It is a context limit for the model, not an access control.
- **Every body is filtered at execute time** by the current user's read permission, using the same inherited-parent walk the search ability performs. A body of a password-protected post is empty unless the user can edit that post; `content_protected` says which posts those are. `edit_link` is present only when the user can edit that post.
- **An unreadable ID and an unknown ID are reported identically**, in `unavailable`. The caller supplied the IDs, so nothing is disclosed that it did not already know, and the two cases stay indistinguishable.

Bodies are returned as plain text: the rendered content with markup stripped. The model is given text to reason about rather than markup to reproduce.

### `ai/propose-drafts`

The model's only reach toward the write path. It records the exact field values it proposes in a user-scoped, expiring transient and returns a proposal identifier. It does not touch the posts table.

Three properties are load bearing:

- It is **withheld from the REST and MCP surfaces** (`show_in_rest` is false), and it refuses outright unless it is running inside a workspace turn, so a remote agent cannot accumulate proposals for someone else to approve.
- The proposal is **bound to the conversation it was made in**, read from the authenticated request rather than from the model's arguments.
- Only the declared item fields (`post_type`, `status`, `title`, `content`, `excerpt`) survive storage. Anything else the model supplies — a prose summary of what it claims it will write, for instance — is dropped.

A proposal carries at most 20 items, requires a title per item, and refuses a post type or a status the user cannot write rather than quietly downgrading it to a draft.

## The write path

There is **no registered write ability anywhere in this feature**. The writer (`Draft_Writer`) is a plain class reachable only from `REST\Proposal_Controller`. A registered write ability would be reachable by every ability consumer on the site — MCP, the Abilities Explorer, any third-party caller — none of which has a confirm gate, which would make confirmation a property of one controller rather than of the write path.

The flow:

1. The assistant calls `ai/propose-drafts` with resolved values. Nothing is written.
2. The transcript renders a confirmation panel listing the **stored** values, item by item, with a checkbox per item.
3. Approving posts to `/ai/v1/workspace/proposals/<id>/execute` with the conversation ID and the selected item keys. The controller re-checks the capability, compares the conversation, and rejects an unknown item key.
4. `Draft_Writer` writes each selected item independently, re-checking the user's capability per item at write time, and stamps each created post with an idempotency token in the `_wpai_workspace_proposal_item` meta key so a resubmitted execution finds the existing post instead of creating a second one.
5. Each item reports its own outcome — `created`, `denied`, `failed`, `duplicate` or `deselected` — to the person and, as data, to the assistant. Nothing is retried automatically.

Proposals expire 30 minutes after they are created, and the expiry stored on the record is compared on every read, so a stale confirmation cannot become executable again.

## Safety posture

### What the assistant may not do

It never deletes or trashes anything, never changes a user's role or capabilities, never manages connector credentials, never installs plugins or themes, and never writes settings. Its whole reachable surface is the three abilities above. It cannot write content at all except by proposing values that a person then confirms — and even then the write happens in a separate authenticated request that the person makes, not in the request that ran the turn.

### Why writes require confirmation

The assistant reads content other people wrote. Post bodies, titles and excerpts are author-controlled, and an instruction planted in one of them can influence what the model says next. So the confirmation surface deliberately shows the **stored resolved values** — the post type, status, title, content and excerpt that will actually be written — and never the assistant's own summary of them, because that summary is influenced by the content it read. The proposal store enforces this by dropping every field outside the declared item shape, so there is nothing else for the confirmation to render.

The proposal cap of 20 items exists for the same reason. Set approval is the weakest point of the write path: the longer the list, the less of it anyone reads, and an injected instruction wins by appending to an otherwise legitimate batch.

**Per-item selection is a deliberate divergence from the design reference.** The design reference for this screen (produced outside this repository) shows the confirmation as a plain list of the proposed items with a single "Create drafts" action for the whole batch. The shipped implementation instead gives each item its own checkbox, and every checkbox starts unchecked, so a person must actively select which items to create rather than approving the set as a whole. This was a deliberate choice, not drift, and should not be "fixed" back toward the mockup: selection is what makes partial approval possible, and the guarantee above — that a person approves the exact stored resolved values that will be written, never the assistant's prose summary of them — is stronger when approval can be scoped to a subset of a batch rather than forced to all-or-nothing. The server-side contract carries this, not just the UI: the proposal-execute route (`POST /workspace/proposals/{id}/execute` in `includes/Experiments/AI_Workspace/REST/Proposal_Controller.php`) requires a `selected` argument naming the approved item keys and rejects the request if it names none, and `Draft_Writer::write()` (`includes/Experiments/AI_Workspace/Draft_Writer.php`) walks every item in the proposal, skips writing any item whose key is absent from `selected`, and reports it back with a `deselected` outcome rather than silently omitting it.

### The tool surface is an allowlist

The model is offered only the abilities on the workspace allowlist that also pass the current user's capability check — currently three — not every ability registered on the site. A tool a user cannot run is never advertised to the model, so the model cannot ask for it. The allowlist is filterable, which means a site that adds an ability to it is widening what the assistant can reach; see the caution under [`wpai_workspace_tool_candidates`](#wpai_workspace_tool_candidates).

### What leaves the site

**Site content is sent to the configured third-party AI provider.** When the assistant calls a tool, the tool's result — post titles, excerpts, statuses, dates, slugs, permalinks and editor URLs, and whole post bodies when the body read tool is called — is sent to that provider as part of the next model request, along with the conversation so far. Two bounds apply, and neither is a reason to skip telling people about the first sentence:

- Only content the **requesting user could already read** is retrieved, because every row is permission-filtered at execute time.
- Only the **fields the tool returned** are sent. `ai/search-content` never returns a post body; `ai/read-content-bodies` does, for at most five posts a call, and only for posts the requesting user could already read.

Which provider receives it is whatever connector the site has configured; the streaming path currently builds an Anthropic model, and falls back to the ordinary buffered client (and therefore the site's configured provider preference) when it cannot. Sites with confidentiality obligations should treat enabling this experiment as a decision about egress, not only about features.

### Retrieved content is untrusted

Tool results reach the model wrapped in a provenance envelope — `wp_tool_result`, carrying the ability name, the site, the requesting user and roles, a `trust: untrusted` marker and a note stating that everything under `data` is site content to report on rather than instructions to follow. JSON gives unambiguous delimiters that prose tags do not. Tool output is **never** merged into the system instruction.

Instructing a model to ignore injected instructions is a mitigation, not a control. The controls are the ones above: the permission filter, the fact that no write happens without confirmation, and the rendering rules below.

### Assistant output is rendered inert

Model output is parsed by a restricted-subset markdown renderer that builds a node tree React renders as elements. No code path produces an HTML string, and nothing is handed to `dangerouslySetInnerHTML`:

- Raw HTML is never recognised; it falls through and is displayed as visible text.
- Images never produce a node that can issue a network request. Inline images render as inert text, and reference-style images and definitions are not recognised at all. Markdown images are the zero-click exfiltration channel, so both spellings are covered.
- Links are neutralised by policy: only `http` and `https` survive, and a destination outside the site's own host is marked inert and rendered as text rather than as a live anchor.
- Generated code is offered with a copy affordance, not an insert affordance.

Tool results rendered as a table are rebuilt field by field from the ability's declared output shape, so a result carrying extra properties cannot smuggle them into the UI.

## Architecture & Implementation

### Key hooks & entry points

`WordPress\AI\Experiments\AI_Workspace\AI_Workspace::register()` runs when the experiment is enabled. It:

- Registers `Admin_Page`, which adds the `Tools` submenu, enqueues the React bundle, and re-checks `manage_options` in the render callback so a direct call can never emit the app shell or its localized data.
- Registers `Show_In_Abilities`, so the curated core post types are exposed before the abilities build their input schemas.
- Registers `Search_Content` (`ai/search-content`), `Read_Content_Bodies` (`ai/read-content-bodies`) and `Propose_Drafts` (`ai/propose-drafts`).
- Registers `REST\Turn_Controller`, `REST\Stream_Responder` and `REST\Proposal_Controller`.
- Hooks `admin_enqueue_scripts` to add the block editor handoff action on `post.php` and `post-new.php`, for users who can open the workspace. The handoff carries the post's identity and a flattened, clamped title, and nothing else — the workspace reads any body through the same permission-checked tool path.

### The turn loop

`Turn_Runner` drives WordPress core's ability-backed tool plumbing — `WP_AI_Client_Prompt_Builder::using_abilities()` and `WP_AI_Client_Ability_Function_Resolver` — rather than brokering abilities itself. Per round it:

1. Re-reads the out-of-band cancellation marker, before the model call and again before any tool runs.
2. Asks the model for a message, streaming text deltas to the caller's callback when one was supplied.
3. Executes each ability call individually (`execute_ability()`, not the batch form), so the provenance envelope and the one-log-row-per-invocation rule have a seam to apply at.
4. Feeds the enveloped results back as a user message.

A turn is capped at five rounds by default and terminates with a status of `complete`, `max_rounds` or `cancelled`.

### Conversation and proposal storage

Both live in **user-scoped transients** sharing the plugin's `wpai_` prefix, so uninstall removes them without naming them:

| Store | Prefix | Lifetime |
| --- | --- | --- |
| Conversation | `wpai_workspace_conv_` | 2 hours since last touch |
| Cancellation marker | `wpai_workspace_cancel_` | 10 minutes |
| Proposal | `wpai_workspace_proposal_` | 30 minutes |

A conversation belongs to one user: the transient key is derived from the owner's user ID as well as the conversation ID, and the stored owner is compared again on load. Without both, a conversation ID would be an enumerable read of somebody else's private and draft post content. A conversation ID that does not resolve for the requesting user is reported as not found rather than silently starting a new conversation.

### Streaming

Streaming is opt-in per request: only a request carrying the `X-WP-AI-Stream: 1` header is considered, and the CLI SAPI is refused outright. The turn route writes no output itself — it exposes the `wpai_workspace_stream_emitter` filter, and `REST\Stream_Responder` is the consumer that turns the emitter into server-sent events (`delta` frames, then one `result` or `error` frame, then `done`). Headers are sent lazily on the first delta, so a turn that produced no streamed text still answers with the ordinary JSON body and the client falls back to the buffered shape.

On the provider side, `Streaming_Turn_Driver` builds an Anthropic streaming model and `Streaming_Http_Transporter` issues the request through its own opener, because WordPress's HTTP API buffers the whole body before returning. That detour skips `pre_http_request`, so the transporter re-establishes the two protections the buffered path gets for free: **connector approval** is decided before the opener is called, and a **request log entry** is written in a `finally` so refusals and failed connections are recorded too. A streamed entry's `duration_ms` measures time to response headers and carries no token counts, and `context.streaming` marks it so the two shapes stay distinguishable.

Every failure to stream — no streaming model, an unapproved connector, a transport that would not open, a provider that refused — returns null so the turn answers with a buffered request instead, and fires `wpai_workspace_streaming_fallback` so the decision is visible. Streaming degrades to a slower answer, never to no answer.

The SDK-level streaming types come from the `streaming` feature of `includes/SDK_Overlay.php`, which forward-ports them from the PHP AI Client and is loaded only when the bundled SDK does not already provide them.

## REST API

All routes require `manage_options`, checked on every request independently of nonce validation. Cookie-authenticated requests additionally have to clear core's REST nonce check.

| Method | Route | Purpose |
| --- | --- | --- |
| `POST` | `/wp-json/ai/v1/workspace/messages` | Run a turn (`message`, optional `conversation_id`, `scope` of `site` or `general`) |
| `POST` | `/wp-json/ai/v1/workspace/messages/cancel` | Mark the conversation's in-flight turn cancelled (`conversation_id`) |
| `GET` | `/wp-json/ai/v1/workspace/proposals/<id>` | Read a proposal's stored resolved values |
| `DELETE` | `/wp-json/ai/v1/workspace/proposals/<id>` | Decline a proposal; writes nothing |
| `POST` | `/wp-json/ai/v1/workspace/proposals/<id>/execute` | Create the approved items (`conversation_id`, `selected`) |

Cancellation is a second route rather than client-abort detection: PHP only observes a disconnected client after it writes output, so a buffered turn cannot detect one at all. The cancel route writes a marker the turn loop re-reads between rounds.

`ai/search-content` and `ai/read-content-bodies` are also runnable like any other ability:

```bash
curl -X POST "https://yoursite.com/wp-json/wp-abilities/v1/abilities/ai/search-content/run" \
  -u "username:application-password" \
  -H "Content-Type: application/json" \
  -d '{ "input": { "search": "remote work", "per_page": 5 } }'
```

`ai/propose-drafts` is not available this way: it is withheld from REST and MCP, and refuses outside a workspace turn.

## Auditing with the AI Request Log

Enable the **AI Request Logging** experiment and every workspace tool call and every write attempt appears at `Tools → AI Request Logs`. The shape is fixed so workspace rows join with rows any other ability consumer writes:

- `type` is always `ability`.
- `operation` is the ability name — `ai/search-content`, `ai/read-content-bodies`, `ai/propose-drafts` — or `ai/create-drafts` for a write attempt.
- `status` is `success`, `error`, `denied`, or `skipped` for an idempotent duplicate. A **denial is recorded separately from a failure**: a refusal from the Abilities API sets `denied` and records `denial_reason` in the context.
- `context` carries `surface` (always `ai-workspace`), `conversation_id`, the `round` index, and a `tool` object naming the ability, the function name and the call ID. Write rows add a `proposal` object with the proposal ID, item key, post type and status.
- `user_id` is the person the tool ran as.

Between them, those fields answer "what did the assistant do on this site, for whom, and was it allowed" without reading application code. Logging is best-effort: `log_ai_request()` returns `false` harmlessly when the logging experiment is off, so it is called unconditionally and costs nothing when nobody is watching.

## Extending the Experiment

### `wpai_workspace_tool_candidates`

Filters the abilities the workspace may declare, as a map of ability name to the coarse capability required to declare it. An empty capability means any authenticated user.

```php
add_filter( 'wpai_workspace_tool_candidates', function ( array $candidates ): array {
    $candidates['my-plugin/read-analytics'] = 'manage_options';

    return $candidates;
} );
```

**Adding a candidate widens what the assistant can reach.** An ability added here is offered to a model that is reading content other people wrote, so it should be read-only, should enforce its own permissions inside `execute_callback` rather than only in its `permission_callback`, and should never be a destructive or credential-bearing operation. An ability that is not registered is skipped, so removing one is safe.

### `wpai_workspace_max_rounds`

Filters how many model rounds a single turn may run. Default 5, clamped to at least 1.

```php
add_filter( 'wpai_workspace_max_rounds', fn (): int => 3 );
```

### `wpai_workspace_system_instruction`

Filters the system instruction, which receives the scope as its second argument. **Tool results must never be merged into this value** — that is what keeps retrieved content out of the instruction channel.

```php
add_filter( 'wpai_workspace_system_instruction', function ( string $instruction, string $scope ): string {
    return $instruction . ' ' . 'Prefer British English spellings.';
}, 10, 2 );
```

### `wpai_workspace_stream_emitter`

Filters the callback that receives assistant text deltas. Returning a callable turns the turn's model call into a streaming one; returning null keeps it buffered. The workspace's own transport supplies the emitter, and the e2e harness uses this filter to suppress it.

### `wpai_workspace_preferred_streaming_models`

Filters the model IDs the streaming driver prefers, most preferred first.

### `wpai_workspace_streaming_fallback`

Action fired with a `Streaming_Exception` code (an integer, or `0` for anything else) and a message whenever a round falls back from streaming to a buffered request.

```php
add_action( 'wpai_workspace_streaming_fallback', function ( int $code, string $message ): void {
    error_log( "Workspace streaming fallback: {$code} {$message}" );
}, 10, 2 );
```

### `wpai_has_function_calling_support`

Filters whether a function-calling-capable model is available, for connectors that do not expose model metadata. Answered from metadata rather than a live request, so it is cheap enough to run before every turn.

## Testing

### Manual Testing

1. **Enable the experiment:**
   - Go to `Settings → AI`, enable global AI features, and toggle **AI Workspace**
   - Ensure valid AI connector credentials are configured
2. **Hold a conversation:**
   - Open `Tools → AI Workspace`, choose **Site Context**, and ask something that needs retrieval ("find posts about remote work")
   - Confirm text streams in, the tool step lists the call, and a post list renders as a table with editor links
   - Cancel a long turn and confirm the transcript reports the cancellation
   - Clear the conversation and confirm the next turn starts a new topic
3. **Scopes:**
   - Switch to **General Knowledge** and confirm the assistant says it cannot see site data
4. **Proposal flow:**
   - Ask for a multi-post plan ("titles and excerpts for a 5-part series on remote work")
   - Confirm the confirmation panel lists the stored values, deselect one item, approve the rest
   - Confirm only the approved items were created, that outcomes are reported per item, and that re-submitting the same approval does not create duplicates
5. **Permissions:**
   - As a non-administrator, confirm the Tools menu entry is absent and the routes return 403
   - With a limited role that can reach the screen via a filtered capability, confirm search results never contain another author's private or draft posts
6. **Auditing:**
   - Enable **AI Request Logging** and confirm each tool call and write attempt appears with `surface: ai-workspace`, its conversation ID and round

### Automated tests

- PHP integration tests live in `tests/Integration/Includes/Experiments/AI_Workspace/` and `tests/Integration/Includes/Abilities/Content/Search_ContentTest.php`
- Playwright specs live in `tests/e2e/specs/experiments/ai-workspace*.{js,ts}`, driven by the fixture scenarios in `tests/e2e-testing/responses/Anthropic/scenarios/`

Two things bite in practice:

- **Streaming cannot be mocked at the `pre_http_request` seam.** The streaming opener calls `fopen()` directly and never enters `wp_safe_remote_request()`, so a streamed round would leave the machine for real. Scenario-driven e2e specs therefore filter the emitter away (`ai_e2e_suppress_provider_streaming()`) and exercise the buffered path. Server-sent events from WordPress to the browser are a separate seam and stay covered by the specs that run without a scenario.
- **Running `npm run test:php` before `npm run test:e2e` fails.** The PHP suite reinstalls WordPress in the shared test environment and deactivates the plugins, so the e2e `enableExperiments()` helper cannot find the settings screen. See [TESTING.md](../TESTING.md#running-both-suites-in-one-session) for the reactivation command.

## Notes & Considerations

### Requirements

- WordPress 7.0+, for `WP_AI_Client_Prompt_Builder::using_abilities()` and `WP_AI_Client_Ability_Function_Resolver`
- Valid AI credentials, and at least one connector exposing a model that supports function declarations
- `manage_options` for the screen and every route behind it

### Limitations

- **Bodies are read five at a time, and never in bulk.** `ai/read-content-bodies` takes explicit IDs and caps a call at five posts, so a question that would need dozens of bodies at once cannot be answered in one pass. There is no way to ask for "every post in this category" as bodies.
- **Conversations are session-scoped and expire.** History lives in a transient with a two-hour idle lifetime; there are no saved, named threads.
- **No mobile-optimized layout.** The screen is built for a desktop admin.
- The transcript shows tool activity as a collapsible step listing each call, rather than a one-line retrieval trace; a result set narrowed by a permission check is not itself reported.
- Comment content is never retrievable. Comments are authored by unauthenticated visitors, which would let an anonymous party place instructions into an admin session.
- Because the streamed body begins before the REST server sets its status code, PHP may log one "headers already sent" notice per streamed turn. The client's frame parser ignores anything that is not an `event:` or `data:` line, so a host that prints the notice into the response does not corrupt the stream.
