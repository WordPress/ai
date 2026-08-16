# Personas

## Summary

The Personas experiment introduces reusable personas — a role, an audience, and a brand voice — that shape the style of AI-generated content. One persona definition applies across every content generation experiment that opts in, so a site keeps a consistent voice without editing a prompt per feature.

Personas influence style only. They never add facts, and when a persona conflicts with an explicit instruction in a request, the request wins.

## Overview

### For End Users

When enabled, the experiment adds:

- A **Personas** screen under **Settings**, where personas are created and edited like posts. The title names the persona; the content describes its voice.
- A **Default persona** setting on the Personas card in **Settings > AI**, applied to every persona-aware generation on the site.
- A **Persona** panel in the post editor sidebar, overriding the site default for that post.

Five personas ship with the experiment and can be used as-is or replaced: Professional, Friendly and conversational, Technical expert, Journalistic, and Playful.

The post editor panel offers three kinds of choice:

| Selection | Effect |
| --- | --- |
| Site default | Inherits whatever the site-wide default is, including later changes to it. |
| A named persona | Overrides the site default for this post. |
| No persona | Opts this post out of the site default entirely. |

The per-post override is stored in post meta, so it applies once the post is saved.

### For Developers

The experiment is made of two pieces:

1. **Service** (`WordPress\AI\Services\Personas`): the persona registry, resolution order, and prompt formatting.
2. **Experiment class** (`WordPress\AI\Experiments\Personas\Personas`): registers the post type, the per-post meta key, the settings field, and the editor panel.

Abilities opt in individually, in the same way they opt into guidelines.

## Architecture & Implementation

### Persona sources

Personas are merged from three sources, keyed by persona ID:

1. Built-in personas shipped with the plugin.
2. Posts in the `wpai_persona` post type. The post slug becomes the persona ID, so a persona post named `professional` replaces the built-in of the same ID.
3. The `wpai_personas` filter, which runs last and can add, adjust, or remove any of the above.

Every persona field is stripped of markup and shortcodes and collapsed to a single line before it can reach a prompt, and each field is truncated to 2000 characters.

### Resolution order

The persona applied to a generation is resolved as:

1. The `wpai_persona` post meta on the post being generated for.
2. The site-wide default (`wpai_feature_personas_field_default_persona`).
3. No persona, in which case nothing is injected.

A post whose meta is set to the reserved value `none` opts out of the site default rather than inheriting it.

Abilities receive the post they act on as part of their input rather than as an argument to the system instruction, so the experiment captures the executing post ID from `wp_before_execute_ability` and the service reads it back during resolution. Abilities with no post context fall back to the site-wide default.

### Prompt injection

`Abstract_Ability::get_system_instruction()` appends the persona after any guidelines and before the `wpai_system_instruction` filters run, so existing prompt customization still sees the final instruction. The injected block looks like:

```xml
<persona>
<name>Technical expert</name>
<role>An experienced practitioner writing for other practitioners.</role>
<voice>Exact and unembellished. …</voice>
<audience>Developers and technical readers who already know the fundamentals.</audience>
</persona>
```

### Opting an ability in

An ability opts in by overriding one method:

```php
protected function supports_personas(): bool {
	return true;
}
```

The default is `false`, so an ability is never affected until it opts in. Abilities that produce prose a reader sees should opt in; abilities that classify, moderate, or return structured data should not, because a voice instruction distorts their output.

Abilities that opt in today:

- `ai/title-generation`
- `ai/excerpt-generation`
- `ai/meta-description`
- `ai/summarization`
- `ai/content-resizing`
- `ai/type-ahead`
- `ai/suggest-reply`
- `ai/generate-image-prompt`

An ability that already knows its post ID can pass it explicitly through the reserved `post_id` key:

```php
$this->get_system_instruction( null, array( 'post_id' => $post_id ) );
```

## Extensibility

### Registering a persona in code

```php
add_filter(
	'wpai_personas',
	static function ( array $personas ): array {
		$personas['support'] = array(
			'label'    => __( 'Support agent', 'my-plugin' ),
			'role'     => 'A support specialist answering a customer question.',
			'voice'    => 'Calm, specific, and free of blame. Lead with the fix.',
			'audience' => 'Customers who are mid-problem and want it resolved.',
		);

		return $personas;
	}
);
```

Returning a persona array without a `label` drops it, since it could not be presented for selection.

### Overriding the resolved persona

```php
add_filter(
	'wpai_active_persona',
	static function ( string $persona_id, ?int $post_id ): string {
		if ( $post_id && 'product' === get_post_type( $post_id ) ) {
			return 'professional';
		}

		return $persona_id;
	},
	10,
	2
);
```

### Other hooks

| Hook | Type | Purpose |
| --- | --- | --- |
| `wpai_personas` | filter | The persona registry, keyed by ID. |
| `wpai_active_persona` | filter | The resolved persona ID for the current generation. |
| `wpai_use_personas` | filter | Return `false` to suppress injection entirely. |
| `wpai_max_persona_length` | filter | Maximum characters per persona field. Default 2000. |
| `wpai_persona_post_type_args` | filter | Arguments passed to `register_post_type()`, for changing the admin menu location or restricting who may manage personas. |

## Testing

### Manual Testing

1. Enable global experiments and **Personas** in **Settings > AI**.
2. Visit **Settings > Personas** and confirm the persona post type is available. Create a persona and confirm it appears in the **Default persona** dropdown on the Personas card.
3. Set a default persona, then generate a title or summary on a post and confirm the tone matches.
4. Open a post, set a different persona in the **Persona** sidebar panel, save, and confirm generation follows the post's persona rather than the site default.
5. Set the post's persona to **No persona**, save, and confirm generation ignores the site default.
6. Disable the experiment and confirm generated output returns to its previous behavior.

### Automated Testing

- `tests/Integration/Includes/Services/Personas_Test.php` covers the registry, sanitization, resolution order, and formatting.
- `tests/Integration/Includes/Abilities/Persona_InjectionTest.php` covers opt-in behavior and confirms abilities that have not opted in are unchanged.

## Related Files

- `includes/Services/Personas.php`
- `includes/Experiments/Personas/Personas.php`
- `includes/Abstracts/Abstract_Ability.php`
- `src/experiments/personas/`
