<?php
/**
 * Job type that generates and saves alt text for an image.
 *
 * @package WordPress\AI\Jobs\Job_Types
 */

declare( strict_types=1 );

namespace WordPress\AI\Jobs\Job_Types;

use WP_Error;
use WordPress\AI\Jobs\Job;
use WordPress\AI\Jobs\Job_Type_Interface;

defined( 'ABSPATH' ) || exit;

/**
 * Generates alt text for an attachment and stores it.
 *
 * The heavy vision request runs in the background. The job resolves the
 * registered alt text ability, re-checks its permission callback, and writes
 * the result to the attachment unless the image is decorative, in which case
 * the existing alt text is left alone.
 *
 * @since 1.6.0
 */
class Alt_Text_Job_Type implements Job_Type_Interface {

	/**
	 * Job type ID.
	 *
	 * @since 1.6.0
	 *
	 * @var string
	 */
	public const ID = 'ai/alt-text';

	/**
	 * Ability used to generate the alt text.
	 *
	 * @since 1.6.0
	 *
	 * @var string
	 */
	public const ABILITY = 'ai/alt-text-generation';

	/**
	 * {@inheritDoc}
	 */
	public function get_id(): string {
		return self::ID;
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_label(): string {
		return __( 'Generate alt text for an image', 'ai' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_input_schema(): array {
		return array(
			'type'       => 'object',
			'required'   => array( 'attachment_id' ),
			'properties' => array(
				'attachment_id' => array(
					'type'        => 'integer',
					'description' => __( 'Attachment ID of the image.', 'ai' ),
				),
				'context'       => array(
					'type'        => 'string',
					'description' => __( 'Optional context about how the image is used.', 'ai' ),
				),
			),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function can_enqueue( array $args, int $user_id ) {
		$attachment_id = isset( $args['attachment_id'] ) ? absint( $args['attachment_id'] ) : 0;
		$attachment    = $attachment_id > 0 ? get_post( $attachment_id ) : null;

		if ( ! $attachment || 'attachment' !== $attachment->post_type || ! wp_attachment_is_image( $attachment ) ) {
			return new WP_Error(
				'wpai_alt_text_invalid_attachment',
				__( 'A valid image attachment is required.', 'ai' )
			);
		}

		if ( ! user_can( $user_id, 'upload_files' ) || ! user_can( $user_id, 'edit_post', $attachment_id ) ) {
			return new WP_Error(
				'wpai_alt_text_forbidden',
				__( 'You do not have permission to generate alt text for this image.', 'ai' )
			);
		}

		return true;
	}

	/**
	 * {@inheritDoc}
	 */
	public function handle( array $args, Job $job ) {
		$allowed = $this->can_enqueue( $args, $job->get_user_id() );

		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}

		$attachment_id = absint( $args['attachment_id'] );
		$context       = isset( $args['context'] ) && is_string( $args['context'] ) ? $args['context'] : '';

		$ability = $this->get_ability();

		if ( is_wp_error( $ability ) ) {
			return $ability;
		}

		$input   = array(
			'attachment_id' => $attachment_id,
			'context'       => $context,
		);
		$allowed = $ability->check_permissions( $input );

		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}

		if ( true !== $allowed ) {
			return new WP_Error(
				'wpai_alt_text_forbidden',
				__( 'The enqueuing user is no longer allowed to generate alt text for this image.', 'ai' )
			);
		}

		$result = $ability->execute( $input );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( ! is_array( $result ) ) {
			return new WP_Error(
				'wpai_alt_text_invalid_result',
				__( 'Alt text generation returned an unexpected result.', 'ai' )
			);
		}

		$alt_text      = isset( $result['alt_text'] ) && is_string( $result['alt_text'] ) ? $result['alt_text'] : '';
		$is_decorative = ! empty( $result['is_decorative'] );
		$previous      = (string) get_post_meta( $attachment_id, '_wp_attachment_image_alt', true );
		$saved         = false;

		// A decorative image is correctly represented by empty alt text. Do not
		// erase hand-written alt text when the model says the image is decorative.
		if ( ! $is_decorative ) {
			update_post_meta( $attachment_id, '_wp_attachment_image_alt', $alt_text );
			$saved = true;
		}

		return array(
			'attachment_id'     => $attachment_id,
			'alt_text'          => $alt_text,
			'is_decorative'     => $is_decorative,
			'saved'             => $saved,
			'previous_alt_text' => $previous,
		);
	}

	/**
	 * Resolves the alt text generation ability.
	 *
	 * @since 1.6.0
	 *
	 * @return \WP_Ability|\WP_Error The ability, or a WP_Error.
	 */
	private function get_ability() {
		/**
		 * Filters the ability the alt text job uses.
		 *
		 * @since 1.6.0
		 *
		 * @param string $ability Ability name. Default ai/alt-text-generation.
		 */
		$name = (string) apply_filters( 'wpai_alt_text_job_ability', self::ABILITY );

		if ( ! function_exists( 'wp_get_ability' ) ) {
			return new WP_Error(
				'wpai_abilities_unavailable',
				__( 'The Abilities API is not available.', 'ai' )
			);
		}

		$ability = wp_get_ability( $name );

		if ( ! $ability instanceof \WP_Ability ) {
			return new WP_Error(
				'wpai_alt_text_ability_missing',
				/* translators: %s: Ability name. */
				sprintf( __( 'The %s ability is not registered.', 'ai' ), $name )
			);
		}

		return $ability;
	}
}
