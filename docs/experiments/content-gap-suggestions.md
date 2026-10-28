# Content Gap Suggestions

## Summary

The Content Gap Suggestions experiment adds a **Content Opportunities** widget to the WordPress dashboard. It reads what visitors search for from a connected analytics plugin (Jetpack Stats for now), filters and aggregates that data into search patterns, and asks the AI model for new post ideas. Every idea can only become a **draft** that a person reviews, so nothing is ever published automatically. The AI step is the `ai/content-gap-suggestions` Ability, which can also be called directly over REST.

## Overview

### For End Users

When enabled, and when a supported analytics plugin is connected, the dashboard shows a **Content Opportunities** widget:

1. Click **Generate suggestions**. Nothing is sent to the AI provider until you click.
2. Review the suggested post titles and outlines.
3. Click **Create draft** to create a draft post (title and outline pre-filled) and open it in the editor, or **Dismiss** to remove an idea.

If something goes wrong (for example the analytics plugin returns an error, or the AI provider is unavailable), the widget shows the error message above a **Try again** button.

**Key Features:**

- Suggestions are based on real search behavior, not guesses
- Only filtered, aggregated patterns are sent to the AI provider, not the raw stats
- Suggestions only ever create a draft; publishing always stays a manual step
- The widget only appears when the experiment is enabled and an analytics provider is available

### For Developers

The experiment consists of four components:

1. **Experiment Class** (`WordPress\AI\Experiments\Content_Gap_Suggestions\Content_Gap_Suggestions`): registers the ability and enqueues the dashboard script.
2. **Ability Class** (`WordPress\AI\Abilities\Content_Gap_Suggestions\Content_Gap_Suggestions`): fetches search data, filters and aggregates it, builds the prompt, and parses the structured response.
3. **Stats layer** (`WordPress\AI\Stats\*`): a provider interface, a registry, a Jetpack Stats adapter, and the search term filter. The same layer is intended to be reused by future analytics-based experiments.
4. **Dashboard widget** (`WordPress\AI\Admin\Dashboard\Content_Opportunities_Widget`): renders the mount point for the React app in `src/experiments/content-gap-suggestions/`.

## Architecture & Implementation

### Key Hooks & Entry Points

`Content_Gap_Suggestions::register()` adds:

- `wp_abilities_api_init` → `register_abilities()` registers `ai/content-gap-suggestions`.
- `admin_enqueue_scripts` → `enqueue_assets()` loads the widget script on the Dashboard (`index.php`) for users with `manage_options`.

`Dashboard_Widgets::register_widgets()` registers the widget only when the experiment is enabled **and** `Stats_Provider_Registry::get_active_provider()` returns a provider.

### Assets & Data Flow

1. The dashboard script mounts a React app into `#ai-content-gap-suggestions-root`.
2. Clicking **Generate suggestions** runs the ability with `{ limit: 5 }`.
3. The ability asks the active stats provider for search queries (last 30 days, up to 50 terms).
4. `Search_Term_Filter::filter()` merges and filters the terms into patterns.
5. Only the patterns and their counts are placed in the prompt (`<search-patterns>`), and the model returns structured JSON (`title` and `outline`).
6. **Create draft** creates a `draft` post through `POST /wp/v2/posts`, escaping the outline before it is written into block markup, then redirects to the editor.

Localized data (`window.aiContentGapSuggestionsData`):

| Key | Description |
| --- | --- |
| `enabled` | Whether the experiment is enabled. |
| `widgetRoot` | ID of the React mount point. |
| `postEditBaseUrl` | `admin_url( 'post.php' )`, used to open the new draft. |

### Stats Providers

Providers implement `WordPress\AI\Stats\Stats_Provider_Interface`:

| Method | Description |
| --- | --- |
| `get_id()` | Unique provider ID. |
| `is_available()` | Whether the provider can be queried right now. |
| `get_search_queries( $args )` | Returns `array{term: string, count: int}[]` or a `WP_Error`. |

`Stats_Provider_Registry` registers the built-in `Jetpack_Stats_Provider`, applies the `wpai_stats_providers` filter, and returns the first available provider. Entries that are not providers, and providers whose ID is already registered, are skipped with a `_doing_it_wrong()` notice.

#### Jetpack Stats

`Jetpack_Stats_Provider` uses `Automattic\Jetpack\Stats\WPCOM_Stats`. It is available only when Jetpack is installed, **connected**, and the **Stats module is active**.

Jetpack Stats does not record on-site WordPress searches. Its search-terms report lists the terms visitors used on **external search engines** to reach the site, and the experiment uses that as a proxy for topics people want content about. It is not a "zero-result on-site search" report.

### Search Term Filtering

`Search_Term_Filter::filter()` sits between the analytics data and the AI provider. It is a best-effort heuristic that reduces the chance of sending personal data to the AI provider. It does not guarantee that, so sites with stricter needs can drop more terms with the `wpai_stats_term_looks_identifying` filter. It:

