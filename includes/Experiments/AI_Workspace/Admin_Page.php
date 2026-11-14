<?php
/**
 * Admin page hosting the AI Workspace app shell.
 *
 * @package WordPress\AI\Experiments\AI_Workspace
 */

declare( strict_types=1 );

namespace WordPress\AI\Experiments\AI_Workspace;

use WordPress\AI\Asset_Loader;
use WordPress\AI\Experiments\AI_Workspace\REST\Conversation_Controller;
use WordPress\AI\Experiments\AI_Workspace\REST\Proposal_Controller;
use WordPress\AI\Experiments\AI_Workspace\REST\Turn_Controller;

use function WordPress\AI\has_valid_ai_credentials;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Registers and renders the AI Workspace admin screen under `Tools`.
 *
 * The screen is capability gated on every request: once when the menu entry is
 * built, once when WordPress dispatches the page, and once again in the render
 * callback so that a direct call can never emit the app shell or its localized
 * data to a user who lacks the capability.
 *
 * @since x.x.x
 */
final class Admin_Page {

	/**
	 * Menu slug used by the admin page.
	 *
	 * @since x.x.x
	 *
	 * @var string
	 */
	public const PAGE_SLUG = 'ai-workspace';

	/**
	 * Query argument carrying the post a handoff seeded the workspace with.
	 *
	 * @since x.x.x
	 *
	 * @var string
	 */
	public const POST_QUERY_ARG = 'wpai-post';

	/**
	 * Query argument carrying the prompt the command palette's Ask AI row opened the workspace with.
	 *
	 * @since x.x.x
	 *
	 * @var string
	 */
	public const PROMPT_QUERY_ARG = 'wpai-prompt';

	/**
	 * Longest seeded prompt carried to the client, in characters.
	 *
	 * @since x.x.x
	 *
	 * @var int
	 */
	public const MAX_SEED_PROMPT_LENGTH = 500;

	/**
	 * Admin body class that scopes the frame styles to this screen.
	 *
	 * @since x.x.x
	 *
	 * @var string
	 */
	public const FRAME_BODY_CLASS = 'ai-workspace-frame';

	/**
	 * Longest seeded post title carried to the client, in characters.
	 *
	 * @since x.x.x
	 *
	 * @var int
	 */
	private const MAX_SEED_TITLE_LENGTH = 200;

	/**
	 * Parent menu used to anchor this page.
	 *
	 * @since x.x.x
	 *
	 * @var string
	 */
	private const PARENT_SLUG = 'tools.php';

	/**
	 * Script and style handle, without the Asset_Loader prefix.
	 *
	 * @since x.x.x
	 *
	 * @var string
	 */
	private const ASSET_HANDLE = 'workspace';

	/**
	 * Built asset path, relative to the build directory and without extension.
	 *
	 * @since x.x.x
	 *
	 * @var string
	 */
	private const ASSET_PATH = 'experiments/ai-workspace';

	/**
	 * Registers the admin menu entry.
	 *
	 * @since x.x.x
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_submenu' ) );
	}

	/**
	 * Returns the absolute admin URL for this page.
	 *
	 * @since x.x.x
	 *
	 * @return string The admin URL for the workspace screen.
	 */
	public static function url(): string {
		return admin_url( self::PARENT_SLUG . '?page=' . self::PAGE_SLUG );
	}

