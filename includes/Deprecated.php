<?php
/**
 * Handle deprecated code.
 *
 * @package WordPress\AI
 *
 * @since x.x.x
 */

declare( strict_types=1 );

namespace WordPress\AI;

/**
 * Handle deprecated code.
 *
 * @internal
 *
 * @since x.x.x
 */
final class Deprecated {
	/**
	 * Initialize the class.
	 */
	public function init(): void {
		// @todo remove in v1.0.
		add_filter(
			'wpai_pre_normalize_content',
			static function ( $content ) {
				if ( ! has_filter( 'ai_experiments_pre_normalize_content' ) ) {
					return $content;
				}

				$content = (string) apply_filters_deprecated(
					'ai_experiments_pre_normalize_content',
					array( $content ),
					'x.x.x',
					'wpai_pre_normalize_content',
					esc_html__( 'This filter will be removed in v1.0', 'ai' )
				);
				return $content;
			}
		);

		// @todo remove in v1.0.
		add_filter(
			'wpai_normalize_content',
			static function ( $content ) {
				if ( ! has_filter( 'ai_experiments_normalize_content' ) ) {
					return $content;
				}

				$content = (string) apply_filters_deprecated(
					'ai_experiments_normalize_content',
					array( $content ),
					'x.x.x',
					'wpai_normalize_content',
					esc_html__( 'This filter will be removed in v1.0', 'ai' )
				);
				return $content;
			},
		);

		// @todo remove in v1.0.
		add_filter(
			'wpai_preferred_models_for_text_generation',
			static function ( $models ) {
				if ( ! has_filter( 'ai_experiments_preferred_models_for_text_generation' ) ) {
					return $models;
				}

				$models = (array) apply_filters_deprecated(
					'ai_experiments_preferred_models_for_text_generation',
					array( $models ),
					'x.x.x',
					'wpai_preferred_models_for_text_generation',
					esc_html__( 'This filter will be removed in v1.0', 'ai' )
				);
				return $models;
			}
		);

		// @todo remove in v1.0.
		add_filter(
			'wpai_preferred_image_models',
			static function ( $models ) {
				if ( ! has_filter( 'ai_experiments_preferred_image_models' ) ) {
					return $models;
				}

				$models = (array) apply_filters_deprecated(
					'ai_experiments_preferred_image_models',
					array( $models ),
					'x.x.x',
					'wpai_preferred_image_models',
					esc_html__( 'This filter will be removed in v1.0', 'ai' )
				);
				return $models;
			}
		);

		// @todo remove in v1.0.
		add_filter(
			'wpai_preferred_vision_models',
			static function ( $models ) {
				if ( ! has_filter( 'ai_experiments_preferred_vision_models' ) ) {
					return $models;
				}

				$models = (array) apply_filters_deprecated(
					'ai_experiments_preferred_vision_models',
					array( $models ),
					'x.x.x',
					'wpai_preferred_vision_models',
					esc_html__( 'This filter will be removed in v1.0', 'ai' )
				);
				return $models;
			}
		);

		// @todo remove in v1.0.
		add_filter(
			'wpai_pre_has_valid_credentials_check',
			static function ( $valid ) {
				if ( ! has_filter( 'ai_experiments_pre_has_valid_credentials_check' ) ) {
					return $valid;
				}

				$valid = apply_filters_deprecated(
					'ai_experiments_pre_has_valid_credentials_check',
					array( $valid ),
					'x.x.x',
					'wpai_pre_has_valid_credentials_check',
					esc_html__( 'This filter will be removed in v1.0', 'ai' )
				);
				return $valid;
			}
		);
	}
}
