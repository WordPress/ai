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
 * Lets an agent browser drive the block editor through WebMCP.
 *
 * On the post editor screens the experiment loads a bridge that registers
 * a small set of tools on `document.modelContext`, one `registerTool` call
 * per tool. Every tool acts on the page the person is looking at, through
 * the editor's own data stores, so the title changes, the block appears and
 * the post saves in front of them. Nothing is exposed that has no visible
 * effect on the current page; a site that wants server-side abilities in an
 * agent uses MCP.
 *
 * Other screens and plugins can add their own page tools through the
 * bridge's JavaScript registry (`wpai.webmcp.registerTool()`) and the
 * `wpai.webmcp.tools` filter. The bridge caps how many tools one page
 * registers, because agent browsers cap it too.
 *
 * @since x.x.x
 */
class WebMCP extends Abstract_Feature {

	/**
	 * Script handle suffix used with Asset_Loader (the loader prefixes it with `ai_`).
	 *
	 * @since x.x.x
	 */
	public const SCRIPT_HANDLE = 'webmcp';

	/**
	 * Default cap on tools registered on one page.
	 *
	 * Agent browsers impose a per-page budget; registering a few hundred tools
	 * disabled WebMCP for the document with no error in testing, while about
	 * thirty worked.
	 *
	 * @since x.x.x
	 */
	public const DEFAULT_MAX_TOOLS = 30;

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
			'description' => __( 'Lets an agent browser work in the block editor through WebMCP: set the title, insert and edit blocks, save and publish, with every change visible on the page as it happens.', 'ai' ),
			'category'    => Experiment_Category::EDITOR,
			'capability'  => 'none',
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function register(): void {
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * Admin screens the bridge loads on.
	 *
	 * @since x.x.x
	 *
	 * @return list<string> Hook suffixes.
	 */
	public function get_screens(): array {
		/**
		 * Filters the admin screens (hook suffixes) the WebMCP bridge loads on.
		 *
		 * The editor tools only work where the editor stores exist. A screen
		 * added here should register its own tools through the JavaScript
		 * registry, or the bridge will have nothing to register.
		 *
		 * @since x.x.x
		 *
		 * @param list<string> $screens Hook suffixes. Default the post editor screens.
		 */
		$screens = apply_filters( 'wpai_webmcp_screens', array( 'post.php', 'post-new.php' ) );

		return array_values( array_filter( $screens, 'is_string' ) );
	}

	/**
	 * Cap on tools registered per page.
	 *
	 * @since x.x.x
	 *
	 * @return int Cap, at least 1.
	 */
	public function get_max_tools(): int {
		/**
		 * Filters how many tools one page may register with the browser.
		 *
		 * @since x.x.x
		 *
		 * @param int $max_tools Cap. Default 30.
		 */
		$max_tools = (int) apply_filters( 'wpai_webmcp_max_tools', self::DEFAULT_MAX_TOOLS );

		return max( 1, $max_tools );
	}

	/**
	 * Loads the bridge on the editor screens.
	 *
	 * @since x.x.x
	 *
	 * @param string $hook_suffix Current admin page hook suffix.
	 */
	public function enqueue_assets( string $hook_suffix ): void {
		if ( ! in_array( $hook_suffix, $this->get_screens(), true ) ) {
			return;
		}

		Asset_Loader::add_global_data(
			'WebMCP',
			array(
				'screen'   => $hook_suffix,
				'maxTools' => $this->get_max_tools(),
			)
		);
		Asset_Loader::enqueue_script( self::SCRIPT_HANDLE, 'experiments/webmcp' );
	}
}
