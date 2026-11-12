<?php
/**
 * Get started guide for new installs.
 *
 * @package WordPress\AI\Onboarding
 *
 * @since x.x.x
 */

declare( strict_types=1 );

namespace WordPress\AI\Onboarding;

use WordPress\AI\Features\Registry;
use WordPress\AI\Settings\Settings_Registration;

use function WordPress\AI\get_ai_connectors;
use function WordPress\AI\has_ai_credentials;
use function WordPress\AI\has_connector_authentication;
use function WordPress\AI\has_image_generation_support;
use function WordPress\AI\has_text_to_speech_support;
use function WordPress\AI\has_valid_ai_credentials;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Holds the state of the Get started guide and passes it to Settings > AI.
 *
 * The guide walks a site owner through three steps: connect an AI provider,
 * choose what AI helps with, and try a tool. Nothing is turned on until the
 * owner chooses it.
 *
 * @since x.x.x
 */
final class Onboarding {

	/**
	 * Option holding the IDs of the chosen use cases and add-ons.
	 *
	 * @since x.x.x
	 *
	 * @var string
	 */
	public const OPTION_USE_CASES = 'wpai_onboarding_use_cases';

	/**
	 * Option recording that the guide's last step was finished or skipped.
	 *
	 * @since x.x.x
	 *
	 * @var string
	 */
	public const OPTION_COMPLETED = 'wpai_onboarding_completed';

	/**
	 * Option set on activation so the welcome notice shows on the Plugins screen.
	 *
	 * @since x.x.x
	 *
	 * @var string
	 */
	public const OPTION_WELCOME = 'wpai_onboarding_welcome';

	/**
	 * User meta recording that this user hid the guide.
	 *
	 * @since x.x.x
	 *
	 * @var string
	 */
	public const META_HIDDEN = 'wpai_onboarding_guide_hidden';

	/**
	 * User meta recording that this user dismissed the welcome notice.
	 *
	 * @since x.x.x
	 *
	 * @var string
	 */
	public const META_WELCOME_DISMISSED = 'wpai_onboarding_welcome_dismissed';

	/**
	 * Slug of the Settings > AI page.
	 *
	 * @since x.x.x
	 *
	 * @var string
	 */
	private const SETTINGS_PAGE_SLUG = 'ai-wp-admin';

	/**
	 * Wapuu sprites the guide uses, by pose.
	 *
	 * @since x.x.x
	 *
	 * @var list<string>
	 */
	private const SPRITES = array( // phpcs:ignore SlevomatCodingStandard.Classes.DisallowMultiConstantDefinition -- This is used as an array const.
		'celebrating',
		'experimenting',
		'juggling',
		'napping',
		'navigating',
		'oops',
		'painting',
		'reading',
		'thinking',
		'working',
		'writing',
	);

	/**
	 * The feature registry.
	 *
	 * @since x.x.x
	 *
	 * @var \WordPress\AI\Features\Registry
	 */
	private Registry $registry;

	/**
	 * The use case definitions.
	 *
	 * @since x.x.x
	 *
	 * @var \WordPress\AI\Onboarding\Use_Cases
	 */
	private Use_Cases $use_cases;

	/**
	 * Constructor.
	 *
	 * @since x.x.x
	 *
	 * @param \WordPress\AI\Features\Registry $registry The feature registry.
	 */
	public function __construct( Registry $registry ) {
		$this->registry  = $registry;
		$this->use_cases = new Use_Cases( $registry );
	}

	/**
	 * Registers the guide's settings and admin hooks.
	 *
	 * @since x.x.x
	 */
	public function init(): void {
		$this->register_settings();

		if ( ! is_admin() ) {
			return;
		}

		add_filter( 'script_module_data_' . self::SETTINGS_PAGE_SLUG, array( $this, 'add_page_data' ) );
		add_action( 'load-settings_page_' . self::SETTINGS_PAGE_SLUG, array( $this, 'clear_welcome_flag' ) );

		( new Guide_Notices( $this ) )->register();
	}

