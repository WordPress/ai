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
use WordPress\AI\Connector_Approval\Prompt_Guard;
use WordPress\AI\Connector_Approval\REST_Controller;
use WordPress\AI\Experiments\Experiment_Category;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Gates use of the WordPress AI Client behind per-plugin administrator approval.
 *
 * Proof-of-concept permission layer for the WordPress 7.0 shared Connectors API.
 * While enabled, calls to `wp_ai_client_prompt()->generate_*()` from an unknown
 * plugin are prevented and recorded for the administrator to review.
 *
 * Limitation: plugins that bypass the AI Client (for example, reading credential
 * options directly and making their own HTTP calls) aren't blocked — this is a
 * first version that leans on a core hook instead of intercepting options.
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
			'description' => __( 'Require explicit administrator approval before other plugins or themes can use the WordPress AI Client.', 'ai' ),
			'category'    => Experiment_Category::ADMIN,
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function register(): void {
		$store      = new Approvals_Store();
		$identifier = new Caller_Identifier();
		$guard      = new Prompt_Guard( $identifier, $store );
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
