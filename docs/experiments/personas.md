# Personas

## Summary

The Personas experiment introduces reusable personas — a role, an audience, and a brand voice — that shape the style of AI-generated content. One persona definition applies across every content generation experiment that opts in, so a site keeps a consistent voice without editing a prompt per feature.

Personas influence style only. They never add facts, and when a persona conflicts with an explicit instruction in a request, the request wins.

## Overview

### For End Users

When enabled, the experiment adds:

- A **Personas** screen under **Posts**, where personas are created and edited like posts. The title names the persona and the content describes its voice; a **Persona details** sidebar panel adds who is speaking (role) and who they are speaking to (audience). Editors and administrators can define personas; authors and below select from what exists.
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

Abilities receive the post they act on as part of their input rather than as an argument to the system instruction, so the experiment captures the executing post ID from `wp_before_execute_ability` and the service reads it back during resolution. Abilities that pass a `comment_id` (suggest reply) resolve to the comment's post. Abilities with no post context fall back to the site-wide default; today that is image prompt generation, which receives text context rather than a post ID.

### Capabilities

Defining a persona and selecting one are different decisions, so they sit at different capability levels:

| Action | Capability | Roles by default |
| --- | --- | --- |
| Create, edit, delete, or read a persona definition | `edit_others_posts` | Editor, Administrator |
| Choose the site-wide default persona | `manage_options` | Administrator |
| Choose the persona for a single post | `edit_post` | Anyone who can edit that post |

A persona's text is injected into the system instruction of every generation that uses it, which makes defining one closer to site configuration than to authoring. Gating definitions at `edit_others_posts` keeps them with the editorial roles that own a publication's voice, while leaving authors free to pick from what exists.

The post type is nested under **Posts** rather than **Settings** for this reason. WordPress gates an admin screen on its parent menu's capability as well as the post type's own, so parenting personas to `options-general.php` would lock out every role below administrator regardless of the capabilities the post type declares.

Reading is gated too. The core REST posts controller treats any published post as readable once its post type is exposed to REST, even when the post type is not public, which would let visitors read persona text at `/wp/v2/wpai_persona`. The post type therefore maps `read` to the manage capability and uses `REST_Personas_Controller`, which requires `read_post`, the same approach core takes for `wp_block`. Authors still see persona names in the picker, because the editor receives them from PHP rather than over REST.

Sites that want a different boundary can remap it:

```php
add_filter(
	'wpai_persona_post_type_args',
	static function ( array $args ): array {
		$args['capabilities'] = array_fill_keys( array_keys( $args['capabilities'] ), 'manage_options' );

		return $args;
	}
);
```

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
- `ai/image-prompt-generation`

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

## Data Removal

When the plugin is deleted, the uninstall routine removes persona definitions (with their meta and revisions) and every per-post persona selection, alongside the rest of the plugin's data. Sites can keep them by returning `false` from `wpai_remove_data_on_uninstall`.

## Testing

### Manual Testing

1. Enable global experiments and **Personas** in **Settings > AI**.
2. Visit **Posts > Personas** and confirm the persona post type is available. Create a persona and confirm it appears in the **Default persona** dropdown on the Personas card.
3. Set a default persona, then generate a title or summary on a post and confirm the tone matches.
4. Open a post, set a different persona in the **Persona** sidebar panel, save, and confirm generation follows the post's persona rather than the site default.
5. Set the post's persona to **No persona**, save, and confirm generation ignores the site default.
6. Disable the experiment and confirm generated output returns to its previous behavior.
7. As an Editor, confirm **Posts > Personas** is reachable. As an Author, confirm it is refused, but the **Persona** sidebar panel still works on their own post.
8. Logged out, request `/wp-json/wp/v2/wpai_persona` and confirm no personas are returned.

### Automated Testing

- `tests/Integration/Includes/Services/Personas_Test.php` covers the registry, sanitization, resolution order, and formatting.
- `tests/Integration/Includes/Experiments/Personas/PersonasTest.php` covers the experiment: post type arguments, capabilities, REST read restrictions, meta registration and sanitization, the settings field, ability context capture (including comment replies), and the editor script data.
- `tests/Integration/Includes/Abilities/Persona_InjectionTest.php` covers opt-in behavior and confirms abilities that have not opted in are unchanged.
- `tests/Integration/Includes/Admin/UninstallTest.php` covers removal of persona definitions and per-post selections on uninstall.
- `tests/e2e/specs/experiments/personas.spec.js` covers the persona picker, saving a selection, defining a persona with a role and audience, the admin screen, and the disabled state.

## Related Files

- `includes/Services/Personas.php`
- `includes/Experiments/Personas/Personas.php`
- `includes/Experiments/Personas/REST_Personas_Controller.php`
- `includes/Abstracts/Abstract_Ability.php`
- `src/experiments/personas/`
