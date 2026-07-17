<?php
/**
 * Abilities Explorer Experiment
 *
 * Discover, inspect, test, and document all abilities
 * registered via the WordPress Abilities API.
 *
 * @package WordPress\AI\Experiments\Abilities_Explorer
 * @since 0.2.0
 */

declare( strict_types=1 );

namespace WordPress\AI\Experiments\Abilities_Explorer;

use WordPress\AI\Abilities\Gated\Gated_Abilities;
use WordPress\AI\Abilities\Show_In_Abilities;
use WordPress\AI\Abstracts\Abstract_Feature;
use WordPress\AI\Asset_Loader;
use WordPress\AI\Experiments\Experiment_Category;
use WordPress\AI\Settings\Settings_Registration;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Abilities Explorer Experiment Class.
 *
 * Provides a comprehensive interface for exploring
 * the WordPress Abilities API.
 *
 * @since 0.2.0
 */
class Abilities_Explorer extends Abstract_Feature {
	/**
	 * {@inheritDoc}
	 */
	public static function get_id(): string {
		return 'abilities-explorer';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function load_metadata(): array {
		return array(
			'label'       => __( 'Abilities Explorer', 'ai' ),
			'description' => __( 'Enable individual WordPress Abilities, then discover, inspect, test, and document all registered abilities.', 'ai' ),
			'category'    => Experiment_Category::ADMIN,
			'capability'  => 'none',
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function register(): void {
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );

		// @todo: evaluate standardization after triaging existing comments.
		$admin_page = new Admin_Page();
		$admin_page->init();

		// Register the WordPress Abilities gated behind this experiment's
		// individual toggles.
		$this->register_abilities();
	}

	/**
	 * {@inheritDoc}
	 *
	 * Registers the per-ability toggle options gated behind this experiment.
	 *
	 * @since x.x.x
	 */
	public function register_settings(): void {
		foreach ( Gated_Abilities::get_all() as $ability ) {
			register_setting(
				Settings_Registration::OPTION_GROUP,
				static::get_field_option_name( $ability->get_key() ),
				array(
					'type'              => 'boolean',
					'default'           => false,
					'sanitize_callback' => 'rest_sanitize_boolean',
					'show_in_rest'      => true,
				)
			);
		}
	}

	/**
	 * {@inheritDoc}
	 *
	 * Declares an individual toggle for each gated ability.
	 *
	 * @since x.x.x
	 */
	public function get_settings_fields(): array {
		$fields = array();

		foreach ( Gated_Abilities::get_all() as $ability ) {
			$fields[] = array(
				'id'          => $ability->get_key(),
				'label'       => $ability->get_label(),
				'description' => $ability->get_description(),
				'type'        => 'boolean',
				'default'     => false,
			);
		}

		return $fields;
	}

	/**
	 * Registers the WordPress Abilities gated behind this experiment.
	 *
	 * Only runs when the experiment is enabled (the Loader gates register()).
	 * Each ability additionally requires its own toggle to be enabled.
	 *
	 * @since x.x.x
	 */
	private function register_abilities(): void {
		$enabled        = array();
		$needs_exposure = false;

		foreach ( Gated_Abilities::get_all() as $ability ) {
			if ( ! $this->is_ability_enabled( $ability->get_key() ) ) {
				continue;
			}

			$enabled[] = $ability;

			if ( $ability->requires_core_object_exposure() ) {
				$needs_exposure = true;
			}
		}

		/*
		 * Show_In_Abilities is infrastructure, not a user-facing ability: expose
		 * the curated core objects once, before any dependent ability registers.
		 */
		if ( $needs_exposure ) {
			( new Show_In_Abilities() )->register();
		}

		foreach ( $enabled as $ability ) {
			$ability->register();
		}
	}

	/**
	 * Whether a given ability toggle is enabled.
	 *
	 * @since x.x.x
	 *
	 * @param string $key The ability toggle key.
	 * @return bool Whether the toggle is enabled.
	 */
	private function is_ability_enabled( string $key ): bool {
		return (bool) get_option( static::get_field_option_name( $key ), false );
	}

	/**
	 * Enqueues and localizes the admin script and styles.
	 *
	 * @since 0.2.0
	 *
	 * @param string $hook_suffix The current admin page hook suffix.
	 */
	public function enqueue_assets( string $hook_suffix ): void {
		// Load asset in Abilities Explorer page only.
		if ( 'tools_page_ai-abilities-explorer' !== $hook_suffix ) {
			return;
		}

		Asset_Loader::enqueue_script( 'abilities_explorer', 'experiments/abilities-explorer' );
		Asset_Loader::enqueue_style( 'abilities_explorer', 'experiments/abilities-explorer' );
		Asset_Loader::localize_script(
			'abilities_explorer',
			'AbilityExplorer',
			array(
				'enabled' => $this->is_enabled(),
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'ai_ability_explorer_invoke' ),
				'strings' => array(
					'invoking'      => esc_html__( 'Invoking ability...', 'ai' ),
					'success'       => esc_html__( 'Success!', 'ai' ),
					'error'         => esc_html__( 'Error', 'ai' ),
					'invalidJson'   => esc_html__( 'Invalid JSON input', 'ai' ),
					'confirmInvoke' => esc_html__( 'Are you sure you want to invoke this ability?', 'ai' ),
					'copySuccess'   => esc_html__( 'Copied!', 'ai' ),
					'copyError'     => esc_html__( 'Failed to copy', 'ai' ),
				),
			)
		);
	}
}
