# Custom Experiment Reference

This page is a reference example for developers extending the WordPress AI plugin with a custom experiment.

The example is intentionally small and uses the same APIs and patterns as the plugin itself. It is demo code, not production-ready plugin scaffolding.

## What This Example Shows

- Creating a feature class by extending `Abstract_Feature`
- Registering the experiment with the AI plugin
- Adding metadata such as the label, description, and category
- Hooking into WordPress actions and filters in `register()`
- Disabling the experiment with the standard feature filters

## Reference Class

The plugin ships with a complete reference implementation at:

- `includes/Experiments/Example_Experiment/Example_Experiment.php`
- `includes/Experiments/Example_Experiment/README.md`

That example is not enabled as a default built-in experiment. It exists to show the expected structure for third-party extensions.

## Minimal Plugin Example

The following example shows the smallest practical custom experiment plugin:

```php
<?php
/**
 * Plugin Name: My AI Experiment
 */

declare( strict_types=1 );

namespace MyPlugin\AI;

use WordPress\AI\Abstracts\Abstract_Feature;
use WordPress\AI\Experiments\Experiment_Category;

class My_Experiment extends Abstract_Feature {
	public static function get_id(): string {
		return 'my-experiment';
	}

	protected function load_metadata(): array {
		return array(
			'label'       => __( 'My Experiment', 'my-plugin' ),
			'description' => __( 'Example custom experiment built on the AI plugin.', 'my-plugin' ),
			'category'    => Experiment_Category::ADMIN,
		);
	}

	public function register(): void {
		add_action( 'admin_notices', array( $this, 'render_notice' ) );
	}

	public function render_notice(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		echo '<div class="notice notice-info"><p>';
		echo esc_html__( 'My Experiment is active.', 'my-plugin' );
		echo '</p></div>';
	}
}

add_action(
	'wpai_register_features',
	static function( $registry ): void {
		$registry->register_feature( new My_Experiment() );
	}
);
```

## Why This Follows Recommended Patterns

- `Abstract_Feature` provides the shared experiment lifecycle used throughout the plugin.
- `load_metadata()` keeps labels and descriptions in one place, matching built-in experiments.
- `register()` is where the experiment attaches behavior through WordPress hooks.
- `wpai_register_features` is the safest entry point for third-party plugins because it registers your feature after the plugin sets up its registry.

## Alternative Registration Method

If you want your class added to the default feature class list before instantiation, use the `wpai_default_feature_classes` filter:

```php
add_filter(
	'wpai_default_feature_classes',
	static function( array $feature_classes ): array {
		$feature_classes[ My_Experiment::get_id() ] = My_Experiment::class;
		return $feature_classes;
	}
);
```

This is useful when your integration closely mirrors the plugin's built-in feature loading flow.

## Disable the Experiment

Disable only this experiment:

```php
add_filter( 'wpai_feature_my-experiment_enabled', '__return_false' );
```

Disable all AI plugin features:

```php
add_filter( 'wpai_features_enabled', '__return_false' );
```

## Next Steps

After the experiment class is working, most production integrations will also want to:

- add tests under `tests/Integration/`
- add any editor or admin assets through `Asset_Loader`
- document settings, abilities, and REST endpoints
- note any provider or environment requirements

For a deeper walkthrough, see the [Developer Guide](../DEVELOPER_GUIDE.md) and the in-repo Example Experiment reference implementation.
