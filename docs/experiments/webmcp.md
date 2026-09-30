# WebMCP

## Summary

The WebMCP experiment registers a curated set of WordPress abilities as WebMCP tools on the page, so an agent browser (ChatGPT's in-app browser, Chrome builds with WebMCP) can call them through `document.modelContext`. The Abilities API stays the single registry: the experiment decides which abilities a page exposes, hands them to the browser one `registerTool` call at a time, and executes them through a REST route that runs the ability's own permission and input checks on the server.

Nothing is exposed until an ability opts in, a filter allows it, or the site owner lists it in the experiment's settings.

## Overview

When enabled, the experiment does three things.

1. On wp-admin screens (for logged-in users) and on the front end (only when something is exposed to visitors) it enqueues a small bridge script with the REST URLs and two tokens.
2. The bridge asks `GET /wp-json/ai/v1/webmcp/tools?context=admin|visitor` for the tools this page exposes and registers each one with `document.modelContext.registerTool()`.
3. When the agent calls a tool, the bridge posts to `POST /wp-json/ai/v1/webmcp/execute` and returns the ability's result as text content.

The browser only ever sees names, descriptions and input schemas. Permission callbacks, input validation and execution run in WordPress through `WP_Ability::execute()`.

## Contexts

Agent browsers cap the number of tools a page may register. In testing, a few hundred tools disabled WebMCP for the document with no error, while about thirty worked. A logged-in editor and a visitor also need different tools, so the experiment keeps two allowlists:

- `admin`: wp-admin screens, logged-in users.
- `visitor`: the front end. The script is not loaded there unless the visitor list is non-empty.

The context is decided by the surface, not the login: a logged-in user reading the front end gets the visitor set.

The cap defaults to 30 tools and can be changed with the `wpai_webmcp_max_tools` filter. Tools beyond the cap are left out, and the tools response reports how many in `truncated`.

## Exposing an ability

An ability is exposed in a context when any of these holds:

1. **Opt-in on the ability.** Add `webmcp` to its `meta` when registering it:

   ```php
   'meta' => array(
       'webmcp' => array( 'admin' => true, 'visitor' => false ),
       // or 'webmcp' => true (every context), or 'webmcp' => 'admin'
   ),
   ```

2. **The `wpai_webmcp_exposed_abilities` filter.**

   ```php
   add_filter( 'wpai_webmcp_exposed_abilities', function ( array $names, string $context ) {
       if ( 'admin' === $context ) {
           $names[] = 'core/get-site-info';
       }
       return $names;
   }, 10, 2 );
   ```

3. **The experiment's settings.** Two text fields under Settings, AI, WebMCP: abilities exposed in wp-admin and abilities exposed to visitors, comma-separated ability names.

Names that do not resolve to a registered ability are dropped silently. Abilities the current user may not run (per the ability's permission callback) are left out of the list, so the agent never sees a tool that would only answer with a permission error.

## Tool names

Ability names contain `/`, and a URL-encoded slash is rejected by stock Apache before WordPress runs (`AllowEncodedSlashes Off` is the default). Tool names therefore travel with `__` in place of `/`: the ability `core/get-post` is the tool `core__get-post`. The execute route maps the name back. An ability name must not itself contain `__`.

## Authentication

Requests from the bridge carry two tokens:

- `X-WP-Nonce`: the `wp_rest` nonce that authenticates the cookie session. Core rejects any other nonce in this header before a route runs.
- `X-WPAI-WebMCP-Nonce`: the experiment's own token (action `wpai_webmcp_execute`), required on every execution.

Both are printed with the page and refreshed from `GET /wp-json/ai/v1/webmcp/nonce` when an execution answers 403, so a page that stays open keeps working.

## REST routes

| Route | Method | Purpose |
| --- | --- | --- |
| `/ai/v1/webmcp/tools?context=admin` | GET | Tools the context exposes for the current user, plus fresh tokens. |
| `/ai/v1/webmcp/execute` | POST | Body: `{ "tool": "core__get-post", "context": "admin", "input": { ... } }`. Returns `{ "tool", "ability", "result" }` or the ability's own `WP_Error`. |
| `/ai/v1/webmcp/nonce` | GET | Fresh tokens. |

## Hooks

- `wpai_webmcp_exposed_abilities` (filter): `list<string> $names, string $context`. Adds or removes ability names for a context.
- `wpai_webmcp_max_tools` (filter): `int $max_tools`. Default 30.

## Testing in an agent browser

1. Enable the experiment and expose at least one ability. The quickest way is the settings field: WordPress registers `core/get-site-info`, `core/get-user-info` and `core/get-environment-info` on every site, all read-only, so any of them works without another experiment.
2. Open a wp-admin screen in a browser that implements WebMCP. ChatGPT's in-app browser does; in Chrome, WebMCP ships behind a flag in recent builds.
3. Ask the agent to list the site's tools, then to call one. Every write still goes through the ability's permission callback.

Without such a browser, `document.modelContext` is undefined and the bridge does nothing; the REST routes can be exercised directly with the two headers above.

## Prior art

This experiment follows the direction set in [#448](https://github.com/WordPress/ai/issues/448) and keeps [#224](https://github.com/WordPress/ai/pull/224) as prior art. Its three requirements (two tokens, the `__` separator, per-context curation with a cap) come from running a WordPress WebMCP bridge in production against ChatGPT's browser since August 2026.
