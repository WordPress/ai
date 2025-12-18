<?php
/**
 * MCP experiment entry point.
 *
 * @package WordPress\AI\Experiments\MCP
 */

declare( strict_types=1 );

namespace WordPress\AI\Experiments\MCP;

use WordPress\AI\Abstracts\Abstract_Experiment;

use function __;
use function admin_url;
use function is_admin;

/**
 * Registers the MCP experiment.
 *
 * @since 0.1.0
 */
class MCP extends Abstract_Experiment {

	private Manager $manager;

	/**
	 * {@inheritDoc}
	 */
	protected function load_experiment_metadata(): array {
		return array(
			'id'          => 'mcp',
			'label'       => __( 'MCP', 'ai' ),
			'description' => __( 'Manage Model Context Protocol servers and client access.', 'ai' ),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function register(): void {
		$this->manager = new Manager();
		$this->manager->init();

		if ( is_admin() ) {
			$page = new Admin_Page( $this->manager );
			$page->init();
		}
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_entry_points(): array {
		return array(
			array(
				'label' => __( 'Dashboard', 'ai' ),
				'url'   => admin_url( 'admin.php?page=ai-mcp' ),
				'type'  => 'dashboard',
			),
		);
	}
}
