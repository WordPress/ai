# Prompt Customization

AI abilities expose scoped filters so developers can customize prompt templates and prompt builders without forking built-in experiments.

Each scoped hook uses the ability name without the `ai/` prefix, with hyphens converted to underscores. For example, `ai/title-generation` becomes `title_generation`.

## System Instructions

Use `wpai_{slug}_system_instruction` to customize the system instruction for one ability. This runs after the global `wpai_system_instruction` filter.

```php
add_filter(
	'wpai_title_generation_system_instruction',
	static function ( string $instruction ): string {
		return $instruction . "\nAvoid clickbait and all-caps phrasing.";
	}
);
```

The global filter remains available when a site-wide change is desired:

```php
add_filter(
	'wpai_system_instruction',
	static function ( string $instruction, string $ability_name ): string {
		if ( 'ai/title-generation' !== $ability_name ) {
			return $instruction;
		}

		return $instruction . "\nPrefer concise language.";
	},
	10,
	2
);
```

## User Prompts

Use `wpai_{slug}_prompt` to modify the assembled user prompt before it is sent to the model.

```php
add_filter(
	'wpai_summarization_prompt',
	static function ( string $prompt, string $length ): string {
		if ( 'short' === $length ) {
			return $prompt . "\nUse one sentence.";
		}

		return $prompt;
	},
	10,
	2
);
```

Two abilities already had prompt filters before the scoped prompt customization work. Their hook names are unchanged and their richer signatures are preserved:

- `wpai_meta_description_prompt( $prompt, $content, $title )`
- `wpai_content_classification_prompt( $prompt, $context, $taxonomy, $assigned_terms, $available_terms )`

## Prompt Builders

Use `wpai_{slug}_prompt_builder` to adjust the configured prompt builder before support validation and generation.

```php
add_filter(
	'wpai_title_generation_prompt_builder',
	static function ( \WP_AI_Client_Prompt_Builder $builder ): \WP_AI_Client_Prompt_Builder {
		return $builder->using_temperature( 0.4 );
	}
);
```

The builder filter runs after the ability applies its default model preference, so a callback may intentionally override the model, provider, temperature, request options, or other builder settings.

Always return the `WP_AI_Client_Prompt_Builder` instance. If a callback returns another value, the plugin ignores that value and continues with the original builder.

Some abilities configure structured JSON responses or request options before the filter runs. Extend those builders instead of replacing them so required schemas, file attachments, and timeouts are preserved.
