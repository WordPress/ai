<?php
/**
 * Checks comment drafts in the standard WordPress comment form.
 *
 * @package WordPress\AI
 */

declare( strict_types=1 );

namespace WordPress\AI\Experiments\Comment_Moderation;

use WP_Error;
use WP_REST_Request;
use WP_REST_Server;
use WordPress\AI\Asset_Loader;
use WordPress\AI\Background\Lock;
use WordPress\AiClient\Providers\Http\DTO\RequestOptions;

use function WordPress\AI\has_ai_credentials;

defined( 'ABSPATH' ) || exit;

/**
 * Adds the optional draft check to eligible comment forms.
 *
 * @since x.x.x
 */
final class Draft_Check {

	/**
	 * REST route used by the comment form.
	 *
	 * @since x.x.x
	 */
	private const ROUTE = '/comment-draft-check';

	/**
	 * Maximum draft size in bytes. Matches the limit for saved comments in core.
	 *
	 * @since x.x.x
	 */
	private const MAX_BYTES = 65525;

	/**
	 * Maximum provider calls per minute per visitor.
	 *
	 * @since x.x.x
	 */
	private const VISITOR_LIMIT = 20;

	/**
	 * Maximum provider calls per minute across the site.
	 *
	 * @since x.x.x
	 */
	private const SITE_LIMIT = 90;

	/**
	 * Owning experiment.
	 *
	 * @since x.x.x
	 *
	 * @var \WordPress\AI\Experiments\Comment_Moderation\Comment_Moderation
	 */
	private Comment_Moderation $experiment;

	/**
	 * Creates a draft checker for the Comment Moderation experiment.
	 *
	 * @since x.x.x
	 *
	 * @param \WordPress\AI\Experiments\Comment_Moderation\Comment_Moderation $experiment Owning experiment.
	 */
	public function __construct( Comment_Moderation $experiment ) {
		$this->experiment = $experiment;
	}

	/**
	 * Enqueues the script and marks an eligible form for enhancement.
	 *
	 * @since x.x.x
	 *
	 * @param int $post_id Post the comment form is for.
	 */
	public function render( int $post_id ): void {
		if ( ! $this->can_check( $post_id ) || ! has_ai_credentials() ) {
			return;
		}

		Asset_Loader::enqueue_script( 'comment_draft_check', 'experiments/comment-draft-check' );
		Asset_Loader::enqueue_style( 'comment_draft_check', 'experiments/comment-draft-check' );
		printf(
			'<span hidden data-wpai-comment-draft-check="%1$s" data-nonce="%2$s"></span>',
			esc_url( rest_url( 'ai/v1' . self::ROUTE ) ),
			is_user_logged_in() ? esc_attr( wp_create_nonce( 'wp_rest' ) ) : ''
		);
	}

