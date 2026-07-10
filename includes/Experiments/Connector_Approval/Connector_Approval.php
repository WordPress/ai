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

use function WordPress\AI\get_ai_connectors;

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
			'description' => __( 'Require explicit administrator approval before plugins or themes can use AI connectors configured on this site. Enabling this feature will block all AI interactions, including those from the AI plugin, until an approved connector is available. Note this is an experimental, proof-of-concept feature and as such, issues may be encountered. Feedback welcome and desired to help shape the feature.', 'ai' ),
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
	 * @since x.x.x
	 *
	 * @param mixed            $response The REST response (WP_REST_Response, WP_HTTP_Response, or WP_Error).
	 * @param \WP_REST_Server   $server   The REST server.
	 * @param \WP_REST_Request  $request  The REST request.
	 * @return mixed The modified REST response.
	 */
	public function customize_rest_error( $response, $server, $request ) {
		// Fast exit if not an abilities run endpoint
		$route = $request->get_route();
		if ( ! str_contains( $route, '/abilities/' ) || ! str_ends_with( $route, '/run' ) ) {
			return $response;
		}

		if ( ! $response instanceof WP_REST_Response || ! $response->is_error() ) {
			return $response;
		}

		$data = $response->get_data();
		if ( ! is_array( $data ) || ! isset( $data['code'] ) ) {
			return $response;
		}

		$code = $data['code'];

		// We only care about connector approval errors or unsupported model errors
		if ( 'wpai_connector_not_approved' !== $code && 'unsupported_model' !== $code ) {
			return $response;
		}

		// Resolve the running ability ID from the request route path.
		$path  = trim( $route, '/' );
		$parts = explode( '/', $path );

		$abilities_index = array_search( 'abilities', $parts, true );
		$run_index       = array_search( 'run', $parts, true );
		if ( false === $abilities_index || false === $run_index || $run_index <= $abilities_index + 1 ) {
			return $response;
		}

		// If it's unsupported_model, check if there's actually an unapproved connector
		if ( 'unsupported_model' === $code ) {
			$store      = new Approvals_Store();
			$identifier = new Caller_Identifier();
			$caller     = $identifier->identify();

			if ( ! $caller ) {
				return $response;
			}

			$unapproved_connector_id = null;
			$connectors              = get_ai_connectors();
			
			foreach ( array_keys( $connectors ) as $connector_id ) {
				if ( ! $store->is_approved( $caller['basename'], $connector_id ) ) {
					$unapproved_connector_id = $connector_id;
					break;
				}
			}

			if ( null === $unapproved_connector_id ) {
				return $response;
			}

			// Change the code so the UI handles it as pending authorization
			$data['code'] = 'wpai_connector_not_approved';
			if ( ! isset( $data['data'] ) || ! is_array( $data['data'] ) ) {
				$data['data'] = array();
			}
			$data['data']['status']       = 403;
			$data['data']['connector_id'] = $unapproved_connector_id;
			$data['data']['caller']       = $caller;
		}

		$ability_id = implode( '/', array_slice( $parts, $abilities_index + 1, $run_index - $abilities_index - 1 ) );
		$message    = $this->get_context_aware_error_message( $ability_id );
		if ( $message ) {
			$data['message'] = $message;
			$response->set_data( $data );

			// Also update the HTTP status code of the response itself if we changed it
			if ( 'unsupported_model' === $code ) {
				$response->set_status( 403 );
			}
		}

		return $response;
	}

	/**
	 * Gets a context-aware error message for the given ability.
	 *
	 * @since x.x.x
	 *
	 * @param string $ability_id The ability ID.
	 * @return string The context-aware error message.
	 */
	private function get_context_aware_error_message( string $ability_id ): string {
		$ability = wp_get_ability( $ability_id );
		
		if ( $ability ) {
			$prefix = sprintf(
				/* translators: %s: The ability label. */
				__( '%s failed.', 'ai' ),
				$ability->get_label()
			);
		} else {
			$prefix = __( 'Request failed.', 'ai' );
		}

		return sprintf(
			/* translators: %s: The specific feature failure message. */
			__( '%s The AI connector is currently pending authorization. Please approve the request under Tools > Connector Approvals.', 'ai' ),
			$prefix
		);
	}
}
