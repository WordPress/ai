# Example Experiment

Reference implementation showing how to build experiments for the AI plugin.

## Summary
- Extends `Abstract_Experiment`
- Adds footer markup for logged-in users
- Modifies the document title while `WP_DEBUG` is true
- Registers a REST endpoint at `/wp-json/ai/v1/example`

## REST Endpoint
This experiment registers a REST endpoint to demonstrate how to expose experiment data.

**Endpoint:** `GET /wp-json/ai/v1/example`

**Permission:** `manage_options`

**Example Response:**
```json
{
  "experiment_id": "example-experiment",
  "label": "Example Experiment",
  "description": "Demonstrates the AI experiment system with example hooks and functionality.",
  "enabled": true,
  "message": "Example experiment is active!"
}
```

## Disable The Experiment
Use the experiment-specific filter:

```php
add_filter( 'ai_experiments_experiment_example-experiment_enabled', '__return_false' );
```

Or use the generic filter to disable all experiments:

```php
add_filter( 'ai_experiments_enabled', '__return_false' );
```

## Create Your Own Experiment
1. Duplicate this folder and rename the namespace/class.
2. Extend `WordPress\AI\Abstracts\Abstract_Experiment`.
3. Set experiment properties (`$id`, `$label`, `$description`) in the constructor.
4. Register hooks in the `register()` method.

See `Example_Experiment.php` for a complete reference.
