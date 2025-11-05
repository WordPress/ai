<?php
/**
 * Title generation feature implementation.
 *
 * @package WordPress\AI
 */

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
	 * System instruction the feature uses.
	 *
	 * @since 0.1.0
	 * @var string
	 */
	protected $system_instruction = 'You are an editorial assistant that generates title suggestions for online articles and pages. You will be provided some context and the goal is to generate a concise, engaging, and accurate title that reflects that context. This title should be optimized for clarity, engagement, and SEO - while maintaining an appropriate tone for the author\'s intent and audience. The title suggestion should be no more than 80 characters; should not contain any markdown, bullets, numbering, or formatting - plain text only; should be distinct in tone or focus; must reflect the actual content and context, not generic clickbait. The context you will be provided is delimited by triple quotes.';

	/**
	 * Load feature metadata.
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
	 * Register any needed hooks.
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
			$this->get_ability_slug(),
			array(
				'label'         => $this->get_label(),
				'feature'       => $this,
				'ability_class' => Title_Generation_Ability::class,
			),
		);
	}

	/**
	 * Generates title suggestions from the given content.
	 *
	 * @since 0.1.0
	 *
	 * @param string $context The context to generate a title from.
	 * @param int $n The number of titles to generate.
	 * @return array<string>|\WP_Error The generated titles, or a WP_Error if there was an error.
	 */
	public function generate_titles( string $context, int $n = 1 ) {
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
