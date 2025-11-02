# Example Feature

Reference implementation showing how to build features for the AI plugin.

## Summary
- Extends `Abstract_Feature`
- Adds footer markup for logged-in users
- Modifies the document title while `WP_DEBUG` is true
- Registers a REST endpoint at `/wp-json/ai/v1/example`
- Demonstrates registering an admin settings section with the `Provides_Settings_Section` trait

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

## Admin Settings Integration
The example feature hooks into the shared settings registry using the `Provides_Settings_Section` trait. The registry is passed as a parameter to the trait method.

```php
use WordPress\AI\Admin\Settings\Settings_Registry;
use WordPress\AI\Admin\Settings\Settings_Section;
use WordPress\AI\Admin\Settings\Settings_Toggle;
use WordPress\AI\Features\Traits\Provides_Settings_Section;

class Example_Feature extends Abstract_Feature {
    use Provides_Settings_Section;

    public function register(): void {
        // Always register settings sections so feature appears in admin.
        add_action(
            'ai_register_settings_sections',
            array( $this, 'register_settings_sections' )
        );

        // Only register functional hooks if feature is enabled.
        if ( ! $this->is_enabled() ) {
            return;
        }

        // Add your feature's functional hooks here.
    }

    public function register_settings_sections( Settings_Registry $registry ): void {
        if ( $registry->has_section( 'example-feature' ) ) {
            return;
        }

        // Pass the registry as the first parameter.
        $this->register_feature_settings_section(
            $registry,
            'example-feature',
            __( 'Example Feature', 'ai' ),
            array( $this, 'render_settings_section' ),
            array(
                'description' => __( 'Demonstration controls.', 'ai' ),
                'priority'    => 20,
            )
        );
    }

    public function render_settings_section( Settings_Toggle $toggle, Settings_Section $section ): void {
        // Render PHP fallback content.
        // The $toggle parameter gives you access to the global experiments toggle state.
    }
}
```

## Create Your Own Feature
1. Duplicate this folder and rename the namespace/class.
2. Extend `WordPress\AI\Abstracts\Abstract_Feature`.
3. Implement `load_feature_metadata()` to return `id`, `label`, and `description`.
4. Register hooks in the `register()` method.
5. Always call `is_enabled()` before registering functional hooks.
6. To add a settings section, use the `Provides_Settings_Section` trait.

See `Example_Feature.php` for a complete reference.