- Normalizes terms (lowercase, trimmed, whitespace collapsed) and merges duplicates
- Drops terms seen fewer than twice, which could be a single visitor
- Drops terms that look like email addresses, long digit runs, or formatted phone, SSN, or card numbers
- Returns at most 20 patterns, highest count first

### Input Schema

| Property | Type | Description |
| --- | --- | --- |
| `limit` | integer (1–10) | Maximum number of suggestions. Defaults to 5. |

### Output Schema

```json
{
  "suggestions": [
    { "title": "How to Start a Vegetable Garden", "outline": "- Choose a sunny spot\n- Prepare the soil" }
  ],
  "message": "Only set when there are no suggestions."
}
```

When `suggestions` is empty, `message` explains why: the provider reported no search terms, or every term was skipped because it was not seen often enough or looked like personal data. The widget shows that message. Low-traffic sites commonly hit this.

### Permissions

The ability and the dashboard script require `manage_options`, matching the dashboard widgets.

## Using the Ability via REST API

### Endpoint

```
POST /wp-json/wp-abilities/v1/abilities/ai/content-gap-suggestions/run
```

### Authentication

Use a logged-in administrator with a REST nonce (`X-WP-Nonce`), or an application password.

### Request Example

```bash
curl -X POST "https://example.com/wp-json/wp-abilities/v1/abilities/ai/content-gap-suggestions/run" \
  -u "admin:application-password" \
  -H "Content-Type: application/json" \
  -d '{ "input": { "limit": 3 } }'
```

### Error Responses

| Code | Meaning |
| --- | --- |
| `no_stats_provider` | No analytics provider is available (for Jetpack: not installed, not connected, or the Stats module is off). |
| `stats_provider_unavailable` | The provider became unavailable while the request ran. |
| `unsupported_model` | No connected AI provider supports text generation. |
| `invalid_response` | The model response could not be parsed. |
| `insufficient_capabilities` | The caller lacks `manage_options`. |

## Extending

### Adding a Stats Provider

```php
add_filter(
	'wpai_stats_providers',
	static function ( array $providers ): array {
		$providers[] = new My_Stats_Provider(); // Implements Stats_Provider_Interface.
		return $providers;
	}
);
```

### Force-disabling the Jetpack Provider

```php
add_filter( 'wpai_jetpack_stats_provider_available', '__return_false' );
```

### Dropping More Terms Before They Reach the AI Provider

```php
add_filter(
	'wpai_stats_term_looks_identifying',
	static function ( bool $looks_identifying, string $term ): bool {
		return $looks_identifying || str_contains( $term, 'my-brand' );
	},
	10,
	2
);
```

### Customizing the System Instruction

```php
add_filter(
	'wpai_content_gap_suggestions_system_instruction',
	static function ( string $instruction ): string {
		return $instruction . "\nPrefer how-to titles.";
	}
);
```

### Filtering the Prompt and Prompt Builder

Use `wpai_content_gap_suggestions_prompt` and `wpai_content_gap_suggestions_prompt_builder`.

### Disabling the Experiment

```php
add_filter( 'wpai_feature_content-gap-suggestions_enabled', '__return_false' );
```

## Testing

### Manual Testing

Jetpack Stats has no real data on a fresh site. To try the full flow, add a temporary must-use plugin (`wp-content/mu-plugins/fake-stats-provider.php`) and delete it afterwards:

```php
<?php
add_filter( 'wpai_stats_providers', function ( array $providers ): array {
	$providers[] = new class() implements \WordPress\AI\Stats\Stats_Provider_Interface {
		public function get_id(): string {
			return 'fake-stats';
		}
		public function is_available(): bool {
			return true;
		}
		public function get_search_queries( array $args = array() ) {
			return array(
				array( 'term' => 'how to start a vegetable garden', 'count' => 5 ),
				array( 'term' => 'how to start a vegetable garden', 'count' => 3 ),
				array( 'term' => 'best low maintenance houseplants', 'count' => 4 ),
			);
		}
	};
	return $providers;
} );
```

1. Enable **Content Gap Suggestions** and connect an AI provider that supports text generation.
2. Open the Dashboard and click **Generate suggestions** in **Content Opportunities**.
3. Confirm suggestions appear, then click **Create draft** and check the new post is a draft.
4. Remove the snippet, reload the Dashboard, and confirm the widget no longer appears.

### Automated Tests

- PHPUnit: `tests/Integration/Includes/Stats/`, `Abilities/Content_Gap_SuggestionsTest.php`, `Experiments/Content_Gap_Suggestions/`, `Dashboard/Content_Opportunities_WidgetTest.php`
- E2E: `tests/e2e/specs/experiments/content-gap-suggestions.spec.js` (uses a fake stats provider from `tests/e2e-testing/e2e-testing.php`)

## Notes & Considerations

### Requirements

- An AI connector that supports text generation
- Jetpack, connected, with the Stats module active (or another registered provider)

### Limitations

- Only Jetpack Stats is supported in this version
- Jetpack data reflects external search-engine terms, not on-site search
- Suggestions depend on enough recent traffic; low-traffic sites may get none
