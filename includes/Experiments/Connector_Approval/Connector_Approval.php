<?php
/**
 * Connector Approval experiment.
 *
 * @package WordPress\AI\Experiments\Connector_Approval
 */

declare( strict_types=1 );

namespace WordPress\AI\Experiments\Connector_Approval;

use WordPress\AI\Abstracts\Abstract_Feature;
use WordPress\AI\Connector_Approval\Admin_Notice;
use WordPress\AI\Connector_Approval\Approvals_Store;
use WordPress\AI\Connector_Approval\Caller_Identifier;
use WordPress\AI\Connector_Approval\Option_Guard;
use WordPress\AI\Connector_Approval\REST_Controller;
use WordPress\AI\Experiments\Experiment_Category;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Gates reads of connector credentials behind per-plugin administrator approval.
 *
 * Proof-of-concept permission layer for the WordPress 7.0 shared Connectors API.
 * While enabled, each connector's credential option is filtered so only the
 * owning connector plugin and explicitly approved callers receive the real
 * value; other callers receive an empty string and are recorded for review.
 *
 * @since x.x.x
 */
class Connector_Approval extends Abstract_Feature {
	/**
	 * Admin page instance, created during register().
	 *
	 * @since x.x.x
	 *
	 * @var \WordPress\AI\Experiments\Connector_Approval\Admin_Page|null
	 */
	private ?Admin_Page $admin_page = null;

	/**
	 * {@inheritDoc}
	 */
	public static function get_id(): string {
		return 'connector-approval';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function load_metadata(): array {
		return array(
			'label'       => __( 'Connector Approval', 'ai' ),
			'description' => __( 'Require explicit administrator approval before other plugins or themes can use AI connectors that you have configured.', 'ai' ),
			'category'    => Experiment_Category::ADMIN,
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function register(): void {
		$store      = new Approvals_Store();
		$identifier = new Caller_Identifier();
		$guard      = new Option_Guard( $identifier, $store );
		$rest       = new REST_Controller( $store );
		$notice     = new Admin_Notice( $store, array( Admin_Page::class, 'url' ) );

		$this->admin_page = new Admin_Page();

		// Option filters need to be registered before any AI request runs.
		add_action( 'init', array( $guard, 'register' ), 20 );

		add_action( 'rest_api_init', array( $rest, 'register_routes' ) );

		if ( ! is_admin() ) {
			return;
		}

		$notice->register();
		$this->admin_page->register();
	}
}
