# Knowledge and Guidelines

## Summary

Stores site guidelines that AI features read as context, and adds a **Settings → Guidelines** page to manage them. Guidelines live in a shared `wp_knowledge` post type: one published row per scope (`guideline-site`, `guideline-copy`, …) plus one row per block (`guideline-block-core_paragraph`), with the text in `post_content`.

The Gutenberg plugin ships the same feature behind its `gutenberg-guidelines` experiment flag. Both plugins share one contract, so only one of them may provide it. The rule is **first to declare it wins**: this experiment checks what is already registered and stands down when another plugin got there first.

## Overview

### For End Users

Enabling the experiment adds a **Guidelines** item under **Settings**. Each section holds one guideline:

- **Site** — purpose, goals, and primary audience.
- **Copy** — tone, voice, style, and formatting.
- **Images** — style, dimensions, formats, mood.
- **Blocks** — per-block guidelines, added one block at a time.
- **Additional** — anything else.

Guidelines can be exported to and imported from a JSON file. AI experiments such as Title Generation, Excerpt Generation, Meta Description, Editorial Notes, and Type Ahead include the relevant guidelines in their prompts.

If the Gutenberg plugin already provides the Guidelines page, this experiment leaves it alone. The page URL is the same either way: `options-general.php?page=guidelines-wp-admin`.

### For Developers

The PHP lives in `includes/Experiments/Knowledge/`:

| File | Role |
| --- | --- |
| `Knowledge.php` | The experiment. Loads the shared functions, registers the post type, and decides whether this plugin owns the feature. |
| `knowledge-functions.php` | The shared, unprefixed `wp_*` contract. Every function is wrapped in `function_exists()`. |
| `Knowledge_Post_Type.php` | Registers the `wp_knowledge` post type and the `wp_knowledge_type` taxonomy. |
| `Knowledge_REST_Controller.php` | Locks down `/wp/v2/knowledge` so reads need authentication and new rows default to `private`. |
| `Guideline_Scopes_REST_Controller.php` | Read-only registry at `/wp/v2/knowledge/guideline-scopes`. |
| `Admin_Page.php` | The Settings → Guidelines submenu, block-library enqueue, and REST preload. |

The UI is a wp-build route in `routes/guidelines/`, a port of Gutenberg's `routes/guidelines`.

## Architecture & Implementation

### How "first plugin wins" works

Three separate mechanisms, mirroring what Gutenberg does:

1. **Guarded functions.** Every function in `knowledge-functions.php` sits inside `if ( ! function_exists( ... ) )`. The names use the `wp_` prefix on purpose — they are a shared contract, not plugin-private code.
2. **Distinct class names.** Gutenberg uses `Gutenberg_Knowledge_Post_Type`; this plugin uses the namespaced `WordPress\AI\Experiments\Knowledge\Knowledge_Post_Type`. There is never a redeclare fatal, so arbitration happens at the behaviour level instead.
3. **A post-type check.** `Knowledge_Post_Type::register()` returns early when `post_type_exists( 'wp_knowledge' )` is already true, and reports back whether it registered anything.

`Knowledge::register()` stores that answer. The scopes REST route and the Settings page are registered only when this plugin owns the feature, so the two plugins can never produce a duplicate page or a duplicate route.

Because Gutenberg requires its copy at plugin-load time and registers the post type on `init` priority 10, while this experiment runs on `init` priority 15, **Gutenberg wins whenever its flag is on**. When its flag is off, or Gutenberg is not installed, this plugin provides everything.

### Extending versus owning

Owning the implementation and extending the registries are separate things. To add a scope or a knowledge type, always use the filters. They work no matter which plugin owns the base implementation:

```php
add_filter(
	'wp_guideline_scopes',
	function ( array $scopes ): array {
		$scopes['legal'] = array(
			'title'       => __( 'Legal', 'my-plugin' ),
			'description' => __( 'Legal wording and disclaimers.', 'my-plugin' ),
			'order'       => 60,
		);
		return $scopes;
	}
);
```

The Settings page grows a section for the new scope automatically, and the `Guidelines` service starts reading it.

### Key hooks and entry points

- `WordPress\AI\Experiments\Knowledge\Knowledge::register()` runs on `init` priority 15 and wires everything.
- `wp_knowledge_types` — filters the knowledge types (`guideline`, `memory`, `note`).
- `wp_guideline_scopes` — filters the sections on the Settings page.
- `wp_guideline_max_length` — filters the character cap per guideline. Default 5000.
- `user_has_cap` — grants the dynamic `*_knowledge_items` capabilities.
- `rest_pre_insert_wp_knowledge` — sanitizes guideline content and re-stamps scope titles.

### Capabilities

`wp_knowledge` uses a `knowledge_item`/`knowledge_items` capability set that is granted at runtime rather than stored on roles:

- Administrators (`manage_options`) get every knowledge capability.
- Contributors and above (`edit_posts`) may list and create rows, and fully manage their own private rows.
- Publishing, and acting on other users' rows, stays with administrators.
- Subscribers get nothing and are stopped at the post-type door.

### Reading guidelines in your own code

Use the helpers in `includes/helpers.php`. They work whichever plugin owns the storage:

```php
$guidelines = \WordPress\AI\get_guidelines( 'copy' );

$prompt_fragment = \WordPress\AI\format_guidelines_for_prompt(
	array( 'site', 'copy' ),
	'core/paragraph'
);
```

`format_guidelines_for_prompt()` returns an XML-tagged string ready to drop into a prompt, or an empty string when there is nothing to include. Filter `wpai_use_guidelines` to turn the integration off, and `wpai_max_guideline_length` to change the cap.

## Related

- [Experiment framework](./experiment-framework.md)
- [Prompt customization](../PROMPT_CUSTOMIZATION.md)
