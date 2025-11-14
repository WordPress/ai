<?php
/**
 * Title generation feature implementation.
 *
 * @package WordPress\AI
 */

declare( strict_types=1 );

namespace WordPress\AI\Features\Title_Generation;

use WordPress\AI\Abilities\Title_Generation as Title_Generation_Ability;
use WordPress\AI\Abstracts\Abstract_Feature;
use WordPress\AI\Admin\Settings\Settings_Registry;
use WordPress\AI\Admin\Settings\Settings_Section;
use WordPress\AI\Admin\Settings\Settings_Toggle;
use WordPress\AI\Features\Traits\Provides_Settings_Section;

/**
 * Title generation feature.
 *
 * @since 0.1.0
 */
class Title_Generation extends Abstract_Feature {
	use Provides_Settings_Section;

	/**
	 * {@inheritDoc}
	 *
	 * @since 0.1.0
	 *
	 * @return array{id: string, label: string, description: string} Feature metadata.
	 */
	protected function load_feature_metadata(): array {
		return array(
			'id'          => 'title-generation',
			'label'       => __( 'Title Generation', 'ai' ),
			'description' => __( 'Generates title suggestions from content', 'ai' ),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 0.1.0
	 */
	protected function register_shared_hooks(): void {
		add_action(
			'ai_register_settings_sections',
			array( $this, 'register_settings_sections' )
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 0.1.0
	 */
	protected function register_enabled_hooks(): void {
		add_action( 'wp_abilities_api_init', array( $this, 'register_abilities' ) );
	}

	/**
	 * Registers any needed abilities.
	 *
	 * @since 0.1.0
	 */
	public function register_abilities(): void {
		wp_register_ability(
			'ai/' . $this->get_id(),
			array(
				'label'         => $this->get_label(),
				'description'   => $this->get_description(),
				'ability_class' => Title_Generation_Ability::class,
				),
			);
	}

	/**
	 * Registers the feature's settings section with the admin registry.
	 *
	 * @since 0.1.0
	 *
	 * @param \WordPress\AI\Admin\Settings\Settings_Registry $registry Settings registry instance.
	 */
	public function register_settings_sections( Settings_Registry $registry ): void {
		if ( $registry->has_section( 'title-generation' ) ) {
			return;
		}

		$this->register_feature_settings_section(
			$registry,
			'title-generation',
			__( 'Title Generation', 'ai' ),
			array( $this, 'render_settings_section' ),
			array(
				'description' => __( 'Generate alternative post titles using AI suggestions.', 'ai' ),
				'priority'    => 30,
			)
		);
	}

	/**
	 * Renders the settings section content.
	 *
	 * @since 0.1.0
	 *
	 * @param \WordPress\AI\Admin\Settings\Settings_Toggle  $toggle  Toggle service.
	 * @param \WordPress\AI\Admin\Settings\Settings_Section $section Section metadata.
	 */
	public function render_settings_section( Settings_Toggle $toggle, Settings_Section $section ): void {
		unset( $toggle, $section );
		?>
		<p>
			<?php esc_html_e( 'Enable this feature to surface AI-powered title suggestions inside the editor.', 'ai' ); ?>
		</p>
		<?php
	}
}
