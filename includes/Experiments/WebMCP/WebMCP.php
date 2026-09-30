<?php
/**
 * WebMCP experiment.
 *
 * @package WordPress\AI
 */

declare( strict_types=1 );

namespace WordPress\AI\Experiments\WebMCP;

use WordPress\AI\Abstracts\Abstract_Feature;
use WordPress\AI\Asset_Loader;
use WordPress\AI\Experiments\Experiment_Category;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers a curated set of WordPress abilities as WebMCP tools on the page,
 * so an agent browser can call them through `document.modelContext`.
 *
 * The Abilities API stays the single registry. This experiment only decides
 * which abilities a page exposes, hands them to the browser one `registerTool`
 * call at a time, and executes them through a REST route that runs the
 * ability's own permission and input checks on the server.
 *
 * Two page contexts exist because agent browsers cap the number of tools a
 * page may register, and a logged-in editor and a visitor need different
 * tools: `admin` for wp-admin screens and `visitor` for the front end.
 *
 * @since x.x.x
 */
class WebMCP extends Abstract_Feature {

	/**
	 * Page context for wp-admin screens.
	 *
	 * @since x.x.x
	 */
	public const CONTEXT_ADMIN = 'admin';

	/**
	 * Page context for the front end.
	 *
	 * @since x.x.x
	 */
	public const CONTEXT_VISITOR = 'visitor';

	/**
	 * Script handle suffix used with Asset_Loader (the loader prefixes it with `ai-`).
	 *
	 * @since x.x.x
	 */
	public const SCRIPT_HANDLE = 'webmcp';

	/**
	 * Exposure rules for the current site.
	 *
	 * @since x.x.x
	 * @var \WordPress\AI\Experiments\WebMCP\Tool_Curator|null
	 */
	private ?Tool_Curator $curator = null;

	/**
	 * REST routes the bridge script talks to.
	 *
	 * @since x.x.x
	 * @var \WordPress\AI\Experiments\WebMCP\REST_Controller|null
	 */
	private ?REST_Controller $rest = null;

	/**
	 * {@inheritDoc}
	 */
	public static function get_id(): string {
		return 'webmcp';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function load_metadata(): array {
		return array(
			'label'       => __( 'WebMCP', 'ai' ),
			'description' => __( 'Exposes a curated set of WordPress abilities to agent browsers as WebMCP tools on the page, through document.modelContext. Nothing is exposed until an ability opts in, a filter allows it, or it is listed in the settings below.', 'ai' ),
			'category'    => Experiment_Category::ADMIN,
			'capability'  => 'none',
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_settings_fields(): array {
		return array(
			array(
				'id'      => 'admin_abilities',
				'label'   => __( 'Abilities exposed in wp-admin (comma-separated ability names, for example core/get-site-info)', 'ai' ),
				'type'    => 'string',
				'default' => '',
			),
			array(
				'id'      => 'visitor_abilities',
				'label'   => __( 'Abilities exposed to visitors on the front end (comma-separated ability names; leave empty to load nothing on the front end)', 'ai' ),
				'type'    => 'string',
				'default' => '',
			),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function register(): void {
		$this->curator = new Tool_Curator( self::get_id() );
		$this->rest    = new REST_Controller( $this->curator );

		add_action( 'rest_api_init', array( $this->rest, 'register_routes' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_front_end_assets' ) );
	}

	/**
	 * Returns the curator, creating it when the experiment was not registered through the loader.
	 *
	 * @since x.x.x
	 *
	 * @return \WordPress\AI\Experiments\WebMCP\Tool_Curator Curator.
	 */
	public function get_curator(): Tool_Curator {
		if ( null === $this->curator ) {
			$this->curator = new Tool_Curator( self::get_id() );
		}

		return $this->curator;
	}

	/**
	 * Loads the bridge on wp-admin screens for logged-in users.
	 *
	 * Every admin screen gets the script: the tool set is the same across
	 * wp-admin and the browser only registers tools when it implements WebMCP.
	 *
	 * @since x.x.x
	 *
	 * @param string $hook_suffix Current admin page hook suffix.
	 */
	public function enqueue_admin_assets( string $hook_suffix ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Signature of the admin_enqueue_scripts hook.
		if ( ! is_user_logged_in() ) {
			return;
		}

		if ( ! $this->get_curator()->has_exposed_abilities( self::CONTEXT_ADMIN ) ) {
			return;
		}

		$this->enqueue_bridge( self::CONTEXT_ADMIN );
	}

	/**
	 * Loads the bridge on the front end, only when something is exposed to visitors.
	 *
	 * @since x.x.x
	 */
	public function enqueue_front_end_assets(): void {
		if ( ! $this->get_curator()->has_exposed_abilities( self::CONTEXT_VISITOR ) ) {
			return;
		}

		$this->enqueue_bridge( self::CONTEXT_VISITOR );
	}

	/**
	 * Enqueues the bridge script with the data it needs to talk to the REST routes.
	 *
	 * @since x.x.x
	 *
	 * @param string $context Page context, one of the CONTEXT_* constants.
	 */
	private function enqueue_bridge( string $context ): void {
		Asset_Loader::add_global_data(
			'WebMCP',
			array(
				'context'    => $context,
				'toolsUrl'   => rest_url( REST_Controller::NAMESPACE . '/webmcp/tools' ),
				'executeUrl' => rest_url( REST_Controller::NAMESPACE . '/webmcp/execute' ),
				'nonceUrl'   => rest_url( REST_Controller::NAMESPACE . '/webmcp/nonce' ),
				'restNonce'  => wp_create_nonce( 'wp_rest' ),
				'nonce'      => wp_create_nonce( REST_Controller::NONCE_ACTION ),
			)
		);
		Asset_Loader::enqueue_script( self::SCRIPT_HANDLE, 'experiments/webmcp' );
	}
}