	/**
	 * Registers the draft check endpoint separately from the moderator ability.
	 *
	 * @since x.x.x
	 */
	public function register_route(): void {
		register_rest_route(
			'ai/v1',
			self::ROUTE,
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'check' ),
				'permission_callback' => array( $this, 'can_request_check' ),
				'args'                => array(
					'post_id' => array(
						'type'     => 'integer',
						'minimum'  => 1,
						'required' => true,
					),
					'text'    => array(
						'type'     => 'string',
						'required' => true,
					),
				),
			)
		);
	}

	/**
	 * Validates the origin and the visitor's ability to comment on the post.
	 *
	 * @since x.x.x
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return bool Whether draft analysis is permitted.
	 */
	public function can_request_check( WP_REST_Request $request ): bool {
		$origin = $request->get_header( 'origin' );
		$home   = wp_parse_url( home_url() );
		$local  = is_array( $home ) && isset( $home['scheme'], $home['host'] )
			? $home['scheme'] . '://' . $home['host'] . ( isset( $home['port'] ) ? ':' . $home['port'] : '' )
			: '';

		return ( ! $origin || $local === $origin )
			&& $request->is_json_content_type()
			&& $this->can_check( (int) $request['post_id'] );
	}

	/**
	 * Checks whether a reader can use draft analysis for this post.
	 *
	 * @since x.x.x
	 *
	 * @param int $post_id Post ID.
	 * @return bool Whether the comment form is eligible.
	 */
	private function can_check( int $post_id ): bool {
		$post = get_post( $post_id );

		if ( ! $post || ! comments_open( $post ) || post_password_required( $post ) ) {
			return false;
		}

		if ( ! is_post_publicly_viewable( $post ) && ( 'private' !== get_post_status( $post ) || ! current_user_can( 'read_post', $post_id ) ) ) {
			return false;
		}

		return is_user_logged_in() || ( ! get_option( 'comment_registration' ) && $this->experiment->should_moderate_guests() );
	}

	/**
	 * Returns whether the exact draft should be blocked by the browser.
	 *
	 * @since x.x.x
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return array{blocked: bool}|\WP_Error Draft verdict or error.
	 */
	public function check( WP_REST_Request $request ) {
		$text = $request->get_json_params()['text'] ?? null;
		if ( ! is_string( $text ) || '' === trim( $text ) || strlen( $text ) > self::MAX_BYTES ) {
			return new WP_Error( 'invalid_comment_draft', __( 'The comment text is empty or too long.', 'ai' ), array( 'status' => 400 ) );
		}

		if ( ! has_ai_credentials() ) {
			return new WP_Error( 'comment_draft_check_unavailable', __( 'The draft check is unavailable.', 'ai' ), array( 'status' => 503 ) );
		}

		if ( ! $this->take_request_slot() ) {
			return new WP_Error( 'comment_draft_check_limit', __( 'The draft check is temporarily unavailable.', 'ai' ), array( 'status' => 429 ) );
		}

		// An unsent draft can contain text the visitor later removes or never posts.
		$limit_timeout = static function ( \WP_AI_Client_Prompt_Builder $builder ): \WP_AI_Client_Prompt_Builder {
			$options = new RequestOptions();
			$options->setTimeout( 4 );
			return $builder->using_request_options( $options );
		};

		add_filter( 'wpai_comment_analysis_prompt_builder', $limit_timeout );
		add_filter( 'wpai_request_log_include_content', '__return_false' );

		try {
			$result = $this->experiment->get_comment_analysis_ability()->analyze_comment_text( $text );
		} finally {
			remove_filter( 'wpai_comment_analysis_prompt_builder', $limit_timeout );
			remove_filter( 'wpai_request_log_include_content', '__return_false' );
		}

		if ( is_wp_error( $result ) ) {
			return new WP_Error( 'comment_draft_check_unavailable', __( 'The draft check is unavailable.', 'ai' ), array( 'status' => 503 ) );
		}

		return array(
			'blocked' => $result['toxicity_score'] >= $this->experiment->get_draft_toxicity_threshold()
				&& Comment_Moderation::SENTIMENT_NEGATIVE === $result['sentiment'],
		);
	}

	/**
	 * Limits calls per visitor and across the site. The lock protects counter updates only.
	 *
	 * @since x.x.x
	 *
	 * @return bool Whether a request slot is available.
	 */
	private function take_request_slot(): bool {
		/**
		 * Filters the maximum draft checks per minute across the site.
		 *
		 * @since x.x.x
		 *
		 * @param int $limit Site limit. Default 90. Zero pauses draft checks.
		 */
		$site_limit = max( 0, (int) apply_filters( 'wpai_comment_moderation_draft_site_limit', self::SITE_LIMIT ) );
		/**
		 * Filters the maximum draft checks per minute per user or guest address.
		 *
		 * @since x.x.x
		 *
		 * @param int $limit Visitor limit. Default 20. Guests behind a proxy may share it.
		 */
		$visitor_limit = max( 0, (int) apply_filters( 'wpai_comment_moderation_draft_visitor_limit', self::VISITOR_LIMIT ) );

		$lock = new Lock( 'comment_draft_check_budget', 5 );
		if ( ! $lock->acquire() ) {
			return false;
		}

		try {
			// Use the server-observed address for guests and do not store it in plaintext.
			// phpcs:ignore WordPressVIPMinimum.Variables -- This uncached REST callback uses only a validated network address for throttling.
			$address = (string) filter_var( $_SERVER['REMOTE_ADDR'] ?? '', FILTER_VALIDATE_IP );
			$visitor = is_user_logged_in() ? 'user:' . get_current_user_id() : 'guest:' . $address;
			$key     = hash_hmac( 'sha256', $visitor, wp_salt() );
			$minute  = intdiv( time(), MINUTE_IN_SECONDS );
			$budget  = get_transient( 'wpai_comment_draft_budget' );

			if ( ! is_array( $budget ) || ( $budget['minute'] ?? null ) !== $minute ) {
				$budget = array(
					'minute'   => $minute,
					'total'    => 0,
					'visitors' => array(),
				);
			}

			$visitor_count = $budget['visitors'][ $key ] ?? 0;
			if ( $budget['total'] >= $site_limit || $visitor_count >= $visitor_limit ) {
				return false;
			}

			++$budget['total'];
			$budget['visitors'][ $key ] = $visitor_count + 1;

			return set_transient( 'wpai_comment_draft_budget', $budget, MINUTE_IN_SECONDS );
		} finally {
			$lock->release();
		}
	}
}