	/**
	 * Adds the submenu entry under Tools.
	 *
	 * @since x.x.x
	 */
	public function add_submenu(): void {
		$page_hook = add_submenu_page(
			self::PARENT_SLUG,
			__( 'AI Workspace', 'ai' ),
			__( 'AI Workspace', 'ai' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render' )
		);

		if ( ! $page_hook ) {
			return;
		}

		add_action( "load-{$page_hook}", array( $this, 'on_load' ) );
	}

	/**
	 * Hooks the screen-specific behaviour once WordPress dispatches this page.
	 *
	 * @since x.x.x
	 */
	public function on_load(): void {
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_filter( 'admin_body_class', array( $this, 'add_frame_body_class' ) );
	}

	/**
	 * Adds the class that aligns this screen with the admin frame.
	 *
	 * The class only scopes the frame styles: the screen drops wp-admin's
	 * content padding and footer, as Settings > AI does. The admin menu stays.
	 *
	 * @since x.x.x
	 *
	 * @param string $classes Space-separated list of admin body classes.
	 * @return string The list with the frame class added.
	 */
	public function add_frame_body_class( string $classes ): string {
		return $classes . ' ' . self::FRAME_BODY_CLASS . ' ';
	}

	/**
	 * Enqueues the workspace bundle and passes its localized data.
	 *
	 * @since x.x.x
	 */
	public function enqueue_assets(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		/*
		 * The transcript renders tool results with DataViews, whose styles ship
		 * with the plugin because `wp-dataviews` is not a registered style on
		 * every supported WordPress version. The bundled copy is used only when
		 * WordPress does not register its own.
		 */
		$dataviews_css = WPAI_PLUGIN_DIR . 'build/admin/dataviews.css';

		if ( ! wp_styles()->query( 'wp-dataviews' ) && file_exists( $dataviews_css ) ) {
			wp_enqueue_style(
				'ai-dataviews',
				WPAI_PLUGIN_URL . 'build/admin/dataviews.css',
				array(),
				(string) filemtime( $dataviews_css )
			);
		}

		Asset_Loader::enqueue_script(
			self::ASSET_HANDLE,
			self::ASSET_PATH,
			array( 'include_core_abilities' => true )
		);

		Asset_Loader::enqueue_style( self::ASSET_HANDLE, self::ASSET_PATH );

		/*
		 * DataViews ships its own UI strings, which WordPress only inlines in
		 * block-editor contexts, so they are loaded explicitly here.
		 */
		wp_set_script_translations( 'wp-dataviews', 'default' );

		Asset_Loader::localize_script(
			self::ASSET_HANDLE,
			'Workspace',
			array(
				'rest'          => array(
					'nonce'  => wp_create_nonce( 'wp_rest' ),
					'root'   => esc_url_raw( rest_url() ),
					/*
					 * Only routes that exist are advertised, and every path is
					 * sourced from the constant its controller registers with,
					 * so the map cannot drift from the routes themselves.
					 */
					'routes' => array(
						'messages'      => Turn_Controller::MESSAGES_ROUTE,
						'cancel'        => Turn_Controller::CANCEL_ROUTE,
						'proposals'     => Proposal_Controller::PROPOSALS_ROUTE,
						'conversations' => Conversation_Controller::CONVERSATIONS_ROUTE,
					),
				),
				'availability'  => $this->get_availability(),
				'connectorsUrl' => admin_url( 'options-connectors.php' ),
				/*
				 * The propose abilities of every registered kind, including
				 * kinds another plugin adds through
				 * `wpai_workspace_proposal_kinds`. The transcript reads a
				 * proposal ID only from a successful call to one of these, so a
				 * kind missing here would be stored on approval's terms but
				 * never shown.
				 */
				'proposalAbilities' => ( new Proposal_Store() )->proposal_abilities(),
				/*
				 * The block editor handoff carries a post identity and nothing
				 * more. The workspace reads a body only through the
				 * permission-checked tool path, so there is one enforcement
				 * path and no trust is placed in a client-supplied body. The
				 * command palette's handoff carries a plain-text prompt for
				 * the composer.
				 */
				'seed'          => $this->get_seed(),
			)
		);
	}

	/**
	 * Outputs the root DOM node the React application mounts into.
	 *
	 * @since x.x.x
	 */
	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'ai' ) );
		}

		echo '<div class="wrap ai-workspace">';
		echo '<h1 class="screen-reader-text">' . esc_html__( 'AI Workspace', 'ai' ) . '</h1>';
		echo '<div id="ai-workspace-root"></div>';
		echo '</div>';
	}

	/**
	 * Resolves what a handoff opened the workspace with.
	 *
	 * A prompt from the command palette's Ask AI row takes precedence over a
	 * post from the block editor, so the composer holds what the person just
	 * typed.
	 *
	 * The prompt is carried as plain text: tags are stripped, whitespace is
	 * flattened to one line, and it is cut to {@see self::MAX_SEED_PROMPT_LENGTH}
	 * characters. It is placed in the composer and never sent on the person's
	 * behalf.
	 *
	 * For a post, only its identity is carried. The title travels with it so the
	 * screen can name what it was opened for, and because a title is author
	 * controlled it is flattened to a single clamped line before it leaves the
	 * server: a title cannot then smuggle a multi-line instruction block into
	 * the prompt the workspace prefills. The client treats it as untrusted
	 * content regardless.
	 *
	 * @since x.x.x
	 *
	 * @return array{postId: int, status: string, postType: string, title: string, prompt: string}|null
	 *         The seed, or null when no handoff parameter was supplied.
	 */
	private function get_seed(): ?array {
		$prompt = $this->get_seed_prompt();

		if ( '' !== $prompt ) {
			return array(
				'postId'   => 0,
				'status'   => 'prompt',
				'postType' => '',
				'title'    => '',
				'prompt'   => $prompt,
			);
		}

		return $this->get_post_seed();
	}

	/**
	 * Reads the prompt the command palette's Ask AI row carried.
	 *
	 * @since x.x.x
	 *
	 * @return string The plain-text, length-clamped prompt, or an empty string.
	 */
	private function get_seed_prompt(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Read-only navigation parameter on a capability-gated screen; nothing is written or sent, and the value is reduced to plain text below.
		$raw = $_GET[ self::PROMPT_QUERY_ARG ] ?? null;

		if ( ! is_string( $raw ) ) {
			return '';
		}

		$prompt = trim( (string) preg_replace( '/\s+/u', ' ', wp_strip_all_tags( wp_unslash( $raw ) ) ) );

		return mb_substr( $prompt, 0, self::MAX_SEED_PROMPT_LENGTH );
	}

	/**
	 * Resolves the post a block editor handoff pointed the workspace at.
	 *
	 * @since x.x.x
	 *
	 * @return array{postId: int, status: string, postType: string, title: string, prompt: string}|null
	 *         The seed, or null when no handoff parameter was supplied.
	 */
	private function get_post_seed(): ?array {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Read-only navigation parameter on a capability-gated screen; nothing is written, and the value is reduced to a positive integer below.
		$raw = $_GET[ self::POST_QUERY_ARG ] ?? null;

		if ( ! is_scalar( $raw ) ) {
			return null;
		}

		$post_id = absint( $raw );

		if ( 0 === $post_id ) {
			return null;
		}

		$seed = array(
			'postId'   => $post_id,
			'status'   => 'not-found',
			'postType' => '',
			'title'    => '',
			'prompt'   => '',
		);

		$post = get_post( $post_id );

		if ( ! $post instanceof \WP_Post ) {
			return $seed;
		}

		if ( ! current_user_can( 'read_post', $post_id ) ) {
			$seed['status'] = 'denied';

			return $seed;
		}

		$seed['status']   = 'ready';
		$seed['postType'] = $post->post_type;
		$seed['title']    = $this->normalize_seed_title( (string) get_the_title( $post ) );

		return $seed;
	}

	/**
	 * Flattens and clamps a seeded post title.
	 *
	 * @since x.x.x
	 *
	 * @param string $title The post title.
	 * @return string The single-line, length-clamped title.
	 */
	private function normalize_seed_title( string $title ): string {
		$flattened = trim( (string) preg_replace( '/\s+/u', ' ', $title ) );

		if ( mb_strlen( $flattened ) <= self::MAX_SEED_TITLE_LENGTH ) {
			return $flattened;
		}

		return mb_substr( $flattened, 0, self::MAX_SEED_TITLE_LENGTH ) . '…';
	}

	/**
	 * Describes whether the workspace can operate, and why not when it cannot.
	 *
	 * @since x.x.x
	 *
	 * @return array{status: string} The workspace availability status.
	 */
	private function get_availability(): array {
		if ( ! has_valid_ai_credentials() ) {
			return array( 'status' => 'no-credentials' );
		}

		if ( ! Function_Calling_Support::is_available() ) {
			return array( 'status' => 'no-function-calling' );
		}

		return array( 'status' => 'ready' );
	}
}
