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
	 * Legacy settings page slug.
	 * TODO: either once [0.6.0 is less than 10% of installs](https://wordpress.org/plugins/ai/advanced/) or we're in October 2026 let's remove this section in case other plugin(s) are attempting to use the `ai` page.
	 *
	 * @since 0.8.0
	 *
	 * @var string
	 */
	private const LEGACY_PAGE_SLUG = 'ai';

	/**
	 * The settings page slug.
	 *
	 * @since 0.7.0
	 *
	 * @var string
	 */
	private const PAGE_SLUG = 'ai-wp-admin';

	/**
	 * Initializes the settings page hooks.
	 *
	 * @since 0.7.0
	 *
	 * @param \WordPress\AI\Features\Registry $registry The feature registry.
	 * @return void
	 */
	public static function init( Registry $registry ): void {
		add_action( 'admin_init', array( self::class, 'maybe_redirect_legacy_page' ), 1 );
		add_action( 'admin_page_access_denied', array( self::class, 'maybe_redirect_legacy_page' ) );

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
						'0.7.0'
					);
				}
			);
		}
	}

	/**
	 * Redirects legacy settings page slug to the current settings route.
	 *
	 * @since 0.8.0
	 */
	public static function maybe_redirect_legacy_page(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading query param for admin page detection only, no data processing.
		if ( ! isset( $_GET['page'] ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading query param for admin page detection only, no data processing.
		$page = sanitize_key( wp_unslash( (string) $_GET['page'] ) );
		if ( self::LEGACY_PAGE_SLUG !== $page ) {
			return;
		}

		$redirect_url = add_query_arg(
			'page',
			self::PAGE_SLUG,
			admin_url( 'options-general.php' )
		);

		if ( wp_safe_redirect( $redirect_url, 301, 'WordPress AI plugin' ) ) {
			exit;
		}
	}

	/**
	 * Gets feature group metadata for the settings UI.
	 *
	 * @since 0.8.0
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
	 * @since 0.8.0
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
				'stability'      => $feature->get_stability(),
				'image'          => esc_url( $feature->get_image() ),
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
		 * @since 0.7.0
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
