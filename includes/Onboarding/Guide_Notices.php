<?php
/**
 * Get started guide messages shown outside Settings > AI.
 *
 * @package WordPress\AI\Onboarding
 *
 * @since x.x.x
 */

declare( strict_types=1 );

namespace WordPress\AI\Onboarding;

use WP_Admin_Bar;

use function WordPress\AI\has_valid_ai_credentials;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Renders Wapuu's welcome on the Plugins screen and the way back from Connectors.
 *
 * Both messages are shown to administrators only, are never modal, and stop
 * once use cases are chosen or the administrator hides the guide.
 *
 * @since x.x.x
 */
final class Guide_Notices {

	/**
	 * ID of the toolbar item shown on the Connectors screen.
	 *
	 * @since x.x.x
	 *
	 * @var string
	 */
	public const TOOLBAR_NODE_ID = 'wpai-guide';

	/**
	 * Query argument used to dismiss the welcome notice.
	 *
	 * @since x.x.x
	 *
	 * @var string
	 */
	private const DISMISS_QUERY_ARG = 'wpai_guide_dismiss';

	/**
	 * Nonce action for dismissal.
	 *
	 * @since x.x.x
	 *
	 * @var string
	 */
	private const DISMISS_NONCE = 'wpai_guide_dismiss';

	/**
	 * Style handle for the guide messages.
	 *
	 * @since x.x.x
	 *
	 * @var string
	 */
	private const STYLE_HANDLE = 'wpai-onboarding-guide';

	/**
	 * The guide state.
	 *
	 * @since x.x.x
	 *
	 * @var \WordPress\AI\Onboarding\Onboarding
	 */
	private Onboarding $onboarding;

	/**
	 * Constructor.
	 *
	 * @since x.x.x
	 *
	 * @param \WordPress\AI\Onboarding\Onboarding $onboarding The guide state.
	 */
	public function __construct( Onboarding $onboarding ) {
		$this->onboarding = $onboarding;
	}

