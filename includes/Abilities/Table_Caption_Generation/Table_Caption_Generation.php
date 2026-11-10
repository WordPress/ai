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
		$cell_schema = array(
			'type'                 => 'object',
			'required'             => array( 'content' ),
			'additionalProperties' => false,
			'properties'           => array(
				'content' => array(
					'type' => 'string',
				),
				'tag'     => array(
					'type' => 'string',
					'enum' => array( 'td', 'th' ),
				),
				'scope'   => array(
					'type' => 'string',
					'enum' => array( 'row', 'col', 'rowgroup', 'colgroup' ),
				),
				'colspan' => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
				'rowspan' => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
			),
		);
		$rows_schema = array(
			'type'  => 'array',
			'items' => array(
				'type'  => 'array',
				'items' => $cell_schema,
			),
		);

		return array(
			'type'                 => 'object',
			'required'             => array( 'post_id', 'table' ),
			'additionalProperties' => false,
			'properties'           => array(
				'post_id' => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'description' => __( 'The post containing the table.', 'ai' ),
				),
				'table'   => array(
					'type'                 => 'object',
					'required'             => array( 'head', 'body', 'foot' ),
					'additionalProperties' => false,
					'properties'           => array_fill_keys( array( 'head', 'body', 'foot' ), $rows_schema ),
					'description'          => __( 'Header, body, and footer rows, each containing cells with text and optional header/scope/span information.', 'ai' ),
				),
				'title'   => array(
					'type'        => 'string',
					'description' => __( 'The current post title, including unsaved edits. Defaults to the saved title.', 'ai' ),
				),
				'context' => array(
					'type'        => 'string',
					'description' => __( 'Text immediately before and after the table.', 'ai' ),
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
			'type'        => 'string',
			'minLength'   => 1,
			'description' => __( 'A plain-text caption for editorial review.', 'ai' ),
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
		if ( ! $post ) {
			return new WP_Error( 'post_not_found', __( 'The post could not be found.', 'ai' ) );
		}
		if ( ! current_user_can( 'edit_post', $post->ID ) ) {
			return new WP_Error( 'insufficient_permissions', __( 'You do not have permission to generate a caption for this post.', 'ai' ) );
		}
		$post_type = get_post_type_object( $post->post_type );
		if ( ! $post_type || ! $post_type->show_in_rest || ! post_type_supports( $post->post_type, 'editor' ) ) {
			return new WP_Error( 'unsupported_post_type', __( 'This post type does not support table caption generation.', 'ai' ) );
		}
		return true;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since x.x.x
	 */
	protected function execute_callback( $input ) {
		$post = get_post( $input['post_id'] );
		if ( ! $post ) {
			return new WP_Error( 'post_not_found', __( 'The post no longer exists.', 'ai' ) );
		}
		$table = $input['table'];

		$has_text = false;
		foreach ( $table as &$rows ) {
			foreach ( $rows as &$cells ) {
				foreach ( $cells as &$cell ) {
					$cell['content'] = normalize_content( $cell['content'] );
					$has_text        = $has_text || '' !== trim( $cell['content'] );
				}
				unset( $cell );
			}
			unset( $cells );
		}
		unset( $rows );
		if ( ! $has_text ) {
			return new WP_Error( 'empty_table', __( 'Add text to the table before generating a caption.', 'ai' ) );
		}

		$data   = array(
			'table'   => $table,
			'title'   => normalize_content( $input['title'] ?? $post->post_title ),
			'context' => normalize_content( $input['context'] ?? '' ),
		);
		$prompt = sprintf(
			"<table-data>\n%s\n</table-data>\n<post-title>%s</post-title>\n<surrounding-text>\n%s\n</surrounding-text>",
			htmlspecialchars( (string) wp_json_encode( $table ), ENT_XML1 | ENT_QUOTES, 'UTF-8' ),
			htmlspecialchars( $data['title'], ENT_XML1 | ENT_QUOTES, 'UTF-8' ),
			htmlspecialchars( $data['context'], ENT_XML1 | ENT_QUOTES, 'UTF-8' )
		);
		$prompt = $this->filter_prompt( $prompt, $data );
		$result = $this->generate_caption( $prompt );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$caption = trim( sanitize_plain_text( html_entity_decode( $result, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ), " \t\n\r\0\x0B\"'" );
		if ( '' === $caption ) {
			return new WP_Error( 'no_results', __( 'No caption was generated. Please try again.', 'ai' ) );
		}
		return $caption;
	}

	/**
	 * Generates one caption with the configured text provider.
	 *
	 * @since x.x.x
	 *
	 * @param string $prompt Assembled user prompt.
	 * @return string|\WP_Error Generated caption or provider error.
	 */
	protected function generate_caption( string $prompt ) {
		$builder = wp_ai_client_prompt( $prompt )->using_system_instruction( $this->get_system_instruction() );
		$builder = $this->filter_prompt_builder( $builder, Experiment::class, array(), $prompt );
		$builder = $this->ensure_text_generation_supported( $builder, __( 'Connect a provider that supports text generation in Settings → Connectors.', 'ai' ) );
		if ( is_wp_error( $builder ) ) {
			return $builder;
		}
		return $builder->generate_text();
	}
}
