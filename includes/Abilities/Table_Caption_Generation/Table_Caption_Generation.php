<?php
/**
 * Table caption generation ability.
 *
 * @package WordPress\AI
 */

declare( strict_types=1 );

namespace WordPress\AI\Abilities\Table_Caption_Generation;

use WP_Error;
use WordPress\AI\Abstracts\Abstract_Ability;
use WordPress\AI\Experiments\Table_Caption_Generation\Table_Caption_Generation as Experiment;

use function WordPress\AI\normalize_content;
use function WordPress\AI\sanitize_plain_text;

/**
 * Generates a caption without saving or modifying a block.
 *
 * @since x.x.x
 */
class Table_Caption_Generation extends Abstract_Ability {
	/**
	 * {@inheritDoc}
	 *
	 * @since x.x.x
	 */
	protected function guideline_categories(): array {
		return array( 'site', 'copy' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since x.x.x
	 */
	protected function input_schema(): array {
		return array(
			'type'                 => 'object',
			'required'             => array( 'post_id', 'table' ),
			'additionalProperties' => false,
			'properties'           => array(
				'post_id' => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
				'table'   => array(
					'type'        => 'string',
					'maxLength'   => 20000,
					'description' => __( 'JSON containing head, body, and foot arrays of rows of cell text.', 'ai' ),
				),
				'title'   => array(
					'type'      => 'string',
					'maxLength' => 300,
				),
				'context' => array(
					'type'      => 'string',
					'maxLength' => 2000,
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
			'type'      => 'string',
			'minLength' => 1,
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since x.x.x
	 */
	protected function meta(): array {
		return array( 'show_in_rest' => true );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since x.x.x
	 */
	protected function permission_callback( $input ) {
		$post = get_post( $input['post_id'] );
		if ( ! $post || ! current_user_can( 'edit_post', $post->ID ) ) {
			return new WP_Error( 'insufficient_permissions', __( 'You do not have permission to generate a caption for this post.', 'ai' ) );
		}
		$post_type = get_post_type_object( $post->post_type );
		return $post_type && $post_type->show_in_rest;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since x.x.x
	 */
	protected function execute_callback( $input ) {
		$table       = json_decode( $input['table'], true );
		$rows_schema = array(
			'type'     => 'array',
			'maxItems' => 50,
			'items'    => array(
				'type'     => 'array',
				'maxItems' => 20,
				'items'    => array( 'type' => 'string' ),
			),
		);
		$valid       = rest_validate_value_from_schema(
			$table,
			array(
				'type'                 => 'object',
				'required'             => array( 'head', 'body', 'foot' ),
				'additionalProperties' => false,
				'properties'           => array_fill_keys( array( 'head', 'body', 'foot' ), $rows_schema ),
			),
			'table'
		);
		if ( is_wp_error( $valid ) ) {
			return new WP_Error( 'invalid_table', __( 'Use a table with at most 50 rows per section and 20 columns.', 'ai' ) );
		}

		$has_text = false;
		foreach ( $table as &$rows ) {
			foreach ( $rows as &$cells ) {
				foreach ( $cells as &$cell ) {
					$cell     = normalize_content( $cell );
					$has_text = $has_text || '' !== trim( $cell );
				}
				unset( $cell );
			}
			unset( $cells );
		}
		unset( $rows );
		if ( ! $has_text ) {
			return new WP_Error( 'empty_table', __( 'Add text to the table before generating a caption.', 'ai' ) );
		}

		$post = get_post( $input['post_id'] );
		if ( ! $post ) {
			return new WP_Error( 'post_not_found', __( 'The post no longer exists.', 'ai' ) );
		}
		$data    = array(
			'table'   => $table,
			'title'   => normalize_content( $input['title'] ?? mb_substr( $post->post_title, 0, 300 ) ),
			'context' => normalize_content( $input['context'] ?? '' ),
		);
		$prompt  = $this->filter_prompt( "Write a caption for the table in this reference data:\n" . wp_json_encode( $data ), $data );
		$builder = wp_ai_client_prompt( $prompt )->using_system_instruction( $this->get_system_instruction() );
		$builder = $this->filter_prompt_builder( $builder, Experiment::class, array(), $prompt );
		$builder = $this->ensure_text_generation_supported( $builder, __( 'Connect a provider that supports text generation in Settings → Connectors.', 'ai' ) );
		if ( is_wp_error( $builder ) ) {
			return $builder;
		}
		$result = $builder->generate_text();
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$caption = trim( sanitize_plain_text( $result ), " \t\n\r\0\x0B\"'" );
		if ( '' === $caption ) {
			return new WP_Error( 'no_results', __( 'No caption was generated. Please try again.', 'ai' ) );
		}
		return $caption;
	}
}
