<?php
/**
 * Title generation feature implementation.
 *
 * @package WordPress\AI
 */

declare( strict_types=1 );

namespace WordPress\AI\Features\Title_Generation;

use WordPress\AI\API_Request;
use WordPress\AI\Abilities\Title_Generation as Title_Generation_Ability;
use WordPress\AI\Abstracts\Abstract_Feature;

/**
 * Title generation feature.
 *
 * @since 0.1.0
 */
class Title_Generation extends Abstract_Feature {

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
	public function register(): void {
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
	 * Generates title suggestions from the given content.
	 *
	 * @since 0.1.0
	 *
	 * @param string|array<string, string> $context The context to generate a title from.
	 * @param int $n The number of titles to generate.
	 * @return array<string>|\WP_Error The generated titles, or a WP_Error if there was an error.
	 */
	public function generate_titles( $context, int $n = 1 ) {
		// Convert the context to a string if it's an array.
		if ( is_array( $context ) ) {
			$context = implode(
				"\n",
				array_map(
					static function ( $key, $value ) {
						return sprintf(
							'%s: %s',
							ucwords( str_replace( '_', ' ', $key ) ),
							$value
						);
					},
					array_keys( $context ),
					$context
				)
			);
		}

		// Make our request.
		$request  = new API_Request();
		$response = $request->generate_text(
			'"""' . $context . '"""',
			$this->get_system_instruction(),
			array(
				'candidateCount' => (int) $n,
				'temperature'    => 0.7,
			)
		);

		// If we have an error, return it.
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return $response;
	}
}
