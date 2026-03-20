<?php
/**
 * Handle deprecated code.
 *
 * @package WordPress\AI
 *
 * @since 0.6.0
 */

declare( strict_types=1 );

namespace WordPress\AI;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Handle deprecated code.
 *
 * @internal
 *
 * @since 0.6.0
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
					'0.6.0',
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
					'0.6.0',
					'wpai_normalize_content',
					esc_html__( 'This filter will be removed in v1.0', 'ai' )
				);
				return $content;
			},
		);

		// @todo remove in v1.0.
		add_filter(
			'wpai_preferred_text_models',
			static function ( $models ) {
				if ( ! has_filter( 'ai_experiments_preferred_models_for_text_generation' ) ) {
					return $models;
				}

				$models = (array) apply_filters_deprecated(
					'ai_experiments_preferred_models_for_text_generation',
					array( $models ),
					'0.6.0',
					'wpai_preferred_text_models',
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
					'0.6.0',
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
					'0.6.0',
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
					'0.6.0',
					'wpai_pre_has_valid_credentials_check',
					esc_html__( 'This filter will be removed in v1.0', 'ai' )
				);
				return $valid;
			}
		);

		// @todo remove in v1.0.
		add_filter(
			'wpai_features_enabled',
			static function ( $enabled ) {
				if ( ! has_filter( 'ai_experiments_enabled' ) ) {
					return $enabled;
				}

				$enabled = (bool) apply_filters_deprecated(
					'ai_experiments_enabled',
					array( $enabled ),
					'0.6.0',
					'wpai_features_enabled',
					esc_html__( 'This filter will be removed in v1.0', 'ai' )
				);
				return $enabled;
			}
		);

		// @todo remove in v1.0.
		add_action(
			'wpai_register_features',
			static function ( $registry ) {
				if ( ! has_action( 'ai_experiments_register_experiments' ) ) {
					return;
				}
				do_action_deprecated(
					'ai_experiments_register_experiments',
					array( $registry ),
					'0.6.0',
					'wpai_register_features',
					esc_html__( 'This action will be removed in v1.0', 'ai' )
				);
			},
		);

		// @todo remove in v1.0.
		add_action(
			'wpai_features_initialized',
			static function () {
				if ( ! has_action( 'ai_experiments_initialized' ) ) {
					return;
				}

				do_action_deprecated(
					'ai_experiments_initialized',
					array(),
					'0.6.0',
					'wpai_features_initialized',
					esc_html__( 'This action will be removed in v1.0', 'ai' )
				);
			},
		);
	}
}
