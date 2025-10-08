# Example Feature

Reference implementation showing how to build features for the AI plugin.

## Summary
- Extends `Abstract_Feature` and implements `Conditional_Feature`
- Adds footer markup for logged-in users
- Modifies the document title while `WP_DEBUG` is true
- Registers a REST endpoint at `/wp-json/ai/v1/example`

## REST Endpoint
The feature registers a REST endpoint to demonstrate how to expose feature data.

**Endpoint:** `GET /wp-json/ai/v1/example`

**Permission:** `manage_options`

**Example Response:**
```json
{
  "feature_id": "example-feature",
  "label": "Example Feature",
  "description": "Demonstrates the AI feature system with example hooks and functionality.",
  "enabled": true,
  "message": "Example feature is active!"
}
```

## Disable The Feature
Filter the `ai_feature_enabled` value:

```php
add_filter(
	'ai_feature_enabled',
	static function( $enabled, $feature_id ) {
		return 'example-feature' === $feature_id ? false : $enabled;
	},
	10,
	2
);
```

## Create Your Own Feature
1. Duplicate this folder and rename the namespace/class.
2. Extend `WordPress\AI\Abstracts\Abstract_Feature`.
3. Register hooks in `register()`.
4. Optionally implement `Conditional_Feature` for requirement checks.

See `Example_Feature.php` for a complete reference. For a more detailed guide, see the [Developer Guide](../../CONTRIBUTING.md).