	/**
	 * Hooks up rendering and dismissal handling.
	 *
	 * @since x.x.x
	 */
	public function register(): void {
		add_action( 'admin_init', array( $this, 'maybe_handle_dismiss' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_styles' ) );
		add_action( 'admin_notices', array( $this, 'render_welcome' ) );
		add_action( 'admin_bar_menu', array( $this, 'add_connectors_toolbar_item' ), 100 );
	}

	/**
	 * Stores the dismissal when the user follows a dismiss link.
	 *
	 * @since x.x.x
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

		update_user_meta( get_current_user_id(), Onboarding::META_WELCOME_DISMISSED, '1' );

		wp_safe_redirect( remove_query_arg( array( self::DISMISS_QUERY_ARG, '_wpnonce' ) ) );
		exit;
	}

	/**
	 * Enqueues the styles on the screens that show a guide message.
	 *
	 * @since x.x.x
	 */
	public function enqueue_styles(): void {
		if ( ! $this->should_render_welcome() && ! $this->should_add_connectors_toolbar_item() ) {
			return;
		}

		wp_register_style( self::STYLE_HANDLE, false, array(), WPAI_VERSION );
		wp_enqueue_style( self::STYLE_HANDLE );
		wp_add_inline_style( self::STYLE_HANDLE, $this->get_styles() );
	}

	/**
	 * Renders the welcome notice on the Plugins screen after activation.
	 *
	 * @since x.x.x
	 */
	public function render_welcome(): void {
		if ( ! $this->should_render_welcome() ) {
			return;
		}

		printf(
			'<div class="notice notice-info wpai-guide wpai-guide--notice">%s<div class="wpai-guide__body"><p><strong>%s</strong> %s</p><p class="wpai-guide__actions"><a class="button button-primary" href="%s">%s</a> <a class="button-link" href="%s">%s</a></p></div></div>',
			$this->get_sprite_html( 'experimenting' ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in get_sprite_html().
			esc_html__( 'AI is installed.', 'ai' ),
			esc_html__( "I'm Wapuu, and I'll help you set it up. It takes three steps. Most tools are Experiments that may change between releases, so a staging site is the best place to try them first.", 'ai' ),
			esc_url( Onboarding::get_settings_url() ),
			esc_html__( 'Get started', 'ai' ),
			esc_url( $this->get_dismiss_url() ),
			esc_html__( 'Dismiss', 'ai' )
		);
	}

	/**
	 * Adds the way back to Settings > AI on the Connectors screen.
	 *
	 * Before a provider is connected, the Connectors screen shows its own
	 * message about the AI plugin with a link to Settings > AI. That message
	 * goes away once a provider is connected, so this item takes over and
	 * points to step two.
	 *
	 * The Connectors screen hides admin notices, and anything pinned over the
	 * screen could cover its buttons, so the pointer lives in the toolbar. It
	 * is added on page load, so it appears the next time the screen is opened
	 * after a provider is saved.
	 *
	 * @since x.x.x
	 *
	 * @param \WP_Admin_Bar $admin_bar The toolbar instance.
	 */
	public function add_connectors_toolbar_item( WP_Admin_Bar $admin_bar ): void {
		if ( ! $this->should_add_connectors_toolbar_item() ) {
			return;
		}

		$provider = $this->onboarding->get_connected_provider_name();
		$label    = '' !== $provider
			? sprintf(
				/* translators: %s: AI provider name, such as OpenAI. */
				__( 'Connected to %s. Next: choose what AI helps with', 'ai' ),
				$provider
			)
			: __( 'Connected. Next: choose what AI helps with', 'ai' );

		$admin_bar->add_node(
			array(
				'id'    => self::TOOLBAR_NODE_ID,
				'title' => $this->get_sprite_html( 'working', 24 ) . '<span class="ab-label">' . esc_html( $label ) . '</span>',
				'href'  => Onboarding::get_settings_url(),
				'meta'  => array(
					'class' => 'wpai-guide-toolbar',
				),
			)
		);
	}

	/**
	 * Whether the welcome notice should show on the current screen.
	 *
	 * @since x.x.x
	 *
	 * @return bool True when the welcome notice should render.
	 */
	private function should_render_welcome(): bool {
		$screen = get_current_screen();
		if ( ! $screen || 'plugins' !== $screen->id ) {
			return false;
		}

		if ( ! get_option( Onboarding::OPTION_WELCOME, false ) ) {
			return false;
		}

		if ( get_user_meta( get_current_user_id(), Onboarding::META_WELCOME_DISMISSED, true ) ) {
			return false;
		}

		return $this->onboarding->is_setup_pending_for_current_user();
	}

	/**
	 * Whether the Connectors toolbar item should show on the current screen.
	 *
	 * @since x.x.x
	 *
	 * @return bool True when the toolbar item should be added.
	 */
	private function should_add_connectors_toolbar_item(): bool {
		if ( ! function_exists( 'get_current_screen' ) ) {
			return false;
		}

		$screen = get_current_screen();
		if ( ! $screen || 'options-connectors' !== $screen->id ) {
			return false;
		}

		if ( ! $this->onboarding->is_setup_pending_for_current_user() ) {
			return false;
		}

		return has_valid_ai_credentials();
	}

	/**
	 * Returns the dismiss URL for the welcome notice.
	 *
	 * @since x.x.x
	 *
	 * @return string The nonce-protected dismiss URL.
	 */
	private function get_dismiss_url(): string {
		return wp_nonce_url(
			add_query_arg( self::DISMISS_QUERY_ARG, '1' ),
			self::DISMISS_NONCE
		);
	}

	/**
	 * Returns the image markup for a Wapuu sprite.
	 *
	 * The sprite is decorative, so it carries an empty alt attribute.
	 *
	 * @since x.x.x
	 *
	 * @param string $pose The sprite's pose.
	 * @param int    $size The rendered size in pixels.
	 * @return string The escaped image markup.
	 */
	private function get_sprite_html( string $pose, int $size = 64 ): string {
		return sprintf(
			'<img class="wpai-guide__wapuu" src="%1$s" alt="" width="%2$d" height="%2$d" />',
			esc_url( Onboarding::get_sprite_url( $pose ) ),
			$size
		);
	}

	/**
	 * Returns the styles for the guide messages.
	 *
	 * @since x.x.x
	 *
	 * @return string The CSS.
	 */
	private function get_styles(): string {
		return '
			.wpai-guide { display: flex; align-items: center; gap: 12px; }
			.wpai-guide__wapuu { flex: none; inline-size: 64px; block-size: 64px; }
			.wpai-guide__body { min-inline-size: 0; }
			.wpai-guide__body p { margin-block: 6px; }
			.wpai-guide__actions { display: flex; flex-wrap: wrap; align-items: center; gap: 12px; }
			.wpai-guide--notice { padding-block: 6px; }
			#wpadminbar .wpai-guide-toolbar > .ab-item {
				display: flex;
				align-items: center;
				gap: 6px;
				background: var(--wp-admin-theme-color, #3858e9);
				color: #fff;
			}
			#wpadminbar .wpai-guide-toolbar > .ab-item:hover,
			#wpadminbar .wpai-guide-toolbar > .ab-item:focus {
				background: var(--wp-admin-theme-color-darker-10, #2145e6);
				color: #fff;
			}
			#wpadminbar .wpai-guide-toolbar .wpai-guide__wapuu { inline-size: 24px; block-size: 24px; }
			#wpadminbar .wpai-guide-toolbar .ab-label { color: inherit; }
		';
	}
}
