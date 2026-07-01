<?php
/**
 * Connector Approval experiment.
 *
 * @package WordPress\AI\Experiments\Connector_Approval
 */

declare( strict_types=1 );

namespace WordPress\AI\Experiments\Connector_Approval;

use WP_REST_Response;
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
		add_filter( 'rest_post_dispatch', array( $this, 'customize_rest_error' ), 10, 3 );

		if ( ! is_admin() ) {
			return;
		}

		$notice->register();
		$this->admin_page->register();
	}

	/**
	 * Filters the REST response to customize the error message when a request is blocked by Connector Approval.
	 *
	 * @since 1.1.0
	 *
	 * @param mixed            $response The REST response (WP_REST_Response, WP_HTTP_Response, or WP_Error).
	 * @param \WP_REST_Server   $server   The REST server.
	 * @param \WP_REST_Request  $request  The REST request.
	 * @return mixed The modified REST response.
	 */
	public function customize_rest_error( $response, $server, $request ) {
		if ( ! $response instanceof WP_REST_Response || ! $response->is_error() ) {
			return $response;
		}

		$data = $response->get_data();
		if ( ! is_array( $data ) || ! isset( $data['code'] ) || 'wpai_connector_not_approved' !== $data['code'] ) {
			return $response;
		}

		// Resolve the running ability ID from the request route path.
		// Route is typically /wp-abilities/v1/abilities/{id}/run
		$route = $request->get_route();
		$path  = trim( $route, '/' );
		$parts = explode( '/', $path );

		$abilities_index = array_search( 'abilities', $parts, true );
		$run_index       = array_search( 'run', $parts, true );
		if ( false !== $abilities_index && false !== $run_index && $run_index > $abilities_index + 1 ) {
			$ability_id = implode( '/', array_slice( $parts, $abilities_index + 1, $run_index - $abilities_index - 1 ) );
			$message    = $this->get_context_aware_error_message( $ability_id );
			if ( $message ) {
				$data['message'] = $message;
				$response->set_data( $data );
			}
		}

		return $response;
	}

	/**
	 * Gets a context-aware error message for the given ability.
	 *
	 * @since 1.1.0
	 *
	 * @param string $ability_id The ability ID.
	 * @return string The context-aware error message.
	 */
	private function get_context_aware_error_message( string $ability_id ): string {
		switch ( $ability_id ) {
			case 'ai/title-generation':
				$prefix = __( 'Title generation failed.', 'ai' );
				break;
			case 'ai/excerpt-generation':
				$prefix = __( 'Excerpt generation failed.', 'ai' );
				break;
			case 'ai/image-generation':
				$prefix = __( 'Image generation failed.', 'ai' );
				break;
			case 'ai/alt-text-generation':
				$prefix = __( 'Alt text generation failed.', 'ai' );
				break;
			case 'ai/meta-description':
				$prefix = __( 'Meta description generation failed.', 'ai' );
				break;
			case 'ai/editorial-notes':
				$prefix = __( 'Editorial notes generation failed.', 'ai' );
				break;
			case 'ai/editorial-updates':
				$prefix = __( 'Editorial updates generation failed.', 'ai' );
				break;
			case 'ai/content-resizing':
				$prefix = __( 'Content resizing failed.', 'ai' );
				break;
			case 'ai/content-classification':
				$prefix = __( 'Content classification failed.', 'ai' );
				break;
			case 'ai/summarization':
				$prefix = __( 'Summarization failed.', 'ai' );
				break;
			case 'ai/comment-analysis':
				$prefix = __( 'Comment analysis failed.', 'ai' );
				break;
			default:
				$prefix = __( 'Request failed.', 'ai' );
				break;
		}

		return sprintf(
			/* translators: %s: The specific feature failure message. */
			__( '%s The AI connector is currently pending authorization. Please approve the request under Tools > Connector Approvals.', 'ai' ),
			$prefix
		);
	}
}