	/**
	 * Flags a newly activated site so the welcome notice shows once.
	 *
	 * @since x.x.x
	 */
	public static function flag_welcome(): void {
		if ( get_option( self::OPTION_COMPLETED, false ) ) {
			return;
		}

		update_option( self::OPTION_WELCOME, '1', false );
	}

	/**
	 * Clears the welcome flag once an administrator has opened Settings > AI.
	 *
	 * @since x.x.x
	 */
	public function clear_welcome_flag(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		delete_option( self::OPTION_WELCOME );
	}

	/**
	 * Registers the guide's site settings and user meta.
	 *
	 * @since x.x.x
	 */
	public function register_settings(): void {
		register_setting(
			Settings_Registration::OPTION_GROUP,
			self::OPTION_USE_CASES,
			array(
				'type'              => 'array',
				'default'           => array(),
				'sanitize_callback' => array( $this, 'sanitize_use_case_ids' ),
				'show_in_rest'      => array(
					'schema' => array(
						'type'  => 'array',
						'items' => array(
							'type' => 'string',
						),
					),
				),
			)
		);

		register_setting(
			Settings_Registration::OPTION_GROUP,
			self::OPTION_COMPLETED,
			array(
				'type'              => 'boolean',
				'default'           => false,
				'sanitize_callback' => 'rest_sanitize_boolean',
				'show_in_rest'      => true,
			)
		);

		register_meta(
			'user',
			self::META_HIDDEN,
			array(
				'type'              => 'boolean',
				'single'            => true,
				'default'           => false,
				'sanitize_callback' => 'rest_sanitize_boolean',
				'show_in_rest'      => true,
				'auth_callback'     => static function (): bool {
					return current_user_can( 'manage_options' );
				},
			)
		);
	}

	/**
	 * Keeps only known use case and add-on IDs.
	 *
	 * @since x.x.x
	 *
	 * @param mixed $value The submitted value.
	 * @return list<string> The sanitized IDs.
	 */
	public function sanitize_use_case_ids( $value ): array {
		if ( ! is_array( $value ) ) {
			return array();
		}

		$allowed = $this->use_cases->get_selectable_ids();
		$ids     = array();

		foreach ( $value as $id ) {
			if ( ! is_string( $id ) || ! in_array( $id, $allowed, true ) || in_array( $id, $ids, true ) ) {
				continue;
			}

			$ids[] = $id;
		}

		return $ids;
	}

	/**
	 * Returns the chosen use case and add-on IDs.
	 *
	 * @since x.x.x
	 *
	 * @return list<string> The chosen IDs.
	 */
	public function get_selected_ids(): array {
		return $this->sanitize_use_case_ids( get_option( self::OPTION_USE_CASES, array() ) );
	}

	/**
	 * Whether the guide's last step was finished or skipped.
	 *
	 * @since x.x.x
	 *
	 * @return bool True when the guide is complete.
	 */
	public function is_completed(): bool {
		return (bool) get_option( self::OPTION_COMPLETED, false );
	}

	/**
	 * Whether the current user hid the guide.
	 *
	 * @since x.x.x
	 *
	 * @return bool True when the guide is hidden for the current user.
	 */
	public function is_hidden_for_current_user(): bool {
		return (bool) get_user_meta( get_current_user_id(), self::META_HIDDEN, true );
	}

	/**
	 * Counts the Features and Experiments that are turned on.
	 *
	 * @since x.x.x
	 *
	 * @return int The number of enabled features.
	 */
	public function count_enabled_features(): int {
		$count = 0;

		foreach ( $this->registry->get_all_features() as $feature ) {
			if ( ! get_option( 'wpai_feature_' . $feature::get_id() . '_enabled', false ) ) {
				continue;
			}

			++$count;
		}

		return $count;
	}

