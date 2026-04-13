<?php
/**
 * Settings page for the AI plugin.
 *
 * @package WordPress\AI
 *
 * @since 0.1.0
 */

declare( strict_types=1 );

namespace WordPress\AI\Settings;

use WordPress\AI\Experiments\Experiment_Category;
use WordPress\AI\Features\Feature_Category;
use WordPress\AI\Features\Registry;
use function WordPress\AI\has_ai_credentials;
use function WordPress\AI\has_valid_ai_credentials;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Manages the admin settings page for the AI plugin.
 *
 * @since 0.1.0
 */
class Settings_Page {

	/**
	 * The settings page slug.
	 *
	 * @since x.x.x
	 *
	 * @var string
	 */
	private const PAGE_SLUG = 'ai-wp-admin';

	/**
	 * Initializes the settings page hooks.
	 *
	 * @since x.x.x
	 *
	 * @param \WordPress\AI\Features\Registry $registry The feature registry.
	 * @return void
	 */
	public static function init( Registry $registry ): void {
		if ( function_exists( 'ai_ai_wp_admin_render_page' ) ) {
			add_action(
				'admin_menu',
				static function () {
					add_options_page(
						__( 'AI', 'ai' ),
						__( 'AI', 'ai' ),
						'manage_options',
						self::PAGE_SLUG,
						'ai_ai_wp_admin_render_page', // @phpstan-ignore argument.type
						2
					);
				}
			);

			// Expose credential status to the settings page script module.
			add_filter(
				'script_module_data_' . self::PAGE_SLUG,
				static function ( array $data ) use ( $registry ): array {
					$feature_metadata            = self::get_settings_feature_metadata( $registry );
					$data['hasCredentials']      = has_ai_credentials();
					$data['hasValidCredentials'] = has_valid_ai_credentials();
					$data['connectorsUrl']       = admin_url( 'options-connectors.php' );
					$data['featureGroups']       = $feature_metadata['groups'] ?? array();
					$data['features']            = $feature_metadata['features'] ?? array();
					return $data;
				}
			);
		} else {
			add_action(
				'admin_menu',
				static function () {
					// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading query param for admin page detection only, no data processing.
					if ( ! isset( $_GET['page'] ) || self::PAGE_SLUG !== $_GET['page'] ) {
						return;
					}

					_doing_it_wrong(
						'initialize_features',
						esc_html__( 'AI settings page render function not found. Run npm run build:routes to generate build assets.', 'ai' ),
						'x.x.x'
					);
				}
			);
		}
	}

	/**
	 * Gets feature group metadata for the settings UI.
	 *
	 * @since x.x.x
	 *
	 * @return array<string, array{
	 *   label:string,
	 *   description:string,
	 *   order:int
	 * }>
	 */
	private static function get_settings_feature_groups(): array {
		$default_groups = array(
			Experiment_Category::EDITOR => array(
				'label'       => __( 'Editor Experiments', 'ai' ),
				'description' => __( 'AI-powered experiments for the block editor, including content generation and enhancement tools.', 'ai' ),
				'order'       => 10,
			),
			Experiment_Category::ADMIN  => array(
				'label'       => __( 'Admin Experiments', 'ai' ),
				'description' => __( 'AI-powered experiments for the WordPress admin area, including exploration and testing tools.', 'ai' ),
				'order'       => 20,
			),
			Feature_Category::OTHER     => array(
				'label'       => __( 'Other Features', 'ai' ),
				'description' => __( 'Additional AI-powered features.', 'ai' ),
				'order'       => 90,
			),
		);

		/**
		 * Filters feature group metadata used by the settings UI.
		 *
		 * @since 0.7.0
		 *
		 * @param array<string, array{
		 *   label:string,
		 *   description:string,
		 *   order:int
		 * }> $default_groups Feature group metadata keyed by category.
		 */
		$filtered_groups = apply_filters( 'wpai_settings_feature_groups', $default_groups );

		return is_array( $filtered_groups ) ? $filtered_groups : $default_groups;
	}

