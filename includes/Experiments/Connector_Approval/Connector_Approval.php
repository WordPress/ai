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
use WordPress\AI\Connector_Approval\Connector_Key_Index;
use WordPress\AI\Connector_Approval\Http_Guard;
use WordPress\AI\Connector_Approval\REST_Controller;
use WordPress\AI\Experiments\Experiment_Category;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Gates use of configured AI connectors behind per-plugin administrator approval.
 *
 * Proof-of-concept permission layer for the WordPress 7.0 shared Connectors API.
 * While enabled, outbound HTTP requests that carry a configured AI connector
 * credential are matched to the originating plugin/theme via the call stack.
 * If that caller hasn't been approved for the connector, the request is
 * blocked and recorded for the administrator to review.
 *
 * Enforcement is done at the HTTP layer rather than the AI Client prompt
 * layer so that:
 *
 * - The exact connector carrying the request is known (no candidate-set
 *   guessing from builder internals).
 * - Plugins that read a credential option directly and make their own HTTP
 *   calls are also covered, not just plugins using `wp_ai_client_prompt()`.
 *
 * @since 1.0.0
 */
class Connector_Approval extends Abstract_Feature {
	/**
	 * Admin page instance, created during register().
	 *
	 * @since 1.0.0
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
			'description' => __( 'Require explicit administrator approval before plugins or themes can use AI connectors configured on this site. Note this is an experimental, proof-of-concept feature and as such, issues may be encountered. Feedback welcome and desired to help shape the feature.', 'ai' ),
			'category'    => Experiment_Category::ADMIN,
			'capability'  => 'none',
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function register(): void {
		$store      = new Approvals_Store();
		$identifier = new Caller_Identifier();
		$key_index  = new Connector_Key_Index();
		$guard      = new Http_Guard( $identifier, $store, $key_index );
		$rest       = new REST_Controller( $store );
		$notice     = new Admin_Notice( $store, array( Admin_Page::class, 'url' ) );

		$this->admin_page = new Admin_Page();

		$guard->register();

		add_action( 'rest_api_init', array( $rest, 'register_routes' ) );

		if ( ! is_admin() ) {
			return;
		}

		$notice->register();
		$this->admin_page->register();
	}
}
