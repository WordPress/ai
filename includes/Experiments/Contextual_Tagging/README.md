# Contextual Tagging

AI-powered suggestions for post tags and categories based on content analysis.

## Summary
- Extends `Abstract_Experiment` with custom settings (strategy, max suggestions)
- Adds "Suggest Tags" and "Suggest Categories" buttons to the editor sidebar panels
- Registers the `ai/contextual-tagging` ability via the WordPress Abilities API
- Uses the `editor.PostTaxonomyType` filter for native editor integration

## Functionality

- Analyzes post content (title, body, existing terms) to suggest relevant taxonomy terms
- Supports both tags (flat) and categories (hierarchical, with parent/child)
- Configurable strategy: suggest only existing terms or allow new ones
- Suggestions include confidence scores and new/existing indicators
- Minimum 150 words required before suggestions are enabled

## Configuration

Settings are available under `Settings > AI Experiments`:

- **Taxonomy strategy**: "Only suggest existing terms" or "Suggest new terms based on context"
- **Maximum suggestions**: 1-10 (default 5)

## Hooks

- `ai_contextual_tagging_content` - Filter content before AI processing
- `ai_contextual_tagging_suggestions` - Filter suggestions after AI processing
- `ai_contextual_tagging_strategy` - Filter the strategy setting
- `ai_contextual_tagging_max_suggestions` - Filter the max suggestions setting

## REST Endpoint

**Endpoint:** `POST /wp-json/wp-abilities/v1/abilities/ai/contextual-tagging/run`

**Permission:** `edit_posts` (or `edit_post` for specific post IDs)

## Disable The Experiment

```php
add_filter( 'ai_experiments_experiment_contextual-tagging_enabled', '__return_false' );
```

## Documentation

See [docs/experiments/contextual-tagging.md](../../../docs/experiments/contextual-tagging.md) for full documentation.
