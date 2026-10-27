<?php
/**
 * Site Agent experiment.
 *
 * @package WordPress\AI\Experiments\Site_Agent
 */

declare( strict_types=1 );

namespace WordPress\AI\Experiments\Site_Agent;

use WordPress\AI\Abilities\Site_Agent\Create_Draft;
use WordPress\AI\Abilities\Site_Agent\Read_Site;
use WordPress\AI\Abilities\Site_Agent\Update_Post;
use WordPress\AI\Abilities\Site_Agent\Update_Setting;
use WordPress\AI\Abstracts\Abstract_Feature;
use WordPress\AI\Experiments\Experiment_Category;
use WordPress\AI\Experiments\Site_Agent\REST\Controller;
use WordPress\AI\Settings\Settings_Registration;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Adds a conversational Site Agent under Tools.
 *
 * The agent is a thin natural-language front end over the WordPress Abilities
 * API. Actions are registered abilities, so they keep their own schemas,
 * permission callbacks, validation, and execution hooks. The agent adds only
 * three things: a reviewed allowlist, a propose-then-approve boundary for
 * anything that is not read-only, and an audit trail.
 *
 * The experiment and its write gate are both off by default.
 *
 * @since x.x.x
 */
class Site_Agent extends Abstract_Feature {

	/**
	 * Maximum message length accepted by the composer and the REST route.
	 *
	 * @since x.x.x
	 *
	 * @var int
	 */
	public const MAX_MESSAGE_LENGTH = 8000;

	/**
	 * Setting controlling whether write proposals may be offered.
	 *
	 * @since x.x.x
	 *
	 * @var string
	 */
	public const FIELD_ALLOW_WRITES = 'allow_writes';

	/**
	 * Admin page instance, created during register().
	 *
	 * @since x.x.x
	 *
	 * @var \WordPress\AI\Experiments\Site_Agent\Admin_Page|null
	 */
	private ?Admin_Page $admin_page = null;

	/**
	 * {@inheritDoc}
	 */
	public static function get_id(): string {
		return 'site-agent';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function load_metadata(): array {
		return array(
			'label'       => __( 'Site Agent', 'ai' ),
			'description' => __( 'Adds a conversational Site Agent under Tools that answers questions about your site and proposes site changes. Every change is reviewed and approved before it runs, and every action decision is logged.', 'ai' ),
			'category'    => Experiment_Category::ADMIN,
			'capability'  => 'text_generation',
		);
	}

	/**
	 * Reports whether the experiment is enabled.
	 *
	 * Static because permission callbacks and REST routes need the answer
	 * without holding a feature instance.
	 *
	 * @since x.x.x
	 *
	 * @return bool True when the experiment is enabled.
	 */
	public static function is_active(): bool {
		$feature = new self();

		return $feature->is_enabled();
	}

	/**
	 * Returns the capability required to open and use the Site Agent.
	 *
	 * @since x.x.x
	 *
	 * @return string The capability name.
	 */
	public static function get_screen_capability(): string {
		return (string) apply_filters( 'wpai_site_agent_capability', 'manage_options' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function register(): void {
		$this->admin_page = new Admin_Page();
		$this->admin_page->register();

		( new Read_Site() )->init();
		( new Create_Draft() )->init();
		( new Update_Post() )->init();
		( new Update_Setting() )->init();

		( new Controller() )->init();
		Audit::init();
	}

	/**
	 * {@inheritDoc}
	 */
	public function register_settings(): void {
		register_setting(
			Settings_Registration::OPTION_GROUP,
			static::get_field_option_name( self::FIELD_ALLOW_WRITES ),
			array(
				'type'              => 'boolean',
				'default'           => false,
				'sanitize_callback' => 'rest_sanitize_boolean',
				'show_in_rest'      => true,
			)
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_settings_fields(): array {
		return array(
			array(
				'id'          => self::FIELD_ALLOW_WRITES,
				'label'       => __( 'Allow write proposals', 'ai' ),
				'type'        => 'boolean',
				'default'     => false,
				'description' => __( 'Let the assistant propose creating drafts, editing posts, and changing the site title or tagline. Nothing is written until you review and approve the exact values.', 'ai' ),
			),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_preloaded_options(): array {
		return array(
			static::get_field_option_name( self::FIELD_ALLOW_WRITES ),
		);
	}

	/**
	 * Reports whether write proposals are enabled.
	 *
	 * @since x.x.x
	 *
	 * @return bool True when write proposals are allowed.
	 */
	public static function writes_allowed(): bool {
		$allowed = (bool) get_option( static::get_field_option_name( self::FIELD_ALLOW_WRITES ), false );

		return (bool) apply_filters( 'wpai_site_agent_allow_writes', $allowed );
	}

	/**
	 * Builds the system instruction for a turn.
	 *
	 * @since x.x.x
	 *
	 * @param list<string> $tools The abilities declared for this turn.
	 * @return string The system instruction.
	 */
	public static function get_system_instruction( array $tools ): string {
		$builder = require __DIR__ . '/system-instruction.php';

		if ( ! is_callable( $builder ) ) {
			return '';
		}

		$instruction = (string) $builder(
			array(
				'tools'     => $tools,
				'site_name' => (string) get_bloginfo( 'name' ),
				'site_url'  => (string) home_url( '/' ),
			)
		);

		/**
		 * Filters the Site Agent system instruction.
		 *
		 * Tool results must never be merged into this value.
		 *
		 * @since x.x.x
		 *
		 * @param string       $instruction The system instruction.
		 * @param list<string> $tools       The ability names declared for this turn.
		 */
		return (string) apply_filters( 'wpai_site_agent_system_instruction', $instruction, $tools );
	}
}