	/**
	 * Whether the guide is still leading the current user through setup.
	 *
	 * True from activation until use cases are chosen. A site that already has
	 * tools turned on without the guide is treated as set up, so its owners are
	 * not walked through setup again.
	 *
	 * @since x.x.x
	 *
	 * @return bool True when setup guidance should be shown outside Settings > AI.
	 */
	public function is_setup_pending_for_current_user(): bool {
		if ( ! current_user_can( 'manage_options' ) ) {
			return false;
		}

		if ( $this->is_completed() || $this->is_hidden_for_current_user() ) {
			return false;
		}

		if ( ! empty( $this->get_selected_ids() ) ) {
			return false;
		}

		return 0 === $this->count_enabled_features();
	}

	/**
	 * Returns the name of the connected AI provider, when there is exactly one.
	 *
	 * @since x.x.x
	 *
	 * @return string The provider name, or an empty string.
	 */
	public function get_connected_provider_name(): string {
		$names = array();

		foreach ( get_ai_connectors() as $connector_id => $connector ) {
			if ( ! has_connector_authentication( (string) $connector_id ) ) {
				continue;
			}

			if ( empty( $connector['name'] ) || ! is_string( $connector['name'] ) ) {
				continue;
			}

			$names[] = $connector['name'];
		}

		return 1 === count( $names ) ? $names[0] : '';
	}

	/**
	 * Returns the URL of a Wapuu sprite.
	 *
	 * @since x.x.x
	 *
	 * @param string $pose The sprite's pose, such as `navigating`.
	 * @return string The sprite URL, or an empty string for an unknown pose.
	 */
	public static function get_sprite_url( string $pose ): string {
		if ( ! in_array( $pose, self::SPRITES, true ) ) {
			return '';
		}

		return WPAI_PLUGIN_URL . 'assets/images/wapuu/wapuu-' . $pose . '.svg';
	}

	/**
	 * Returns the URL of the Settings > AI page.
	 *
	 * @since x.x.x
	 *
	 * @return string The settings page URL.
	 */
	public static function get_settings_url(): string {
		return admin_url( 'options-general.php?page=' . self::SETTINGS_PAGE_SLUG );
	}

	/**
	 * Adds the guide's state to the Settings > AI script module data.
	 *
	 * @since x.x.x
	 *
	 * @param mixed $data The script module data.
	 * @return array<string, mixed> The data with an `onboarding` entry.
	 */
	public function add_page_data( $data ): array {
		$data = is_array( $data ) ? $data : array();

		$has_valid_credentials = has_valid_ai_credentials();
		$sprites               = array();

		foreach ( self::SPRITES as $pose ) {
			$sprites[ $pose ] = self::get_sprite_url( $pose );
		}

		$use_cases = array();

		foreach ( $this->use_cases->get_all() as $use_case ) {
			$use_case['unsupported'] = $has_valid_credentials
				? $this->get_unsupported_feature_labels( $use_case['features'] )
				: array();

			$use_cases[] = $use_case;
		}

		$data['onboarding'] = array(
			'useCases'     => $use_cases,
			'selected'     => $this->get_selected_ids(),
			'completed'    => $this->is_completed(),
			'hidden'       => $this->is_hidden_for_current_user(),
			'enabledCount' => $this->count_enabled_features(),
			'providerName' => has_ai_credentials() ? $this->get_connected_provider_name() : '',
			'sprites'      => $sprites,
		);

		return $data;
	}

	/**
	 * Returns the labels of the features the connected providers cannot serve.
	 *
	 * Only image generation and text to speech are checked. Every other tool
	 * in the guide needs text generation, which step one already requires.
	 *
	 * @since x.x.x
	 *
	 * @param list<string> $feature_ids The feature IDs to check.
	 * @return list<string> Labels of unsupported features.
	 */
	private function get_unsupported_feature_labels( array $feature_ids ): array {
		$labels = array();

		foreach ( $feature_ids as $feature_id ) {
			$feature = $this->registry->get_feature( $feature_id );
			if ( null === $feature ) {
				continue;
			}

			$capability = $feature->get_capability();

			if ( 'image_generation' === $capability && ! has_image_generation_support() ) {
				$labels[] = $feature->get_label();
			} elseif ( 'text_to_speech_conversion' === $capability && ! has_text_to_speech_support() ) {
				$labels[] = $feature->get_label();
			}
		}

		return $labels;
	}
}
