<?php
/**
 * Internal Links experiment implementation.
 *
 * @package WordPress\AI
 */

declare( strict_types=1 );

namespace WordPress\AI\Experiments\Internal_Links;

use WordPress\AI\Abilities\Internal_Links\Internal_Links as Internal_Links_Ability;
use WordPress\AI\Abstracts\Abstract_Feature;
use WordPress\AI\Asset_Loader;
use WordPress\AI\Experiments\Experiment_Category;
use WordPress\AI\Settings\Settings_Registration;

use function WordPress\AI\get_min_content_length;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Internal Links experiment.
 *
 * @since x.x.x
 */
class Internal_Links extends Abstract_Feature {

	/**
	 * {@inheritDoc}
	 */
	public static function get_id(): string {
		return 'internal-links';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function load_metadata(): array {
		return array(
			'label'       => __( 'Internal Link Suggestions', 'ai' ),
			'description' => __( 'Suggests relevant internal links within post content, using existing text as anchor text. All suggestions require editor review before being applied. Requires an AI connector that includes support for text generation and embedding models.', 'ai' ),
			'category'    => Experiment_Category::EDITOR,
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function register(): void {
		add_action( 'wp_abilities_api_init', array( $this, 'register_abilities' ) );
		add_action( 'enqueue_block_editor_assets', array( $this, 'enqueue_assets' ), 5 );
	}

	/**
	 * Registers the embedding selection separately from the text-generation override.
	 *
	 * @since x.x.x
	 */
	public function register_settings(): void {
		register_setting(
			Settings_Registration::OPTION_GROUP,
			static::get_field_option_name( 'embedding' ),
			array(
				'type'              => 'object',
				'default'           => array(),
				'sanitize_callback' => array( $this, 'sanitize_embedding_config' ),
				'show_in_rest'      => array(
					'schema' => array(
						'type'                 => 'object',
						'additionalProperties' => false,
						'properties'           => array(
							'provider' => array(
								'type'    => 'string',
								'default' => '',
							),
							'model'    => array(
								'type'    => 'string',
								'default' => '',
							),
						),
					),
				),
			)
		);
	}

	/**
	 * Sanitizes the provider and model stored by the settings screen.
	 *
	 * @since x.x.x
	 *
	 * @param mixed $value The submitted embedding configuration.
	 * @return array{provider: string, model: string} Sanitized configuration.
	 */
	public function sanitize_embedding_config( $value ): array {
		$value = is_array( $value ) ? $value : array();

		return array(
			'provider' => isset( $value['provider'] ) && is_string( $value['provider'] ) ? trim( sanitize_text_field( $value['provider'] ) ) : '',
			'model'    => isset( $value['model'] ) && is_string( $value['model'] ) ? trim( sanitize_text_field( $value['model'] ) ) : '',
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_preloaded_options(): array {
		return array( static::get_field_option_name( 'embedding' ) );
	}

	/**
	 * Registers the internal links ability.
	 *
	 * @since x.x.x
	 */
	public function register_abilities(): void {
		wp_register_ability(
			'ai/' . $this->get_id(),
			array(
				'label'         => $this->get_label(),
				'description'   => $this->get_description(),
				'ability_class' => Internal_Links_Ability::class,
			)
		);
	}

	/**
	 * Enqueues and localises the block editor script.
	 *
	 * @since x.x.x
	 */
	public function enqueue_assets(): void {
		Asset_Loader::enqueue_script( 'internal_links', 'experiments/internal-links', array( 'include_core_abilities' => true ) );
		Asset_Loader::enqueue_style( 'internal_links', 'experiments/internal-links' );
		Asset_Loader::localize_script(
			'internal_links',
			'InternalLinksData',
			array(
				'enabled'          => $this->is_enabled(),
				'minContentLength' => get_min_content_length( 'internal-links', 75 ),
				'maxSuggestions'   => $this->get_max_suggestions(),
			)
		);
	}

	/**
	 * Returns the configured maximum number of link suggestions.
	 *
	 * Defaults to 5 and can be overridden via the `wpai_internal_links_max_suggestions` filter.
	 *
	 * @since x.x.x
	 *
	 * @return int Maximum number of suggestions (clamped to 1–10).
	 */
	private function get_max_suggestions(): int {
		/**
		 * Filters the maximum number of internal link suggestions returned per request.
		 *
		 * @since x.x.x
		 *
		 * @param int $max Maximum suggestions (default 5, clamped to 1–10).
		 */
		$max = (int) apply_filters( 'wpai_internal_links_max_suggestions', 5 );

		return max( 1, min( 10, $max ) );
	}
}
