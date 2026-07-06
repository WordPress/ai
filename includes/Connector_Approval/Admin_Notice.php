<?php
/**
 * Admin notice summarising pending connector approval requests.
 *
 * @package WordPress\AI\Connector_Approval
 */

declare( strict_types=1 );

namespace WordPress\AI\Connector_Approval;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

use function WordPress\AI\get_ai_connectors;

/**
 * Renders a dismissible admin notice when there are pending approval requests.
 *
 * @since 1.0.0
 */
final class Admin_Notice {
	/**
	 * User meta key tracking the last-dismissed pending signature.
	 *
	 * @since 1.0.0
	 *
	 * @var string
	 */
	private const DISMISS_META = 'wpai_connector_approval_notice_dismissed';

	/**
	 * Option key tracking checked connectors for the AI plugin.
	 *
	 * @since x.x.x
	 *
	 * @var string
	 */
	private const CHECKED_CONNECTORS_OPTION = 'wpai_connector_approval_checked_connectors';

	/**
	 * Query argument used to trigger dismissal.
	 *
	 * @since 1.0.0
	 *
	 * @var string
	 */
	public const DISMISS_QUERY_ARG = 'wpai_ca_notice_dismiss';

	/**
	 * Nonce action for dismissal.
	 *
	 * @since 1.0.0
	 *
	 * @var string
	 */
	public const DISMISS_NONCE = 'wpai_ca_notice';

	/**
	 * Approvals store.
	 *
	 * @since 1.0.0
	 *
	 * @var \WordPress\AI\Connector_Approval\Approvals_Store
	 */
	private Approvals_Store $store;

	/**
	 * Admin page URL builder.
	 *
	 * @since 1.0.0
	 *
	 * @var callable
	 */
	private $page_url_resolver;

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 *
	 * @param \WordPress\AI\Connector_Approval\Approvals_Store $store Approvals store.
	 * @param callable $page_url_resolver Callable returning the admin page URL.
	 */
	public function __construct( Approvals_Store $store, callable $page_url_resolver ) {
		$this->store             = $store;
		$this->page_url_resolver = $page_url_resolver;
	}

	/**
	 * Hooks up notice rendering and dismissal handling.
	 *
	 * @since 1.0.0
	 */
	public function register(): void {
		add_action( 'admin_init', array( $this, 'maybe_handle_dismiss' ) );
		add_action( 'admin_init', array( $this, 'flag_any_active_connectors' ) );
		add_action( 'admin_notices', array( $this, 'render' ) );
	}

	/**
	 * Checks for any active/newly configured connectors and registers a pending request
	 * for the AI plugin itself if the connector is not already approved for it.
	 *
	 * This ensures administrators are immediately flagged that the AI plugin needs
	 * access to the connected providers when Connector Approvals is enabled.
	 *
	 * @since x.x.x
	 */
	public function flag_any_active_connectors(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$connectors = get_ai_connectors();
		if ( empty( $connectors ) ) {
			return;
		}

		$ai_basename = plugin_basename( WPAI_PLUGIN_FILE );
		$name        = __( 'AI', 'ai' );

		$checked = get_option( self::CHECKED_CONNECTORS_OPTION, array() );
		if ( ! is_array( $checked ) ) {
			$checked = array();
		}

		$updated = false;

		foreach ( array_keys( $connectors ) as $connector_id ) {
			// Skip connectors we have already provided a notice to anytime before.
			if ( isset( $checked[ $connector_id ] ) ) {
				continue;
			}

			// If not approved, record as pending.
			if ( ! $this->store->is_approved( $ai_basename, $connector_id ) ) {
				$this->store->record_pending(
					array(
						'type'     => 'plugin',
						'basename' => $ai_basename,
						'name'     => $name,
					),
					$connector_id
				);
			}

			$checked[ $connector_id ] = true;
			$updated                  = true;
		}

		if ( $updated ) {
			update_option( self::CHECKED_CONNECTORS_OPTION, $checked, true );
		}

	}

	/**
	 * Handles the dismissal redirect when the user clicks the dismiss link.
	 *
	 * @since 1.0.0
	 */
	public function maybe_handle_dismiss(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nonce is verified below.
		if ( ! isset( $_GET[ self::DISMISS_QUERY_ARG ] ) ) {
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotValidated
		$nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, self::DISMISS_NONCE ) ) {
			return;
		}

		update_user_meta( get_current_user_id(), self::DISMISS_META, $this->signature() );

		$redirect = remove_query_arg( array( self::DISMISS_QUERY_ARG, '_wpnonce' ) );
		wp_safe_redirect( $redirect );
		exit;
	}

	/**
	 * Returns whether the pending requests notice has been dismissed by the user.
	 *
	 * @since x.x.x
	 *
	 * @param array<string, array<string, mixed>> $pending Array of pending approval requests.
	 * @return bool
	 */
	public static function is_dismissed( array $pending ): bool {
		if ( empty( $pending ) ) {
			return true;
		}

		$keys = array_keys( $pending );
		sort( $keys );
		$signature = md5( implode( '|', $keys ) );
		$dismissed = (string) get_user_meta( get_current_user_id(), self::DISMISS_META, true );

		return $signature === $dismissed;
	}

	/**
	 * Renders the notice.
	 *
	 * @since 1.0.0
	 */
	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$screen = get_current_screen();
		if ( $screen && in_array( $screen->id, array( 'tools_page_ai-connector-approval', 'settings_page_ai-wp-admin' ), true ) ) {
			return;
		}

		$pending = $this->store->get_pending();
		if ( self::is_dismissed( $pending ) ) {
			return;
		}

		$count       = count( $pending );
		$review_url  = (string) call_user_func( $this->page_url_resolver );
		$dismiss_url = wp_nonce_url(
			add_query_arg( self::DISMISS_QUERY_ARG, '1' ),
			self::DISMISS_NONCE
		);

		$class = 'notice notice-warning ai-connector-approval-notice';
		if ( $screen && 'tools_page_ai-request-logs' === $screen->id ) {
			$class .= ' inline';
		}

		printf(
			'<div class="%s"><p>%s <a href="%s">%s</a> &middot; <a href="%s">%s</a></p></div>',
			esc_attr( $class ),
			esc_html(
				sprintf(
					/* translators: %d: number of pending approval requests. */
					_n(
						'%d plugin or theme is requesting access to an AI connector.',
						'%d plugins or themes are requesting access to AI connectors.',
						$count,
						'ai'
					),
					$count
				)
			),
			esc_url( $review_url ),
			esc_html__( 'Review requests', 'ai' ),
			esc_url( $dismiss_url ),
			esc_html__( 'Dismiss', 'ai' )
		);
	}

	/**
	 * Returns a stable signature of the current pending set for dismissal tracking.
	 *
	 * The signature changes whenever new requests are added, so a dismissal only
	 * silences the notice until the queue meaningfully changes.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	private function signature(): string {
		$pending = $this->store->get_pending();
		$keys    = array_keys( $pending );
		sort( $keys );

		return md5( implode( '|', $keys ) );
	}
}
