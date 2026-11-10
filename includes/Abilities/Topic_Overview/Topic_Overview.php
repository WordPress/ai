<?php
/**
 * Generates grounded topic sections without publishing or storing them.
 *
 * @package WordPress\AI
 * @since x.x.x
 */

declare( strict_types=1 );

namespace WordPress\AI\Abilities\Topic_Overview;

use WP_Error;
use WordPress\AI\Abstracts\Abstract_Ability;
use WordPress\AI\Experiments\Topic_Pages\Sources;
use WordPress\AI\Experiments\Topic_Pages\Store;
use WordPress\AI\Experiments\Topic_Pages\Topic_Pages;

/**
 * Reusable generation ability, separate from editorial persistence.
 *
 * @since x.x.x
 */
class Topic_Overview extends Abstract_Ability {

	/**
	 * Returns the editorial guideline categories.
	 *
	 * @since x.x.x
	 *
	 * @return string[] Editorial guideline categories.
	 */
	protected function guideline_categories(): array {
		return array( 'site', 'copy' );
	}

	/**
	 * Defines the generation input.
	 *
	 * @since x.x.x
	 *
	 * @return array<string,mixed> Ability input schema.
	 */
	protected function input_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'term_id'  => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'description' => __( 'The ID of the category to generate sections for.', 'ai' ),
				),
				'sections' => array(
					'type'        => 'array',
					'description' => __( 'The topic sections to generate.', 'ai' ),
					'items'       => array(
						'type' => 'string',
						'enum' => Store::SECTIONS,
					),
					'minItems'    => 1,
					'maxItems'    => count( Store::SECTIONS ),
					'uniqueItems' => true,
					'default'     => Store::SECTIONS,
				),
			),
			'required'             => array( 'term_id' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * Defines the generated section output.
	 *
	 * @since x.x.x
	 *
	 * @return array<string,mixed> Ability output schema.
	 */
	protected function output_schema(): array {
		$properties = array();

		foreach ( Store::SECTIONS as $section ) {
			$properties[ $section ] = array(
				'type'                 => 'object',
				'properties'           => array(
					'content'      => array(
						'type'        => 'string',
						'description' => __( 'Generated block markup for the section.', 'ai' ),
					),
					'fingerprint'  => array(
						'type'        => 'string',
						'description' => __( 'Hash of the source records used during generation.', 'ai' ),
					),
					'generated_at' => array(
						'type'        => 'string',
						'description' => __( 'ISO 8601 timestamp when the section was generated.', 'ai' ),
					),
					'source_ids'   => array(
						'type'        => 'array',
						'description' => __( 'IDs of the posts used as sources for the section.', 'ai' ),
						'items'       => array( 'type' => 'integer' ),
					),
				),
				'required'             => array( 'content', 'source_ids', 'fingerprint', 'generated_at' ),
				'additionalProperties' => false,
			);
		}

		return array(
			'type'                 => 'object',
			'properties'           => $properties,
			'additionalProperties' => false,
		);
	}

	/**
	 * Returns the ability exposure metadata.
	 *
	 * @since x.x.x
	 *
	 * @return array<string,mixed> REST exposure metadata.
	 */
	protected function meta(): array {
		return array( 'show_in_rest' => true );
	}

	/**
	 * Check term management permissions.
	 *
	 * @since x.x.x
	 *
	 * @param mixed $input Ability input.
	 * @return bool|\WP_Error
	 */
	protected function permission_callback( $input ) {
		$term = ( new Sources() )->term( absint( $input['term_id'] ?? 0 ) );

		if ( is_wp_error( $term ) ) {
			return $term;
		}

		$taxonomy = get_taxonomy( $term->taxonomy );
		// phpcs:ignore WordPress.WP.Capabilities.Undetermined -- WordPress supplies the registered taxonomy capability mapping.
		return $taxonomy && current_user_can( $taxonomy->cap->manage_terms ) && current_user_can( 'edit_term', $term->term_id );
	}

	/**
	 * Generate sections without changing editorial state.
	 *
	 * @since x.x.x
	 *
	 * @param mixed $input Ability input.
	 * @return array<string,mixed>|\WP_Error
	 */
	protected function execute_callback( $input ) {
		$term_id  = absint( $input['term_id'] );
		$sections = $input['sections'] ?? Store::SECTIONS;
		$selector = new Sources();
		$term     = $selector->eligible_term( $term_id );

		if ( is_wp_error( $term ) ) {
			return $term;
		}

		Sources::invalidate( array( $term_id ), $term->taxonomy );
		$versions = array();

		foreach ( $sections as $section ) {
			if ( ! in_array( $section, Store::SECTIONS, true ) ) {
				return new WP_Error( 'topic_invalid_section', __( 'Choose Summary or Overview.', 'ai' ) );
			}

			$sources = $selector->collect_for_term( $term, $section );

			if ( is_wp_error( $sources ) ) {
				return $sources;
			}

			$context = array(
				'topic'    => $term->name,
				'sections' => array( $section ),
				'sources'  => $sources,
			);
			$prompt  = $this->filter_prompt(
				'Write the requested section using only these article excerpts. Return the required JSON object.' .
				"\n\n" . wp_json_encode( $context ),
				$context
			);
			$builder = wp_ai_client_prompt( $prompt )
				->using_system_instruction( $this->get_system_instruction() )
				->as_json_response( $this->response_schema( array( $section ) ) );
			$builder = $this->filter_prompt_builder( $builder, Topic_Pages::class, array(), $context );
			$builder = $this->ensure_text_generation_supported( $builder, __( 'Connect a text-generation provider in Settings → AI.', 'ai' ) );

			if ( is_wp_error( $builder ) ) {
				return $builder;
			}

			$result = $builder->generate_text();

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			$prepared = $this->prepare_sections( json_decode( $result, true ), array( $section ), $sources );

			if ( is_wp_error( $prepared ) ) {
				return $prepared;
			}

			$versions = array_merge( $versions, $prepared );
		}

		return $versions;
	}

	/**
	 * Define the provider response for the requested sections.
	 *
	 * @since x.x.x
	 *
	 * @param string[] $sections Requested sections.
	 * @return array<string,mixed>
	 */
	private function response_schema( array $sections ): array {
		$properties = array();

		foreach ( $sections as $section ) {
			$properties[ $section ] = array(
				'type'     => 'array',
				'minItems' => Store::SUMMARY === $section ? 1 : 2,
				'maxItems' => Store::SUMMARY === $section ? 1 : 2,
				'items'    => array(
					'type'                 => 'object',
					'properties'           => array(
						'text'       => array( 'type' => 'string' ),
						'source_ids' => array(
							'type'     => 'array',
							'items'    => array( 'type' => 'integer' ),
							'minItems' => 1,
						),
					),
					'required'             => array( 'text', 'source_ids' ),
					'additionalProperties' => false,
				),
			);
		}

		return array(
			'type'                 => 'object',
			'properties'           => $properties,
			'required'             => $sections,
			'additionalProperties' => false,
		);
	}

	/**
	 * Reject unknown sources and create static paragraphs with real permalinks.
	 *
	 * @since x.x.x
	 *
	 * @param mixed $decoded Provider response.
	 * @param string[] $sections Requested sections.
	 * @param array<int,array<string,mixed>> $sources Source records sent to the provider.
	 * @return array<string,mixed>|\WP_Error
	 */
	private function prepare_sections( $decoded, array $sections, array $sources ) {
		$valid = rest_validate_value_from_schema( $decoded, $this->response_schema( $sections ), 'topic' );

		if ( is_wp_error( $valid ) ) {
			return new WP_Error( 'topic_invalid_response', __( 'The provider returned invalid topic content. Your saved content has not changed.', 'ai' ) );
		}

		$by_id    = array_column( $sources, null, 'id' );
		$versions = array();

		foreach ( $sections as $section ) {
			$entries = $decoded[ $section ];

			foreach ( $entries as $entry ) {
				if ( '' === trim( $entry['text'] ) || array_diff( $entry['source_ids'], array_keys( $by_id ) ) ) {
					return new WP_Error( 'topic_invalid_sources', __( 'The provider returned an empty section or an unknown source. Please regenerate.', 'ai' ) );
				}
			}

			$content = '';

			foreach ( $entries as $entry ) {
				$links = array();

				foreach ( array_unique( $entry['source_ids'] ) as $id ) {
					$links[] = '<a class="wpai-topic-source-link" data-wpai-source-id="' . $id . '" href="' .
						esc_url( (string) get_permalink( $id ) ) . '">' .
						esc_html( $by_id[ $id ]['title'] ) . '</a>';
				}

				$citation_html = ! empty( $links )
					? ' <cite class="wpai-topic-citation">' . esc_html__( 'Sources:', 'ai' ) . ' ' . implode( ', ', $links ) . '</cite>'
					: '';
				$content      .= '<!-- wp:paragraph --><p>' . esc_html( $entry['text'] ) . $citation_html . '</p><!-- /wp:paragraph -->' . "\n";
			}

			// All supplied sources may influence a synthesis, including uncited context.
			$versions[ $section ] = array(
				'content'      => $content,
				'source_ids'   => array_keys( $by_id ),
				'fingerprint'  => Sources::fingerprint( $sources ),
				'generated_at' => gmdate( 'c' ),
			);
		}

		return $versions;
	}
}
