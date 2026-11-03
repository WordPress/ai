<?php
/**
 * Command palette AI search WordPress Ability implementation.
 *
 * @package WordPress\AI
 */

declare( strict_types=1 );

namespace WordPress\AI\Abilities\Command_Palette_AI_Search;

use WP_Error;
use WordPress\AI\Abstracts\Abstract_Ability;
use WordPress\AI\Experiments\Command_Palette_AI_Search\Command_Palette_AI_Search as Command_Palette_AI_Search_Experiment;

/**
 * Command palette AI search WordPress Ability.
 *
 * @since x.x.x
 */
class Command_Palette_AI_Search extends Abstract_Ability {

	/**
	 * Most commands returned.
	 *
	 * @since x.x.x
	 *
	 * @var int
	 */
	private const MAX_RESULTS = 3;

	/**
	 * {@inheritDoc}
	 *
	 * @since x.x.x
	 */
	protected function input_schema(): array {
		return array(
			'type'       => 'object',
			'required'   => array( 'query', 'commands' ),
			'properties' => array(
				'query'    => array(
					'type'              => 'string',
					'minLength'         => 3,
					'maxLength'         => 200,
					'sanitize_callback' => 'sanitize_text_field',
					'description'       => esc_html__( 'What the user typed into the command palette.', 'ai' ),
				),
				'commands' => array(
					'type'        => 'array',
					'minItems'    => 1,
					'maxItems'    => 500,
					'description' => esc_html__( 'The commands registered in the command palette.', 'ai' ),
					'items'       => array(
						'type'       => 'object',
						'required'   => array( 'name', 'label' ),
						'properties' => array(
							'name'     => array(
								'type'      => 'string',
								'maxLength' => 200,
							),
							'label'    => array(
								'type'      => 'string',
								'maxLength' => 300,
							),
							'keywords' => array(
								'type'     => 'array',
								'maxItems' => 30,
								'items'    => array(
									'type'      => 'string',
									'maxLength' => 100,
								),
							),
						),
					),
				),
			),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since x.x.x
	 */
	protected function output_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'commands' => array(
					'type'        => 'array',
					'items'       => array(
						'type' => 'string',
					),
					'description' => esc_html__( 'Names of the commands that answer the search, best first. Empty when none does.', 'ai' ),
				),
			),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since x.x.x
	 */
	protected function execute_callback( $input ) {
		$query    = sanitize_text_field( (string) ( $input['query'] ?? '' ) );
		$commands = array_values( (array) ( $input['commands'] ?? array() ) );

		if ( '' === $query || ! $commands ) {
			return new WP_Error(
				'insufficient_data',
				esc_html__( 'A search and at least one command are required.', 'ai' )
			);
		}

		// List each command as an ID the model can return, so it never has to repeat a name.
		$lines = array();
		foreach ( $commands as $index => $command ) {
			$keywords = array_filter( array_map( 'sanitize_text_field', (array) ( $command['keywords'] ?? array() ) ) );
			$lines[]  = sprintf(
				'c%d: %s%s',
				$index,
				sanitize_text_field( (string) ( $command['label'] ?? '' ) ),
				$keywords ? ' (' . implode( ', ', $keywords ) . ')' : ''
			);
		}

		$prompt  = "<search>{$query}</search>\n\n";
		$prompt .= '<theme>' . ( wp_is_block_theme() ? 'Block theme: the Site Editor is available, the Customizer is not.' : 'Classic theme: the Customizer is available.' ) . "</theme>\n\n";
		$prompt .= "<commands>\n" . implode( "\n", $lines ) . "\n</commands>";
		$prompt  = $this->filter_prompt( $prompt, $query, $commands );

		$ids = $this->choose_commands( $prompt, count( $commands ) );

		if ( is_wp_error( $ids ) ) {
			return $ids;
		}

		$names = array();
		foreach ( $ids as $id ) {
			if ( ! preg_match( '/^c(\d+)$/', (string) $id, $match ) || ! isset( $commands[ (int) $match[1] ]['name'] ) ) {
				continue;
			}

			$names[] = (string) $commands[ (int) $match[1] ]['name'];
		}

		return array(
			'commands' => array_slice( array_values( array_unique( $names ) ), 0, self::MAX_RESULTS ),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since x.x.x
	 */
	protected function permission_callback( $input ) {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return new WP_Error(
				'insufficient_capabilities',
				esc_html__( 'You do not have permission to search admin commands.', 'ai' )
			);
		}

		return true;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since x.x.x
	 */
	protected function meta(): array {
		return array(
			'show_in_rest' => true,
		);
	}

	/**
	 * Asks the model which commands answer the search.
	 *
	 * @since x.x.x
	 *
	 * @param string $prompt The prompt.
	 * @param int    $count  Number of commands in the prompt.
	 * @return list<string>|\WP_Error Command IDs such as `c4`, best first, or WP_Error.
	 */
	protected function choose_commands( string $prompt, int $count ) {
		$prompt_builder = $this->get_prompt_builder( $prompt )->as_json_response( $this->commands_schema( $count ) );

		// Not every provider supports structured output; the system instruction also asks for JSON.
		if ( ! $prompt_builder->is_supported_for_text_generation() ) {
			$prompt_builder = $this->get_prompt_builder( $prompt );
		}

		$prompt_builder = $this->ensure_text_generation_supported(
			$prompt_builder,
			esc_html__( 'Command palette AI search failed. Please ensure you have a connected provider that supports text generation.', 'ai' )
		);

		if ( is_wp_error( $prompt_builder ) ) {
			return $prompt_builder;
		}

		$response = $prompt_builder->generate_text();

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return $this->parse_commands( (string) $response );
	}

	/**
	 * Decodes the command IDs returned by the model.
	 *
	 * @since x.x.x
	 *
	 * @param string $response The raw response, which may wrap the JSON in other text.
	 * @return list<string>|\WP_Error The command IDs, or WP_Error if the response could not be parsed.
	 */
	private function parse_commands( string $response ) {
		$decoded = preg_match( '/\{.*\}/s', $response, $json ) ? json_decode( $json[0], true ) : null;

		if ( ! is_array( $decoded ) || ! isset( $decoded['commands'] ) || ! is_array( $decoded['commands'] ) ) {
			return new WP_Error(
				'invalid_response',
				esc_html__( 'Could not parse the AI response as a list of commands.', 'ai' )
			);
		}

		return array_values( array_filter( $decoded['commands'], 'is_string' ) );
	}

	/**
	 * Gets a prompt builder for choosing commands.
	 *
	 * @since x.x.x
	 *
	 * @param string $prompt The prompt.
	 * @return \WP_AI_Client_Prompt_Builder The prompt builder.
	 */
	private function get_prompt_builder( string $prompt ): \WP_AI_Client_Prompt_Builder {
		$prompt_builder = wp_ai_client_prompt( $prompt )
			->using_system_instruction( $this->get_system_instruction( null, array( 'max_results' => self::MAX_RESULTS ) ) );

		return $this->filter_prompt_builder( $prompt_builder, Command_Palette_AI_Search_Experiment::class, array(), $prompt );
	}

	/**
	 * Returns the JSON schema the model must respond with.
	 *
	 * @since x.x.x
	 *
	 * @param int $count Number of commands in the prompt.
	 * @return array<string, mixed> The response schema.
	 */
	protected function commands_schema( int $count ): array {
		$ids = array();
		for ( $index = 0; $index < $count; $index++ ) {
			$ids[] = 'c' . $index;
		}

		return array(
			'type'                 => 'object',
			'properties'           => array(
				'commands' => array(
					'type'     => 'array',
					'maxItems' => self::MAX_RESULTS,
					'items'    => array(
						'type' => 'string',
						'enum' => $ids,
					),
				),
			),
			'required'             => array( 'commands' ),
			'additionalProperties' => false,
		);
	}
}
