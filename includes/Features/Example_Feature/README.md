# Example Feature

Reference implementation showing how to build features for the AI plugin.

## Summary
- Extends `Abstract_Feature`
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
Use the feature-specific filter:

```php
add_filter( 'ai_feature_example-feature_enabled', '__return_false' );
```

Or use the generic filter to disable all features:

```php
add_filter( 'ai_features_enabled', '__return_false' );
```

## Create Your Own Feature
1. Duplicate this folder and rename the namespace/class.
2. Extend `WordPress\AI\Abstracts\Abstract_Feature`.
3. Set feature properties (`$id`, `$label`, `$description`) in the constructor.
4. Register hooks in the `register()` method.

See `Example_Feature.php` for a complete reference.