	/**
	 * Builds feature metadata used by the settings route UI.
	 *
	 * @since x.x.x
	 *
	* @param \WordPress\AI\Features\Registry $registry Feature registry instance.
	* @return array{
	*   groups: list<array{
	*     id: non-empty-string,
	*     label: non-empty-string,
	*     description: string
	*   }>,
	*   features: list<array{
	*     id: non-empty-string,
	*     settingName: non-falsy-string,
	*     label: non-empty-string,
	*     description: string,
	*     category: non-empty-string,
	*     settingsFields: array<int, array{
	*       id: string,
	*       label: string,
	*       type: string,
	*       default?: mixed,
	*       elements?: list<array{value: string, label: string}>,
	*       isValid?: array{min?: int, max?: int}
	*     }>
	*   }>
	* }
	 */
	private static function get_settings_feature_metadata( Registry $registry ): array {
		$group_definitions = self::get_settings_feature_groups();
		$categories_in_use = array();
		$features          = array();

		foreach ( $registry->get_all_features() as $feature ) {
			$feature_id = $feature::get_id();
			$category   = $feature->get_category();

			if ( ! is_string( $category ) || '' === $category ) {
				$category = Feature_Category::OTHER;
			}

			if ( ! isset( $group_definitions[ $category ] ) ) {
				$group_definitions[ $category ] = array(
					'label'       => ucwords( str_replace( array( '-', '_' ), ' ', $category ) ),
					'description' => '',
					'order'       => 100,
				);
			}

			$categories_in_use[ $category ] = true;
			$features[]                     = array(
				'id'             => $feature_id,
				'settingName'    => "wpai_feature_{$feature_id}_enabled",
				'label'          => $feature->get_label(),
				'description'    => wp_strip_all_tags( $feature->get_description() ),
				'category'       => $category,
				'settingsFields' => $feature->get_settings_fields_metadata(),
			);
		}

		$groups = array();
		foreach ( array_keys( $categories_in_use ) as $category ) {
			$group = $group_definitions[ $category ] ?? array();

			$groups[] = array(
				'id'          => $category,
				'label'       => isset( $group['label'] ) && is_string( $group['label'] ) && '' !== $group['label']
					? $group['label']
					: ucwords( str_replace( array( '-', '_' ), ' ', $category ) ),
				'description' => isset( $group['description'] ) && is_string( $group['description'] )
					? $group['description']
					: '',
				'order'       => isset( $group['order'] ) ? (int) $group['order'] : 100,
			);
		}

		usort(
			$groups,
			static function ( array $first, array $second ): int {
				if ( $first['order'] === $second['order'] ) {
					return strcasecmp( (string) $first['label'], (string) $second['label'] );
				}

				return $first['order'] <=> $second['order'];
			}
		);

		$groups = array_values(
			array_map(
				static function ( array $group ): array {
					unset( $group['order'] );
					return $group;
				},
				$groups
			)
		);

		$metadata = array(
			'groups'   => $groups,
			'features' => $features,
		);

		/**
		 * Filters settings metadata passed to the settings route client.
		 *
		 * @since x.x.x
		 *
		 * @param array{
		 *   groups: list<array{
		 *     id: non-empty-string,
		 *     label: non-empty-string,
		 *     description: string
		 *   }>,
		 *   features: list<array{
		 *     id: non-empty-string,
		 *     settingName: non-falsy-string,
		 *     label: non-empty-string,
		 *     description: string,
		 *     category: non-empty-string,
		 *     settingsFields: array<int, array{
		 *       id: string,
		 *       label: string,
		 *       type: string,
		 *       default?: mixed,
		 *       elements?: list<array{value: string, label: string}>,
		 *       isValid?: array{min?: int, max?: int}
		 *     }>
		 *   }>
		 * } $metadata Settings UI metadata.
		 * @param \WordPress\AI\Features\Registry $registry Feature registry instance.
		 */
		$filtered_metadata = apply_filters( 'wpai_settings_feature_metadata', $metadata, $registry );

		return is_array( $filtered_metadata ) ? $filtered_metadata : $metadata;
	}
}
